<?php
/** Gerätegebundene Expo-Push-Anmeldung und dauerhafte Versand-/Receipt-Warteschlange. */
if (!defined('ABSPATH')) { exit; }

class EVG_App_Push {
    const PROJECT_ID = '5c0b701f-b5c9-4c10-beba-1cba871cd978';
    const HOOK = 'evg_app_push_worker';

    public function __construct() {
        add_action('init', [__CLASS__, 'ensure_tables']);
        add_action('rest_api_init', [$this, 'routes']);
        add_action(self::HOOK, [__CLASS__, 'work']);
    }
    public static function ensure_tables($force = false) {
        if (!$force && (int)get_option('evg_app_push_schema', 0) === 1) return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}evg_app_devices (
            installation_id varchar(64) NOT NULL,
            user_id bigint unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            token text NOT NULL,
            secret_hash varchar(255) NOT NULL,
            platform varchar(16) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (installation_id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$wpdb->prefix}evg_app_push_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            event_key char(64) NOT NULL,
            payload longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts int unsigned NOT NULL DEFAULT 0,
            ticket_id varchar(191) DEFAULT NULL,
            available_at datetime NOT NULL,
            last_error varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY due (status,available_at)
        ) {$charset};");
        if (!$force) update_option('evg_app_push_schema', 1, false);
    }
    public function routes() {
        register_rest_route('easyverein-go/v1', '/push-devices', [
            'methods' => 'POST', 'permission_callback' => function() { return is_user_logged_in(); },
            'callback' => [$this, 'register_device'],
        ]);
        register_rest_route('easyverein-go/v1', '/push-devices/(?P<installation_id>[a-f0-9-]{36})', [
            'methods' => 'DELETE', 'permission_callback' => '__return_true', 'callback' => [$this, 'remove_device'],
        ]);
    }
    public function register_device(WP_REST_Request $request) {
        global $wpdb;
        $id = (string)$request->get_param('installation_id');
        $secret = (string)$request->get_param('secret');
        $token = (string)$request->get_param('token');
        $platform = (string)$request->get_param('platform');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $secret) ||
            !preg_match('/^(Expo|Exponent)PushToken\[[A-Za-z0-9_-]{10,200}\]$/D', $token) ||
            !in_array($platform, ['android','ios'], true) || $request->get_param('project_id') !== self::PROJECT_ID) {
            return new WP_Error('evg_push_invalid', 'Ungültige Geräteanmeldung.', ['status'=>400]);
        }
        $table = $wpdb->prefix.'evg_app_devices';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE installation_id=%s", $id), ARRAY_A);
        if ($existing && !hash_equals($existing['secret_hash'], hash('sha256',$secret))) return new WP_Error('evg_push_forbidden', 'Geräteanmeldung nicht erlaubt.', ['status'=>403]);
        $hash = hash('sha256', $token);
        $other = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash=%s", $hash), ARRAY_A);
        if ($other && $other['installation_id'] !== $id) {
            if ((int)$other['user_id'] !== get_current_user_id()) return new WP_Error('evg_push_conflict', 'Dieses Gerät muss zuerst vom bisherigen Konto abgemeldet werden.', ['status'=>409]);
            $wpdb->delete($table, ['installation_id'=>$other['installation_id']], ['%s']);
        }
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d", get_current_user_id()));
        if ((!$existing || (int)$existing['user_id']!==get_current_user_id()) && $count >= 10) return new WP_Error('evg_push_limit', 'Maximal zehn Push-Geräte pro Konto.', ['status'=>409]);
        $ok = $wpdb->replace($table, [
            'installation_id'=>$id, 'user_id'=>get_current_user_id(), 'token_hash'=>$hash, 'token'=>$token,
            'secret_hash'=>hash('sha256',$secret), 'platform'=>$platform, 'updated_at'=>current_time('mysql', true),
        ]);
        if (!$ok) return new WP_Error('evg_push_db', 'Gerät konnte nicht gespeichert werden.', ['status'=>500]);
        return new WP_REST_Response(['registered'=>true], 200);
    }
    /** Widerrufsgeheimnis erlaubt das Abmelden auch nach Ablauf des EasyVerein-Access-Tokens. */
    public function remove_device(WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix.'evg_app_devices';
        $id = (string)$request->get_param('installation_id');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE installation_id=%s", $id), ARRAY_A);
        if (!$row) return new WP_REST_Response(['registered'=>false], 200);
        $secret = (string)$request->get_param('secret');
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret) || !hash_equals($row['secret_hash'], hash('sha256',$secret))) {
            return new WP_Error('evg_push_forbidden', 'Geräteabmeldung nicht erlaubt.', ['status'=>403]);
        }
        if ($wpdb->delete($table, ['installation_id'=>$id], ['%s']) === false) return new WP_Error('evg_push_db', 'Gerät konnte nicht abgemeldet werden.', ['status'=>500]);
        return new WP_REST_Response(['registered'=>false], 200);
    }
    public static function enqueue(array $users, $event, array $payload) {
        global $wpdb;
        foreach (array_unique(array_map('intval', $users)) as $user_id) {
            $devices = $wpdb->get_results($wpdb->prepare("SELECT token_hash FROM {$wpdb->prefix}evg_app_devices WHERE user_id=%d", $user_id), ARRAY_A);
            foreach ((array)$devices as $device) {
                $key = hash('sha256', $event.'|'.$user_id.'|'.$device['token_hash']);
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}evg_app_push_jobs (user_id,token_hash,event_key,payload,available_at) VALUES (%d,%s,%s,%s,%s)",
                    $user_id, $device['token_hash'], $key, wp_json_encode($payload), current_time('mysql', true)
                ));
            }
        }
        self::schedule();
    }
    private static function schedule() {
        global $wpdb;
        $next = $wpdb->get_var("SELECT MIN(available_at) FROM {$wpdb->prefix}evg_app_push_jobs WHERE status IN ('pending','receipt')");
        if (!$next) return;
        $due = max(time()+1, strtotime($next.' UTC'));
        $scheduled = wp_next_scheduled(self::HOOK);
        if ($scheduled && $scheduled > $due) { wp_unschedule_event($scheduled, self::HOOK); $scheduled = false; }
        if (!$scheduled) wp_schedule_single_event($due, self::HOOK);
    }
    private static function http($endpoint, array $body) {
        $headers = ['Content-Type'=>'application/json', 'Accept'=>'application/json'];
        $access = defined('EVG_EXPO_ACCESS_TOKEN') ? EVG_EXPO_ACCESS_TOKEN : get_option('evg_expo_access_token', '');
        if ($access !== '') $headers['Authorization'] = 'Bearer '.$access;
        $response = wp_remote_post('https://exp.host/--/api/v2/push/'.$endpoint, [
            'headers'=>$headers, 'body'=>wp_json_encode($body), 'timeout'=>8, 'redirection'=>0,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) && isset($data['data']) ? $data['data'] : null;
    }
    private static function update_job($id, array $data) {
        global $wpdb;
        $wpdb->update($wpdb->prefix.'evg_app_push_jobs', $data, ['id'=>$id]);
    }
    private static function retry(array $job, $error, $receipt = false) {
        $attempts = (int)$job['attempts'] + 1;
        self::update_job($job['id'], [
            'status'=>$attempts >= 8 ? 'failed' : ($receipt ? 'receipt' : 'pending'), 'attempts'=>$attempts,
            'available_at'=>gmdate('Y-m-d H:i:s', time()+($receipt ? 900 : min(3600, 60*(2 ** $attempts)))),
            'last_error'=>substr($error, 0, 191),
        ]);
    }
    public static function work() {
        global $wpdb;
        $lock = 'evg_push_'.md5($wpdb->prefix);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { self::schedule(); return; }
        try {
            $jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}evg_app_push_jobs WHERE status IN ('pending','receipt') AND available_at<=%s ORDER BY id LIMIT 10", current_time('mysql', true)
            ), ARRAY_A);
            $started = microtime(true);
            foreach ((array)$jobs as $job) {
                if (microtime(true)-$started > 15) break;
                $payload = json_decode($job['payload'], true);
                $device = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}evg_app_devices WHERE token_hash=%s AND user_id=%d", $job['token_hash'], $job['user_id']), ARRAY_A);
                if (!$device || !is_array($payload) || ($job['status']==='pending' && !EVG_Group_Requests::can_receive_push((int)$job['user_id'], $payload))) {
                    self::update_job($job['id'], ['status'=>'done']); continue;
                }
                if ($job['status'] === 'receipt') {
                    $receipts = self::http('getReceipts', ['ids'=>[$job['ticket_id']]]);
                    $result = is_array($receipts) ? ($receipts[$job['ticket_id']] ?? null) : null;
                    if (!$result) { self::retry($job, 'Receipt noch nicht verfügbar', true); continue; }
                } else {
                    $payload['to'] = $device['token'];
                    $payload['data']['owner'] = untrailingslashit(home_url()).'#'.$job['user_id'];
                    $payload['channelId'] = 'group-requests';
                    $payload['sound'] = 'default';
                    $result = self::http('send', $payload);
                    if (!$result) { self::retry($job, 'Push-Dienst nicht erreichbar'); continue; }
                    if (($result['status'] ?? '') === 'ok' && !empty($result['id'])) {
                        self::update_job($job['id'], ['status'=>'receipt', 'ticket_id'=>$result['id'], 'available_at'=>gmdate('Y-m-d H:i:s', time()+900), 'attempts'=>0]); continue;
                    }
                }
                if (($result['status'] ?? '') === 'ok') { self::update_job($job['id'], ['status'=>'done','last_error'=>'']); continue; }
                $error = $result['details']['error'] ?? 'PushError';
                if ($error === 'DeviceNotRegistered') {
                    $wpdb->delete($wpdb->prefix.'evg_app_devices', ['token_hash'=>$job['token_hash']], ['%s']);
                    self::update_job($job['id'], ['status'=>'done','last_error'=>'DeviceNotRegistered']);
                } elseif (in_array($error, ['MessageTooBig','InvalidCredentials','MismatchSenderId'], true)) {
                    self::update_job($job['id'], ['status'=>'failed','last_error'=>$error]);
                } else self::retry($job, 'Push-Zustellung fehlgeschlagen');
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            self::schedule();
        }
    }
}
