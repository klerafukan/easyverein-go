<?php
/** Beantragbare Vereinsgruppen und serverseitig geprüfte mobile Genehmigungen. */
if (!defined('ABSPATH')) { exit; }
class EVG_Group_Requests {
    const RULES = 'evg_group_join_rules';
    public function __construct() {
        add_action('init', [__CLASS__, 'ensure_table']);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_evg_group_join_settings', [$this, 'save_settings']);
        add_action('admin_post_evg_group_join_decision', [$this, 'save_decision']);
    }
    public static function ensure_table($force = false) {
        if (!$force && (int)get_option('evg_group_join_schema', 0) === 1) return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}evg_group_join_requests (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            requested_by bigint unsigned NOT NULL,
            member_id varchar(191) NOT NULL,
            member_name varchar(255) NOT NULL,
            group_id varchar(191) NOT NULL,
            group_label varchar(255) NOT NULL,
            payment_active tinyint NOT NULL DEFAULT 0,
            note text NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            active_key char(64) DEFAULT NULL,
            requested_at datetime NOT NULL,
            reviewed_by bigint unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            review_note text DEFAULT NULL,
            last_error varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY active_key (active_key),
            KEY requester (requested_by,status),
            KEY reviewer_queue (status,group_id(64))
        ) {$charset};");
        if (!$force) update_option('evg_group_join_schema', 1, false);
    }
    public static function rules() {
        $rules = get_option(self::RULES, []);
        return is_array($rules) ? $rules : [];
    }
    public static function can_approve($user_id, $group_id = null) {
        if (user_can($user_id, 'manage_options')) return true;
        $rules = self::rules();
        if ($group_id !== null) $rules = isset($rules[$group_id]) ? [$rules[$group_id]] : [];
        foreach ($rules as $rule) if (in_array((int)$user_id, array_map('intval', (array)($rule['approvers'] ?? [])), true)) return true;
        return false;
    }
    private static function review_scope($user_id) {
        $ids = [];
        foreach (self::rules() as $id=>$rule) if (self::can_approve($user_id, (string)$id)) $ids[] = (string)$id;
        return $ids;
    }
    private static function error($code, $message, $status = 400) { return new WP_Error($code, $message, ['status'=>$status]); }
    private static function table() { global $wpdb; return $wpdb->prefix.'evg_group_join_requests'; }
    public function routes() {
        $auth = function() { return is_user_logged_in(); };
        register_rest_route('easyverein-go/v1', '/membership/groups', ['methods'=>'GET','permission_callback'=>$auth,'callback'=>[$this,'groups']]);
        register_rest_route('easyverein-go/v1', '/membership/requests', [
            ['methods'=>'GET','permission_callback'=>$auth,'callback'=>[$this,'requests']],
            ['methods'=>'POST','permission_callback'=>$auth,'callback'=>[$this,'submit']],
        ]);
        register_rest_route('easyverein-go/v1', '/membership/requests/(?P<id>\d+)/decision', ['methods'=>'POST','permission_callback'=>$auth,'callback'=>[$this,'decide']]);
        register_rest_route('easyverein-go/v1', '/membership/requests/(?P<id>\d+)', ['methods'=>'DELETE','permission_callback'=>$auth,'callback'=>[$this,'withdraw']]);
    }
    public function groups(WP_REST_Request $request) {
        global $wpdb;
        $member = (string)get_user_meta(get_current_user_id(), 'evg_member_id', true);
        $rules = self::rules(); $items = [];
        foreach ((array)$wpdb->get_results("SELECT group_id,name FROM {$wpdb->prefix}evg_groups ORDER BY name", ARRAY_A) as $group) {
            $id = (string)$group['group_id'];
            if (!isset($rules[$id])) continue;
            $joined = $member !== '' && (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}evg_member_groups WHERE member_id=%s AND group_id=%s", $member, $id));
            $items[] = ['group_id'=>$id,'label'=>$group['name'] ?: $id,'is_member'=>$joined,'payment_active'=>!empty($rules[$id]['payment_active'])];
        }
        return new WP_REST_Response(['groups'=>$items,'can_request'=>$member !== '', 'can_approve'=>self::can_approve(get_current_user_id())], 200);
    }
    private static function queue_where($user_id) {
        global $wpdb;
        $where = $wpdb->prepare("status IN ('pending','processing') AND requested_by<>%d", $user_id);
        if (!user_can($user_id, 'manage_options')) {
            $ids = self::review_scope($user_id);
            if (!$ids) return '1=0';
            $where .= $wpdb->prepare(' AND group_id IN ('.implode(',', array_fill(0, count($ids), '%s')).')', ...$ids);
        }
        return $where;
    }
    public static function pending_count($user_id) {
        global $wpdb;
        return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE '.self::queue_where($user_id));
    }
    public static function format(array $row) {
        return [
            'id'=>(int)$row['id'],'group_id'=>(string)$row['group_id'],'group_label'=>$row['group_label'],
            'member_name'=>$row['member_name'],'requested_by'=>(int)$row['requested_by'],
            'note'=>$row['note'],'status'=>$row['status'],'requested_at'=>$row['requested_at'],
            'reviewed_at'=>$row['reviewed_at'],'review_note'=>$row['review_note'],'last_error'=>$row['last_error'],
            'payment_active'=>(bool)$row['payment_active'],
        ];
    }
    public function requests(WP_REST_Request $request) {
        global $wpdb;
        $approvals = $request->get_param('scope') === 'approvals';
        if ($approvals && !self::can_approve(get_current_user_id())) return self::error('evg_join_forbidden','Keine Freigabeberechtigung.',403);
        $where = $approvals ? self::queue_where(get_current_user_id()) : $wpdb->prepare('requested_by=%d',get_current_user_id());
        $offset = max(0, (int)$request->get_param('offset'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE {$where} ORDER BY requested_at DESC,id DESC LIMIT 50 OFFSET %d",$offset), ARRAY_A);
        $total = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table()." WHERE {$where}");
        return new WP_REST_Response(['items'=>array_map([__CLASS__,'format'],(array)$rows),'total'=>$total,'can_approve'=>self::can_approve(get_current_user_id())],200);
    }
    public function submit(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $member = (string)get_user_meta($user_id,'evg_member_id',true);
        if (!ctype_digit($member) || (int)$member <= 0) return self::error('evg_join_member','Deinem Konto ist noch kein Vereinsmitglied zugeordnet.',403);
        $id = (string)$request->get_param('group_id');
        $rule = self::rules()[$id] ?? null;
        $group = $wpdb->get_row($wpdb->prepare("SELECT group_id,name FROM {$wpdb->prefix}evg_groups WHERE group_id=%s",$id),ARRAY_A);
        $person = $wpdb->get_row($wpdb->prepare("SELECT first_name,family_name FROM {$wpdb->prefix}evg_members WHERE member_id=%s",$member),ARRAY_A);
        if (!$person) return self::error('evg_join_member','Vereinsmitglied nicht verfügbar. Bitte den Verein kontaktieren.',403);
        if (!$rule || !$group || !ctype_digit($id)) return self::error('evg_join_group','Diese Gruppe kann nicht beantragt werden.',400);
        if (!empty($rule['payment_active']) && $request->get_param('acknowledge_payments') !== true) return self::error('evg_join_payments','Bitte bestätige den Hinweis zu Gruppenzahlungen.');
        $note = sanitize_textarea_field((string)$request->get_param('note'));
        if (mb_strlen($note) > 1000) return self::error('evg_join_note','Die Nachricht darf höchstens 1000 Zeichen enthalten.');
        $key = hash('sha256',$member.'|'.$id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE active_key=%s',$key),ARRAY_A);
        if ($row) return (int)$row['requested_by'] === $user_id ? new WP_REST_Response(self::format($row),200) : self::error('evg_join_pending','Für diese Gruppenmitgliedschaft liegt bereits ein Antrag vor.',409);
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}evg_member_groups WHERE member_id=%s AND group_id=%s",$member,$id))) return self::error('evg_join_existing','Du bist bereits Mitglied dieser Gruppe.',409);
        $ok = $wpdb->insert(self::table(),[
            'requested_by'=>$user_id,'member_id'=>$member,'member_name'=>trim($person['first_name'].' '.$person['family_name']),
            'group_id'=>$id,'group_label'=>$group['name'] ?: $id,'payment_active'=>!empty($rule['payment_active']) ? 1 : 0,
            'note'=>$note,'active_key'=>$key,'requested_at'=>current_time('mysql',true),
        ]);
        if (!$ok) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE active_key=%s',$key),ARRAY_A);
            return $row && (int)$row['requested_by'] === $user_id ? new WP_REST_Response(self::format($row),200) : self::error('evg_join_db','Antrag konnte nicht gespeichert werden. Bitte aktualisieren.',409);
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$wpdb->insert_id),ARRAY_A);
        $reviewers = array_unique(array_merge((array)($rule['approvers'] ?? []),get_users(['role'=>'administrator','fields'=>'ids'])));
        $reviewers = array_values(array_filter(array_map('intval',$reviewers),function($uid) use($user_id) { return $uid !== $user_id; }));
        EVG_App_Push::enqueue($reviewers,'join-new-'.$row['id'],[
            'title'=>'Neuer Gruppenantrag','body'=>'Ein Gruppenantrag wartet auf deine Freigabe.',
            'data'=>['feature'=>'group-join-request','requestId'=>(int)$row['id'],'view'=>'approvals'],
        ]);
        return new WP_REST_Response(self::format($row),201);
    }
    private static function ref_id($value) {
        if (is_array($value)) $value = $value['id'] ?? '';
        if (is_numeric($value)) return (string)(int)$value;
        return preg_match('~/([0-9]+)/?$~',(string)$value,$match) ? $match[1] : '';
    }
    private static function ev_request($method, array $body) {
        $base = rtrim((string)get_option('evg_api_base','https://easyverein.com'),'/');
        $key = (string)get_option('evg_api_key','');
        if ($key === '') return self::error('evg_join_api','EasyVerein-Zugang ist nicht konfiguriert.',503);
        $headers = ['Accept'=>'application/json','Content-Type'=>'application/json'];
        $headers[get_option('evg_auth_header','Authorization Bearer') === 'Authorization Bearer' ? 'Authorization' : 'X-API-Key'] = get_option('evg_auth_header','Authorization Bearer') === 'Authorization Bearer' ? 'Bearer '.$key : $key;
        $url = $base.'/api/v3.0/member-group-assignment';
        $args = ['method'=>$method,'headers'=>$headers,'timeout'=>20,'redirection'=>0];
        if ($method === 'GET') $url = add_query_arg($body,$url); else $args['body'] = wp_json_encode($body);
        evg_api_throttle();
        $response = wp_remote_request($url,$args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            return self::error('evg_join_api','EasyVerein konnte die Gruppenmitgliedschaft nicht bestätigen. Bitte später erneut versuchen.',502);
        }
        $data = json_decode(wp_remote_retrieve_body($response),true);
        return is_array($data) ? $data : self::error('evg_join_api','Ungültige Antwort von EasyVerein.',502);
    }
    private static function is_assigned(array $row) {
        $data = self::ev_request('GET',['user_object'=>$row['member_id'],'member_group'=>$row['group_id'],'show_active_groups'=>'true','limit'=>100]);
        if (is_wp_error($data)) return $data;
        $items = $data['results'] ?? $data;
        if (!is_array($items) || ($items && array_keys($items) !== range(0,count($items)-1))) return self::error('evg_join_api','Ungültige Antwort von EasyVerein.',502);
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['user_object'],$item['member_group'])) return self::error('evg_join_api','Unvollständige Antwort von EasyVerein.',502);
            if (self::ref_id($item['user_object']) === $row['member_id'] && self::ref_id($item['member_group']) === $row['group_id']) return true;
        }
        return false;
    }
    public function decide(WP_REST_Request $request) {
        return $this->change_status((int)$request->get_param('id'),(string)$request->get_param('action'),sanitize_textarea_field((string)$request->get_param('note')));
    }
    public function withdraw(WP_REST_Request $request) { return $this->change_status((int)$request->get_param('id'),'withdraw',''); }
    private function change_status($id,$action,$note) {
        global $wpdb;
        if (!in_array($action,['approve','reject','withdraw'],true) || mb_strlen($note)>1000 || ($action==='reject' && $note==='')) return self::error('evg_join_action','Gültige Aktion und bei Ablehnung eine Begründung angeben.');
        $lock = 'evg_join_'.md5($wpdb->prefix.'|'.$id);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== 1) return self::error('evg_join_busy','Dieser Antrag wird gerade bearbeitet.',409);
        try {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A);
            if (!$row) return self::error('evg_join_missing','Antrag nicht gefunden.',404);
            $uid = get_current_user_id();
            if ($action==='withdraw' ? (int)$row['requested_by']!==$uid : (!self::can_approve($uid,$row['group_id']) || (int)$row['requested_by']===$uid)) return self::error('evg_join_forbidden','Dieser Antrag darf von dir nicht bearbeitet werden.',403);
            $status = ['approve'=>'approved','reject'=>'rejected','withdraw'=>'withdrawn'][$action];
            if ($row['status']===$status) return new WP_REST_Response(self::format($row),200);
            if (!in_array($row['status'],['pending','processing'],true)) return self::error('evg_join_closed','Der Antrag wurde bereits abgeschlossen.',409);
            if ($row['status']==='processing' && $action!=='approve') return self::error('evg_join_processing','Die Mitgliedschaft muss zuerst erneut in EasyVerein geprüft werden.',409);
            if ($action==='approve') {
                $rule = self::rules()[$row['group_id']] ?? null;
                if (!$rule || (bool)$row['payment_active']!==!empty($rule['payment_active'])) return self::error('evg_join_changed','Die Gruppeneinstellungen haben sich geändert. Bitte den Antrag neu stellen.',409);
                if ($wpdb->update(self::table(),['status'=>'processing','last_error'=>''],['id'=>$id])===false) return self::error('evg_join_db','Antrag konnte nicht zur Bearbeitung markiert werden.',500);
                $assigned = self::is_assigned($row);
                $write_attempted = false;
                if (!is_wp_error($assigned) && !$assigned) {
                    $write_attempted = true;
                    // v3.0 verlangt user_object bei POST (offizieller Migrationsleitfaden).
                    $created = self::ev_request('POST',['user_object'=>(int)$row['member_id'],'member_group'=>(int)$row['group_id'],'payment_active'=>(bool)$row['payment_active'],'start'=>wp_date('Y-m-d')]);
                    // Bei Timeout kann POST trotzdem erfolgreich gewesen sein: immer nachlesen.
                    $assigned = self::is_assigned($row);
                    if (!is_wp_error($created) && $assigned === false) $assigned = self::error('evg_join_unconfirmed','EasyVerein hat die Mitgliedschaft noch nicht bestätigt. Bitte erneut versuchen.',502);
                    if (is_wp_error($created) && $assigned === false) $assigned = $created;
                }
                if (is_wp_error($assigned) || !$assigned) {
                    $message = is_wp_error($assigned) ? $assigned->get_error_message() : 'Mitgliedschaft nicht bestätigt.';
                    // Nach einem Schreibversuch kann die Mitgliedschaft trotz verlorener Antwort bestehen.
                    // Bis zur erfolgreichen Nachprüfung weder ablehnen noch zurückziehen.
                    $wpdb->update(self::table(),['status'=>($write_attempted || $row['status']==='processing') ? 'processing' : 'pending','last_error'=>$message],['id'=>$id]);
                    return is_wp_error($assigned) ? $assigned : self::error('evg_join_api',$message,502);
                }
                // Keine Änderung von evg_groups/evg_groups_all: Mitgliedschaft gewährt keine App-Datenrechte.
                $wpdb->replace($wpdb->prefix.'evg_member_groups',[
                    'member_id'=>$row['member_id'],'group_id'=>$row['group_id'],'member_name'=>$row['member_name'],
                    'group_name'=>$row['group_label'],'assigned_at'=>current_time('mysql'),
                ]);
            }
            $ok = $wpdb->update(self::table(),['status'=>$status,'active_key'=>null,'reviewed_by'=>$uid,'reviewed_at'=>current_time('mysql',true),'review_note'=>$note,'last_error'=>''],['id'=>$id]);
            if ($ok===false) return self::error('evg_join_db','Entscheidung konnte nicht gespeichert werden. Bitte aktualisieren und erneut prüfen.',500);
            if ($action!=='withdraw') EVG_App_Push::enqueue([(int)$row['requested_by']],'join-'.$status.'-'.$id,[
                'title'=>'Gruppenantrag entschieden','body'=>$status==='approved' ? 'Dein Gruppenantrag wurde genehmigt.' : 'Dein Gruppenantrag wurde abgelehnt.',
                'data'=>['feature'=>'group-join-request','requestId'=>$id,'view'=>'mine'],
            ]);
            return new WP_REST_Response(self::format($wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A)),200);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function can_receive_push($user_id,array $payload) {
        global $wpdb;
        $data = $payload['data'] ?? [];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',(int)($data['requestId'] ?? 0)),ARRAY_A);
        if (!$row) return false;
        return ($data['view'] ?? '')==='approvals'
            ? in_array($row['status'],['pending','processing'],true) && (int)$row['requested_by']!==$user_id && self::can_approve($user_id,$row['group_id'])
            : (int)$row['requested_by']===$user_id && in_array($row['status'],['approved','rejected'],true);
    }
    public function menu() { add_submenu_page(EVG_SLUG,'Gruppenanträge','Gruppenanträge','manage_options','evg-group-requests',[$this,'admin_page']); }
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'easyverein-go_page_evg-group-requests') return;
        wp_enqueue_script('evg-group-requests',EVG_URL.'assets/admin/evg-group-requests.js',[],EVG_VERSION,true);
    }
    public function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Nicht erlaubt.',403);
        check_admin_referer('evg_group_join_settings');
        global $wpdb;
        $rules = []; $submitted = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : [];
        foreach ((array)$wpdb->get_col("SELECT group_id FROM {$wpdb->prefix}evg_groups") as $id) {
            if (!isset($submitted[$id]) || !is_array($submitted[$id]) || empty($submitted[$id]['enabled'])) continue;
            $approvers = array_values(array_filter(array_unique(array_map('intval',(array)($submitted[$id]['approvers'] ?? []))),function($uid) { return $uid>0 && get_userdata($uid); }));
            $rules[(string)$id] = ['approvers'=>$approvers,'payment_active'=>!empty($submitted[$id]['payment_active'])];
        }
        update_option(self::RULES,$rules,false);
        if (!empty($_POST['clear_expo_access_token'])) delete_option('evg_expo_access_token');
        elseif (!empty($_POST['expo_access_token'])) update_option('evg_expo_access_token',trim((string)wp_unslash($_POST['expo_access_token'])),false);
        wp_safe_redirect(admin_url('admin.php?page=evg-group-requests&tab=settings&saved=1')); exit;
    }
    private function render_settings() {
        global $wpdb;
        $rules=self::rules(); $users=get_users(['orderby'=>'display_name']);
        $groups=$wpdb->get_results("SELECT group_id,name FROM {$wpdb->prefix}evg_groups ORDER BY name",ARRAY_A);
        echo '<h2>Einstellungen</h2><p>Beantragbare EasyVerein-Gruppen und zuständige Genehmiger. Administratoren dürfen alle Anträge außer eigenen bearbeiten. Freigaben sind hier unter Anträge und in der App unter Gruppen → Freigaben möglich.</p>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success inline"><p>Einstellungen gespeichert.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="evg_group_join_settings">';
        wp_nonce_field('evg_group_join_settings');
        echo '<div data-evg-group-selection hidden><p><button type="button" class="button" data-evg-select-all>Alle Gruppen auswählen</button> <button type="button" class="button" data-evg-deselect-all>Alle abwählen</button> <span data-evg-selection-count role="status" aria-live="polite"></span></p><p>Wähle alle Gruppen aus und entferne anschließend einzelne Häkchen als Ausnahmen. Übernommen wird die Auswahl erst mit „Einstellungen speichern“.</p></div>';
        echo '<table class="widefat" data-evg-group-rules><thead><tr><th><label data-evg-select-header hidden><input type="checkbox" data-evg-toggle-all aria-label="Alle Gruppen als beantragbar auswählen oder abwählen"> </label>Beantragbar</th><th>Gruppe</th><th>Genehmiger</th><th>Gruppenzahlungen aktivieren</th></tr></thead><tbody>';
        foreach ($groups as $group) {
            $id=(string)$group['group_id']; $rule=$rules[$id] ?? [];
            echo '<tr><td><input type="checkbox" data-evg-group-enabled aria-label="'.esc_attr(($group['name'] ?: $id).' als beantragbar freigeben').'" name="rules['.esc_attr($id).'][enabled]" value="1" '.checked(isset($rules[$id]),true,false).'></td><td>'.esc_html($group['name'] ?: $id).'</td><td><select multiple size="4" name="rules['.esc_attr($id).'][approvers][]" aria-label="Genehmiger für '.esc_attr($group['name']).'">';
            foreach ($users as $user) echo '<option value="'.(int)$user->ID.'" '.selected(in_array((int)$user->ID,array_map('intval',(array)($rule['approvers'] ?? [])),true),true,false).'>'.esc_html($user->display_name).'</option>';
            echo '</select></td><td><input type="checkbox" name="rules['.esc_attr($id).'][payment_active]" value="1" '.checked(!empty($rule['payment_active']),true,false).'></td></tr>';
        }
        echo '</tbody></table><h2>Expo Push</h2><p>Optionaler Expo Access Token bei aktivierter Push-Sicherheit. Bereits gespeicherte Tokens werden nicht angezeigt.</p><input type="password" autocomplete="new-password" name="expo_access_token" value=""><label><input type="checkbox" name="clear_expo_access_token" value="1"> Gespeicherten Expo-Token löschen</label>';
        submit_button('Einstellungen speichern'); echo '</form><h2>Push-Status</h2>';
        foreach ((array)$wpdb->get_results("SELECT status,COUNT(*) AS n FROM {$wpdb->prefix}evg_app_push_jobs GROUP BY status",ARRAY_A) as $item) echo '<p>'.esc_html($item['status']).': '.(int)$item['n'].'</p>';
    }
    /** Gleicher Entscheidungsweg wie REST, zusätzlich mit WordPress-Adminrecht und CSRF-Prüfung. */
    public function admin_decision(array $input) {
        if (!current_user_can('manage_options')) return self::error('evg_join_forbidden','Keine Berechtigung für die Antragsverwaltung.',403);
        $id = absint($input['request_id'] ?? 0);
        if (!$id || !isset($input['_wpnonce']) || !is_string($input['_wpnonce']) || !wp_verify_nonce($input['_wpnonce'],'evg_group_join_decision_'.$id)) return self::error('evg_join_nonce','Die Sicherheitsprüfung ist abgelaufen. Bitte die Antragsliste neu laden.',403);
        $action = isset($input['decision']) && is_string($input['decision']) ? $input['decision'] : '';
        if (!in_array($action,['approve','reject'],true)) return self::error('evg_join_action','Ungültige Entscheidung.');
        $note = isset($input['note']) && is_string($input['note']) ? sanitize_textarea_field($input['note']) : '';
        return $this->change_status($id,$action,$note);
    }
    public function save_decision() {
        $result = $this->admin_decision(wp_unslash($_POST));
        $args = ['page'=>'evg-group-requests'];
        if (is_wp_error($result)) {
            $args['result']='error'; $args['message']=$result->get_error_message();
        } else $args['result']=$result->get_data()['status'];
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php'))); exit;
    }
    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $settings = isset($_GET['tab']) && $_GET['tab']==='settings';
        $base = admin_url('admin.php?page=evg-group-requests');
        echo '<div class="wrap"><h1>Gruppenanträge</h1><nav class="nav-tab-wrapper" aria-label="Gruppenanträge">';
        echo '<a class="nav-tab '.(!$settings ? 'nav-tab-active' : '').'" href="'.esc_url($base).'">Anträge</a>';
        echo '<a class="nav-tab '.($settings ? 'nav-tab-active' : '').'" href="'.esc_url(add_query_arg('tab','settings',$base)).'">Einstellungen</a></nav>';
        if ($settings) $this->render_settings(); else $this->render_requests();
        echo '</div>';
    }
    private function render_requests() {
        global $wpdb;
        $labels = ['pending'=>'Wartet auf Freigabe','processing'=>'Mitgliedschaft prüfen','approved'=>'Genehmigt','rejected'=>'Abgelehnt','withdrawn'=>'Zurückgezogen'];
        $filters = ['open'=>'Offene Anträge','all'=>'Alle Anträge'] + $labels;
        $status = isset($_GET['status']) && is_string($_GET['status']) && isset($filters[$_GET['status']]) ? $_GET['status'] : 'open';
        $search = isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = max(1,absint($_GET['paged'] ?? 1));
        $where = $status==='open' ? "status IN ('pending','processing')" : ($status==='all' ? '1=1' : $wpdb->prepare('status=%s',$status));
        if ($search!=='') {
            $like='%'.$wpdb->esc_like($search).'%';
            $where.=$wpdb->prepare(' AND (member_name LIKE %s OR member_id LIKE %s OR group_label LIKE %s OR note LIKE %s)',$like,$like,$like,$like);
        }
        $total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE '.$where);
        $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE {$where} ORDER BY requested_at DESC,id DESC LIMIT 20 OFFSET %d",($page-1)*20),ARRAY_A);
        echo '<h2>Anträge verwalten</h2><p>Prüfe den Antrag und bestätige die Gruppenmitgliedschaft. Eine Genehmigung trägt die Mitgliedschaft direkt in EasyVerein ein.</p>';
        $result=isset($_GET['result']) && is_string($_GET['result']) ? $_GET['result'] : '';
        if (in_array($result,['approved','rejected'],true)) echo '<div class="notice notice-success inline"><p>'.($result==='approved' ? 'Die Mitgliedschaft wurde in EasyVerein bestätigt und der Antrag genehmigt.' : 'Der Antrag wurde abgelehnt.').'</p></div>';
        if ($result==='error') {
            $message=isset($_GET['message']) && is_string($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : 'Die Entscheidung konnte nicht gespeichert werden.';
            echo '<div class="notice notice-error inline"><p>'.esc_html($message).'</p></div>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="evg-group-requests"><p><label for="evg-join-status">Status </label><select id="evg-join-status" name="status">';
        foreach ($filters as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($status,$value,false).'>'.esc_html($label).'</option>';
        echo '</select> <label class="screen-reader-text" for="evg-join-search">Mitglied, Gruppe oder Nachricht suchen</label><input type="search" id="evg-join-search" name="s" value="'.esc_attr($search).'" placeholder="Mitglied oder Gruppe suchen"> <button class="button">Filtern</button></p></form>';
        echo '<p>'.(int)$total.' Anträge · Seite '.(int)$page.' von '.(int)$pages.'</p><table class="widefat striped"><thead><tr><th>Antrag</th><th>Mitglied</th><th>Gruppe und Nachricht</th><th>Status und Entscheidung</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">Keine Anträge für diese Auswahl vorhanden.</td></tr>';
        foreach ((array)$rows as $row) {
            $requester=get_userdata((int)$row['requested_by']);
            $reviewer=$row['reviewed_by'] ? get_userdata((int)$row['reviewed_by']) : false;
            $date=get_date_from_gmt($row['requested_at'],get_option('date_format').' '.get_option('time_format'));
            echo '<tr><td>#'.(int)$row['id'].'<br>'.esc_html($date).'</td><td><strong>'.esc_html($row['member_name']).'</strong><br>Mitglieds-ID: '.esc_html($row['member_id']).'<br>Konto: '.esc_html($requester ? $requester->display_name : 'Gelöscht').'</td>';
            echo '<td><strong>'.esc_html($row['group_label']).'</strong><p style="white-space:pre-wrap">'.esc_html($row['note'] ?: 'Keine Nachricht').'</p><p>Gruppenzahlungen: '.(!empty($row['payment_active']) ? 'Bei Genehmigung aktivieren (vom Antragsteller bestätigt)' : 'Nicht aktivieren').'</p></td>';
            echo '<td><strong>'.esc_html($labels[$row['status']] ?? $row['status']).'</strong>';
            if ($reviewer) echo '<p>Bearbeitet von '.esc_html($reviewer->display_name).' · '.esc_html(get_date_from_gmt($row['reviewed_at'],get_option('date_format').' '.get_option('time_format'))).'</p>';
            if ($row['review_note']) echo '<p style="white-space:pre-wrap">'.esc_html($row['review_note']).'</p>';
            if ($row['last_error']) echo '<p role="alert">'.esc_html($row['last_error']).'</p>';
            if (in_array($row['status'],['pending','processing'],true)) {
                if ((int)$row['requested_by']===get_current_user_id()) echo '<p>Eigene Anträge müssen von einer anderen berechtigten Person bearbeitet werden.</p>';
                else {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="evg_group_join_decision"><input type="hidden" name="request_id" value="'.(int)$row['id'].'">';
                    wp_nonce_field('evg_group_join_decision_'.$row['id']);
                    if ($row['status']==='pending') echo '<p><label for="evg-join-note-'.(int)$row['id'].'">Begründung (bei Ablehnung erforderlich)</label><br><textarea id="evg-join-note-'.(int)$row['id'].'" name="note" maxlength="1000" rows="3" style="width:100%"></textarea></p>';
                    echo '<p><button class="button button-primary" name="decision" value="approve">'.($row['status']==='processing' ? 'Mitgliedschaft prüfen' : 'Genehmigen').'</button> ';
                    if ($row['status']==='pending') echo '<button class="button" name="decision" value="reject">Ablehnen</button>';
                    echo '</p></form>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if ($pages>1) {
            $base=add_query_arg(['page'=>'evg-group-requests','status'=>$status,'s'=>$search],admin_url('admin.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">';
            if ($page>1) echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1,$base)).'">← Vorherige Seite</a> ';
            if ($page<$pages) echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page+1,$base)).'">Nächste Seite →</a>';
            echo '</div></div>';
        }
    }
}
