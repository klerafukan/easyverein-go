<?php
/**
 * Plugin Name: Easyverein Go
 * Description: Mitglieder-Sync + lokales Frontend. Speichert und nutzt den contact-details Link pro Mitglied. Benutzerbezogene Gruppenfreigabe.
 * Version: 2.1.2
 * Author: Tilmann Laux
 * Text Domain: ev-groups
 */
if (!defined('ABSPATH')) { exit; }

define('EVG_VERSION','2.1.2');
define('EVG_SLUG','easyverein-go');
define('EVG_PATH', plugin_dir_path(__FILE__));
define('EVG_URL', plugin_dir_url(__FILE__));

require_once EVG_PATH.'includes/evg-utils.php';
require_once EVG_PATH.'includes/class-evg-admin.php';
require_once EVG_PATH.'includes/class-evg-sync.php';
require_once EVG_PATH.'includes/class-evg-frontend.php';

class EVG_Plugin {
    private const CRON_HOOK = 'evg_nightly_sync';

    public function __construct(){
        register_activation_hook(__FILE__,[$this,'on_activate']);
        register_deactivation_hook(__FILE__,[$this,'on_deactivate']);
        add_action('plugins_loaded',[$this,'init']);
        add_action(self::CRON_HOOK,[$this,'run_nightly_sync']);
        add_action('update_option_evg_nightly_sync_enabled',[$this,'handle_nightly_toggle'],10,3);
    }
    public function on_activate(){
        $defaults = [
            'evg_api_base'     => 'https://easyverein.com',
            'evg_api_key'      => '',
            'evg_auth_header'  => 'Authorization Bearer',
            'evg_groups_path'  => '/api/v2.0/member-group',
            'evg_members_path' => '/api/v2.0/member',
            'evg_contact_details_path' => '/api/v2.0/contact-details/{id}',
            'evg_member_groups_path'   => '/api/v2.0/member/{id}/groups',
            'evg_columns'      => ['first_name','family_name','email_private','date_of_birth','age','birth_year','gender','phone','zip','city','street','groups'],
            'evg_debug'        => 1,
            'evg_sync_next_pages_max' => 100,
            'evg_sync_rate_per_sec'   => 5,
            'evg_sync_calls_per_tick' => 5,
            'evg_tick_pause_ms'       => 900,
            'evg_sync_skip_groups'    => 0,
            'evg_nightly_sync_enabled'=> 0
        ];
        foreach($defaults as $k=>$v){ if (null===get_option($k,null)) update_option($k,$v); }
        $this->create_tables();
        $this->maybe_migrate_add_contact_details();
        $this->maybe_migrate_add_speaking_columns();
        $this->maybe_migrate_add_extended_member_fields();
    }
    public function on_deactivate(){
        $this->clear_cron();
    }
    private function create_tables(){
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $g = $wpdb->prefix.'evg_groups';
        $m = $wpdb->prefix.'evg_members';
        $x = $wpdb->prefix.'evg_member_groups';
        $wpdb->query("CREATE TABLE IF NOT EXISTS $g (
            group_id varchar(191) PRIMARY KEY,
            name varchar(255) DEFAULT '',
            short varchar(120) DEFAULT '',
            updated_at datetime NULL,
            raw longtext
        ) $c;");
        $wpdb->query("CREATE TABLE IF NOT EXISTS $m (
            member_id varchar(191) PRIMARY KEY,
            member_number varchar(64) DEFAULT '',
            contact_details varchar(255) DEFAULT '',
            first_name varchar(120) DEFAULT '',
            family_name varchar(120) DEFAULT '',
            date_of_birth date DEFAULT NULL,
            age int DEFAULT NULL,
            birth_year int DEFAULT NULL,
            gender varchar(32) DEFAULT '',
            email_private varchar(190) DEFAULT '',
            phone varchar(120) DEFAULT '',
            zip varchar(20) DEFAULT '',
            city varchar(120) DEFAULT '',
            street varchar(190) DEFAULT '',
            address_suffix varchar(190) DEFAULT '',
            updated_at datetime NULL,
            raw longtext,
            KEY idx_member_number (member_number)
        ) $c;");
        $wpdb->query("CREATE TABLE IF NOT EXISTS $x (
            member_id varchar(191) NOT NULL,
            group_id varchar(191) NOT NULL,
            member_name varchar(255) DEFAULT '',
            group_name varchar(255) DEFAULT '',
            assigned_at datetime NULL,
            PRIMARY KEY (member_id, group_id),
            KEY idx_group (group_id)
        ) $c;");
    }
    private function maybe_migrate_add_contact_details(){
        global $wpdb; $table=$wpdb->prefix.'evg_members';
        $col = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='contact_details'", DB_NAME, $table ));
        if (!$col) { $wpdb->query("ALTER TABLE {$table} ADD COLUMN contact_details varchar(255) DEFAULT '' AFTER member_number"); }
    }
    private function maybe_migrate_add_speaking_columns(){
        global $wpdb; $table=$wpdb->prefix.'evg_member_groups';
        $member_name_col = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='member_name'", DB_NAME, $table ));
        $group_name_col = $wpdb->get_var($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='group_name'", DB_NAME, $table ));
        
        if (!$member_name_col) { 
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN member_name varchar(255) DEFAULT '' AFTER group_id"); 
        }
        if (!$group_name_col) { 
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN group_name varchar(255) DEFAULT '' AFTER member_name"); 
        }
        
        // Add indexes if they don't exist
        $has_idx_member = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s",
            DB_NAME, $table, 'idx_member_name'
        ));
        if (!$has_idx_member) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_member_name (member_name)");
        }
        $has_idx_group = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s",
            DB_NAME, $table, 'idx_group_name'
        ));
        if (!$has_idx_group) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_group_name (group_name)");
        }
    }
    private function maybe_migrate_add_extended_member_fields(){
        global $wpdb;
        $table = $wpdb->prefix.'evg_members';
        $columns = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME, $table
        ));
        $columns = array_map('strtolower', (array)$columns);
        if (!in_array('birth_year', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN birth_year int DEFAULT NULL AFTER age");
        }
        if (!in_array('gender', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN gender varchar(32) DEFAULT '' AFTER birth_year");
        }
        if (!in_array('phone', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN phone varchar(120) DEFAULT '' AFTER email_private");
        }
    }
    public function init(){
        $this->maybe_migrate_add_extended_member_fields();
        new EVG_Admin();
        new EVG_Sync();
        new EVG_Frontend();
        add_action('init',[$this,'maybe_schedule_cron']);
        add_action('rest_api_init',[$this,'register_rest_endpoints']);
    }

    private function collect_sync_db_counts(){
        global $wpdb;
        $defaults=[
            'groups'=>0,
            'members'=>0,
            'members_with_contact'=>0,
            'member_groups'=>0,
        ];
        if(!isset($wpdb) || !is_object($wpdb)){
            return $defaults;
        }
        $g_table=$wpdb->prefix.'evg_groups';
        $m_table=$wpdb->prefix.'evg_members';
        $x_table=$wpdb->prefix.'evg_member_groups';
        $defaults['groups']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$g_table}");
        $defaults['members']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$m_table}");
        $defaults['members_with_contact']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$m_table} WHERE contact_details IS NOT NULL AND contact_details <> ''");
        $defaults['member_groups']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$x_table}");
        return $defaults;
    }

    private function send_sync_report($success,array $db_counts,array $state_counts,$error_message=''){
        $admin_email=get_option('admin_email');
        if(!$admin_email || !is_email($admin_email)){
            return;
        }
        $site_name=wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES);
        if(empty($site_name)){
            $site_name='WordPress';
        }
        $subject=sprintf('[%s] Easyverein Sync %s',$site_name,$success?'erfolgreich':'fehlgeschlagen');
        $timestamp=current_time('timestamp');
        $formatted_time=date_i18n('d.m.Y H:i',$timestamp);

        $lines=[];
        $lines[]=$success
            ? 'Der naechtliche Easyverein Sync wurde erfolgreich abgeschlossen.'
            : 'Der naechtliche Easyverein Sync wurde NICHT erfolgreich abgeschlossen.';
        if(!$success && !empty($error_message)){
            $lines[]='';
            $lines[]='Fehlerhinweis: '.$error_message;
        }
        $lines[]='';
        $lines[]='Zeitpunkt: '.$formatted_time;
        $lines[]='';
        $lines[]='Lokale Datenbankstaende:';
        $lines[]='- Gruppen: '.(int)$db_counts['groups'];
        $lines[]='- Mitglieder gesamt: '.(int)$db_counts['members'];
        $lines[]='- Mitglieder mit Kontakt-Details: '.(int)$db_counts['members_with_contact'];
        $lines[]='- Gruppen-Zuordnungen: '.(int)$db_counts['member_groups'];

        $state_sum=0;
        foreach(['groups','members_list','details','member_groups'] as $sk){
            $state_sum+=isset($state_counts[$sk]) ? (int)$state_counts[$sk] : 0;
        }
        if($state_sum>0){
            $lines[]='';
            $lines[]='API-Zaehler (Rohdaten):';
            $lines[]='- Gruppen abgeholt: '.(int)$state_counts['groups'];
            $lines[]='- Mitglieder gelistet: '.(int)$state_counts['members_list'];
            $lines[]='- Kontakt-Details geladen: '.(int)$state_counts['details'];
            $lines[]='- Zuordnungen importiert: '.(int)$state_counts['member_groups'];
        }
        if(!empty($state_counts['skip_groups'])){
            $lines[]='';
            $lines[]='Hinweis: Gruppen-Import war fuer diese Ausfuehrung deaktiviert.';
        }
        if(!$success){
            $lines[]='';
            $lines[]='Bitte pruefe das Easyverein Log oder aktiviere den Debug-Modus in den Plugin-Einstellungen.';
        }

        wp_mail($admin_email,$subject,implode("\n",$lines));
    }

    private function next_cron_timestamp(){
        if(function_exists('wp_timezone')){
            $tz=wp_timezone();
            $now=new \DateTime('now',$tz);
            $target=new \DateTime('now',$tz);
            $target->setTime(3,0,0);
            if($target <= $now){
                $target->modify('+1 day');
            }
            return $target->getTimestamp();
        }
        $offset=(float)get_option('gmt_offset',0);
        $now=time();
        $local=$now + ($offset * HOUR_IN_SECONDS);
        $target=strtotime('tomorrow 03:00',$local);
        if(!$target){
            $target=$local + DAY_IN_SECONDS;
        }
        return (int)($target - ($offset * HOUR_IN_SECONDS));
    }
    private function schedule_cron(){
        if(!get_option('evg_nightly_sync_enabled',0)) return;
        if(!wp_next_scheduled(self::CRON_HOOK)){
            wp_schedule_event($this->next_cron_timestamp(),'daily',self::CRON_HOOK);
        }
    }
    private function clear_cron(){
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
    public function maybe_schedule_cron(){
        if(get_option('evg_nightly_sync_enabled',0)){
            $this->schedule_cron();
        } else {
            $this->clear_cron();
        }
    }
    public function handle_nightly_toggle($old_value,$value,$option){
        if((int)$value){
            $this->schedule_cron();
        } else {
            $this->clear_cron();
        }
    }
    public function run_nightly_sync(){
        if(!get_option('evg_nightly_sync_enabled',0)){
            return;
        }
        if(function_exists('wp_doing_cron') && !wp_doing_cron()){
            return;
        }
        $state=get_option('evg_sync_job',[]);
        if(is_array($state) && !empty($state) && empty($state['done'])){
            // another sync is running, skip
            return;
        }
        $sync=new EVG_Sync();
        $sync->job_start(0);
        $max_iterations=300;
        $sync_success=false;
        $error_message='';
        while($max_iterations>0){
            $result=$sync->job_tick();
            if(empty($result['ok'])){
                $error_message=isset($result['summary']) ? (string)$result['summary'] : 'Unbekannter Fehler';
                if(get_option('evg_debug',0)){
                    error_log('EVG nightly sync failed: '.$error_message);
                }
                break;
            }
            if(!empty($result['done'])){
                $sync_success=true;
                break;
            }
            $max_iterations--;
        }
        if($max_iterations<=0 && !$sync_success && $error_message===''){
            $error_message='Abbruch nach maximalen Iterationen';
            if(get_option('evg_debug',0)){
                error_log('EVG nightly sync aborted: '.$error_message);
            }
        }

        $state_final=get_option('evg_sync_job',[]);
        $state_counts=[
            'groups'=>isset($state_final['counts']['groups']) ? (int)$state_final['counts']['groups'] : 0,
            'members_list'=>isset($state_final['counts']['members_list']) ? (int)$state_final['counts']['members_list'] : 0,
            'details'=>isset($state_final['counts']['details']) ? (int)$state_final['counts']['details'] : 0,
            'member_groups'=>isset($state_final['counts']['member_groups']) ? (int)$state_final['counts']['member_groups'] : 0,
            'skip_groups'=>!empty($state_final['skip_groups'])
        ];
        $db_counts=$this->collect_sync_db_counts();
        $this->send_sync_report($sync_success,$db_counts,$state_counts,$error_message);
    }

    public function register_rest_endpoints(){
        register_rest_route(
            'easyverein-go/v1',
            '/members',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this,'rest_get_members'],
                'permission_callback' => function(){
                    return is_user_logged_in();
                },
                'args' => [
                    'per_page' => [
                        'description'       => 'Anzahl der zurückgegebenen Mitglieder pro Seite.',
                        'type'              => 'integer',
                        'default'           => 500,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'description'       => 'Offset für Pagination.',
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'modified_after' => [
                        'description'       => 'Nur Mitglieder, deren Datensatz nach diesem ISO-8601 Zeitstempel aktualisiert wurde.',
                        'type'              => 'string',
                    ],
                ],
            ]
        );
    }

    public function rest_get_members( WP_REST_Request $request ){
        if (!is_user_logged_in()){
            return new WP_Error('evg_rest_auth', __('Authentication required','ev-groups'), ['status'=>401]);
        }
        $user_id   = get_current_user_id();
        $allow_all = (int) get_user_meta($user_id, 'evg_groups_all', true);
        $allowed   = $allow_all ? [] : (array) get_user_meta($user_id, 'evg_groups', true);
        $allowed   = array_values(array_filter(array_map('strval', $allowed)));

        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) { $per_page = 500; }
        if ($per_page > 1000) { $per_page = 1000; }
        $offset = (int) $request->get_param('offset');
        if ($offset < 0) { $offset = 0; }

        $modified_after = null;
        if ($request->get_param('modified_after')) {
            $timestamp = strtotime($request->get_param('modified_after'));
            if ($timestamp === false){
                return new WP_Error('evg_rest_invalid_modified_after', __('Invalid modified_after parameter','ev-groups'), ['status'=>400]);
            }
            $modified_after = gmdate('Y-m-d H:i:s', $timestamp);
        }

        if (!$allow_all && empty($allowed)){
            $response = [
                'data_version' => gmdate('c'),
                'total'        => 0,
                'count'        => 0,
                'members'      => [],
                'groups'       => [],
            ];
            return new WP_REST_Response($response, 200);
        }

        global $wpdb;
        $m_table = $wpdb->prefix.'evg_members';
        $x_table = $wpdb->prefix.'evg_member_groups';
        $g_table = $wpdb->prefix.'evg_groups';

        $joins = " LEFT JOIN {$x_table} x ON x.member_id = m.member_id
                   LEFT JOIN {$g_table} g ON g.group_id = x.group_id";
        $where = [];
        $params = [];

        if ($modified_after){
            $where[] = "m.updated_at >= %s";
            $params[] = $modified_after;
        }
        if (!$allow_all && !empty($allowed)){
            $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
            $where[] = "x.group_id IN ($placeholders)";
            $params = array_merge($params, $allowed);
        }
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_sql = "
            SELECT COUNT(DISTINCT m.member_id)
            FROM {$m_table} m
            {$joins}
            {$where_sql}
        ";
        $count_query = $params ? $wpdb->prepare($count_sql, $params) : $count_sql;
        $total = (int) $wpdb->get_var($count_query);

        $data_sql = "
            SELECT m.*,
                   GROUP_CONCAT(DISTINCT x.group_id ORDER BY x.group_id SEPARATOR '||') AS group_ids,
                   GROUP_CONCAT(DISTINCT COALESCE(NULLIF(g.name,''), x.group_id) ORDER BY x.group_id SEPARATOR '||') AS group_names
            FROM {$m_table} m
            {$joins}
            {$where_sql}
            GROUP BY m.member_id
            ORDER BY m.family_name, m.first_name, m.member_number
            LIMIT %d OFFSET %d
        ";
        $data_params = array_merge($params, [$per_page, $offset]);
        $data_query = $wpdb->prepare($data_sql, $data_params);
        $rows = $wpdb->get_results($data_query, ARRAY_A);

        $members = array_map([$this,'rest_format_member_row'], $rows);

        // Groups dictionary for filter UI
        if ($allow_all) {
            $groups_raw = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM {$g_table} ORDER BY label ASC", ARRAY_A);
        } else {
            $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
            $groups_raw = $wpdb->get_results(
                $wpdb->prepare("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM {$g_table} WHERE group_id IN ({$placeholders}) ORDER BY label ASC", $allowed),
                ARRAY_A
            );
        }
        $groups = [];
        foreach ((array) $groups_raw as $g_row){
            $groups[] = [
                'id'    => (string) $g_row['group_id'],
                'label' => (string) $g_row['label'],
            ];
        }

        $response = [
            'data_version' => gmdate('c'),
            'total'        => $total,
            'count'        => count($members),
            'members'      => $members,
            'groups'       => $groups,
        ];
        return new WP_REST_Response($response, 200);
    }

    private function rest_format_member_row(array $row){
        $group_ids = [];
        $group_names = [];
        if (!empty($row['group_ids'])){
            $group_ids = array_filter(array_map('trim', explode('||', (string) $row['group_ids'])));
        }
        if (!empty($row['group_names'])){
            $group_names = array_filter(array_map('trim', explode('||', (string) $row['group_names'])));
        }
        $groups = [];
        $max = max(count($group_ids), count($group_names));
        for ($i=0; $i < $max; $i++){
            $gid = isset($group_ids[$i]) ? $group_ids[$i] : (isset($group_names[$i]) ? $group_names[$i] : null);
            if (!$gid) { continue; }
            $glabel = isset($group_names[$i]) && $group_names[$i] !== '' ? $group_names[$i] : $gid;
            $groups[] = [
                'id'    => (string) $gid,
                'label' => (string) $glabel,
            ];
        }

        $phones = [];
        if (!empty($row['phone'])){
            $parts = preg_split('/[,;]+/', (string) $row['phone']);
            $phones = array_values(array_filter(array_map('trim', (array) $parts)));
        }

        $birth_year = null;
        if (isset($row['birth_year']) && $row['birth_year'] !== ''){
            $birth_year = (int) $row['birth_year'];
        }

        $age = null;
        if (isset($row['age']) && $row['age'] !== ''){
            $age = (int) $row['age'];
        }

        $updated_at = null;
        if (!empty($row['updated_at'])){
            $timestamp = strtotime($row['updated_at'].' UTC');
            if ($timestamp !== false){
                $updated_at = gmdate('c', $timestamp);
            }
        }

        $salutation = '';
        if (!empty($row['gender'])){
            $salutation = trim((string) $row['gender']);
        }

        return [
            'member_id'     => (string) $row['member_id'],
            'member_number' => isset($row['member_number']) ? (string) $row['member_number'] : '',
            'full_name'     => trim(((string) ($row['first_name'] ?? '')).' '.((string) ($row['family_name'] ?? ''))),
            'first_name'    => isset($row['first_name']) ? (string) $row['first_name'] : '',
            'family_name'   => isset($row['family_name']) ? (string) $row['family_name'] : '',
            'date_of_birth' => isset($row['date_of_birth']) ? (string) $row['date_of_birth'] : '',
            'birth_year'    => $birth_year,
            'age'           => $age,
            'salutation'    => $salutation,
            'email_private' => isset($row['email_private']) ? (string) $row['email_private'] : '',
            'phones'        => $phones,
            'zip'           => isset($row['zip']) ? (string) $row['zip'] : '',
            'city'          => isset($row['city']) ? (string) $row['city'] : '',
            'street'        => isset($row['street']) ? (string) $row['street'] : '',
            'address_suffix'=> isset($row['address_suffix']) ? (string) $row['address_suffix'] : '',
            'groups'        => $groups,
            'updated_at'    => $updated_at,
        ];
    }
}
new EVG_Plugin();
