<?php
/**
 * Plugin Name: Easyverein Go
 * Description: Mitglieder-Sync + lokales Frontend. Speichert und nutzt den contact-details Link pro Mitglied. Benutzerbezogene Gruppenfreigabe.
 * Version: 3.2.7
 * Author: Tilmann Laux
 * Text Domain: ev-groups
 */
if (!defined('ABSPATH')) { exit; }

define('EVG_VERSION','3.2.7');
define('EVG_SLUG','easyverein-go');
define('EVG_PATH', plugin_dir_path(__FILE__));
define('EVG_URL', plugin_dir_url(__FILE__));

require_once EVG_PATH.'includes/evg-utils.php';
require_once EVG_PATH.'includes/class-evg-admin.php';
require_once EVG_PATH.'includes/class-evg-sync.php';
require_once EVG_PATH.'includes/class-evg-frontend.php';
require_once EVG_PATH.'includes/class-evg-api.php';

class EVG_Plugin {
    private const CRON_HOOK = 'evg_nightly_sync';
    private const NIGHTLY_TABLE_PREFIX = 'evg_nightly';

    private static $instance = null;

    public function __construct(){
        self::$instance = $this;
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
            'evg_groups_path'  => '/api/v3.0/member-group',
            'evg_members_path' => '/api/v3.0/member',
            'evg_contact_details_path' => '/api/v3.0/contact-details/{id}',
            'evg_member_groups_path'   => '/api/v3.0/member-group-assignment?user_object={id}',
            'evg_custom_fields_path'   => '/api/v3.0/custom-field',
            'evg_member_custom_fields_path' => '/api/v3.0/member-custom-field-assignment?user_object={id}',
            'evg_columns'      => ['first_name','family_name','email_private','date_of_birth','age','birth_year','gender','phone','zip','city','street','groups'],
            'evg_debug'        => 1,
            'evg_sync_next_pages_max' => 100,
            'evg_sync_rate_per_sec'   => 5,
            'evg_sync_calls_per_tick' => 5,
            'evg_tick_pause_ms'       => 900,
            'evg_nightly_sync_enabled'=> 0,
            'evg_manual_sync_table_prefix'=> 'evg',
            'evg_nightly_sync_table_prefix'=> self::NIGHTLY_TABLE_PREFIX,
            'evg_sync_report_email' => ''
        ];
        foreach($defaults as $k=>$v){ if (null===get_option($k,null)) update_option($k,$v); }
        $this->ensure_tables_for_prefix('evg');
        if(self::NIGHTLY_TABLE_PREFIX!=='evg'){
            $this->ensure_tables_for_prefix(self::NIGHTLY_TABLE_PREFIX);
        }
        EVG_Api::ensure_change_requests_table();
    }
    public function on_deactivate(){
        $this->clear_cron();
    }
    private function migrate_api_paths_to_v3(){
        $map = [
            'evg_groups_path'               => ['/api/v2.0/member-group'                => '/api/v3.0/member-group'],
            'evg_members_path'              => ['/api/v2.0/member'                       => '/api/v3.0/member'],
            'evg_contact_details_path'      => ['/api/v2.0/contact-details/{id}'         => '/api/v3.0/contact-details/{id}'],
            'evg_custom_fields_path'        => ['/api/v2.0/custom-field'                 => '/api/v3.0/custom-field'],
            'evg_member_custom_fields_path' => ['/api/v2.0/member/{id}/custom-fields'    => '/api/v3.0/member-custom-field-assignment?user_object={id}'],
            'evg_member_groups_path'        => ['/api/v2.0/member/{id}/groups'           => '/api/v3.0/member-group-assignment?user_object={id}'],
        ];
        foreach ($map as $option => $replacements) {
            $current = get_option($option, null);
            if (!is_string($current)) continue;
            foreach ($replacements as $old => $new) {
                if ($current === $old) {
                    update_option($option, $new);
                    break;
                }
            }
        }
    }

    private function ensure_runtime_option_defaults(){
        $defaults = [
            'evg_custom_fields_path'         => '/api/v3.0/custom-field',
            'evg_member_custom_fields_path'  => '/api/v3.0/member-custom-field-assignment?user_object={id}',
            'evg_manual_sync_table_prefix'   => 'evg',
            'evg_nightly_sync_table_prefix'  => self::NIGHTLY_TABLE_PREFIX,
        ];
        foreach ($defaults as $key => $value) {
            $current = get_option($key, null);
            if ($current === null || $current === '') {
                update_option($key, $value);
                continue;
            }
            if (!is_string($current)) {
                update_option($key, $value);
                continue;
            }
            $normalized = $current;
            if (strpos($normalized, 'custom-field?kind=E') !== false) {
                $normalized = '/api/v3.0/custom-field';
            }
            if (strpos($normalized, 'custom-fields?limit=100') !== false) {
                $normalized = '/api/v3.0/member-custom-field-assignment?user_object={id}';
            }
            if (strpos($normalized, 'https://easyverein.com/api/v2.0/custom-field') === 0) {
                $normalized = '/api/v3.0/custom-field';
            }
            if (strpos($normalized, 'https://easyverein.com/api/v2.0/member/') === 0) {
                $normalized = '/api/v3.0/member-custom-field-assignment?user_object={id}';
            }
            if ($key === 'evg_manual_sync_table_prefix' || $key === 'evg_nightly_sync_table_prefix') {
                $normalized = EVG_Sync::sanitize_table_prefix($normalized);
                if ($normalized === '') {
                    $normalized = ($key === 'evg_manual_sync_table_prefix') ? 'evg' : self::NIGHTLY_TABLE_PREFIX;
                }
            }
            if ($normalized !== $current) {
                update_option($key, $normalized);
            }
        }
    }

    private function table_name_for_prefix($prefix,$suffix){
        global $wpdb;
        return $wpdb->prefix.EVG_Sync::sanitize_table_prefix($prefix).'_'.$suffix;
    }

    private function get_schema_statements($prefix){
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $prefix = EVG_Sync::sanitize_table_prefix($prefix);
        $base = $wpdb->prefix.$prefix.'_';
        $charset = $wpdb->get_charset_collate();

        return [
            "CREATE TABLE {$base}groups (
                group_id varchar(191) NOT NULL,
                name varchar(255) DEFAULT '',
                short varchar(120) DEFAULT '',
                updated_at datetime NULL,
                raw longtext,
                PRIMARY KEY  (group_id),
                KEY idx_name (name(191))
            ) {$charset};",
            "CREATE TABLE {$base}members (
                member_id varchar(191) NOT NULL,
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
                PRIMARY KEY  (member_id),
                KEY idx_member_number (member_number)
            ) {$charset};",
            "CREATE TABLE {$base}member_groups (
                member_id varchar(191) NOT NULL,
                group_id varchar(191) NOT NULL,
                member_name varchar(255) DEFAULT '',
                group_name varchar(255) DEFAULT '',
                assigned_at datetime NULL,
                PRIMARY KEY  (member_id,group_id),
                KEY idx_group (group_id),
                KEY idx_member_name (member_name(191)),
                KEY idx_group_name (group_name(191))
            ) {$charset};",
            "CREATE TABLE {$base}custom_fields (
                field_id varchar(191) NOT NULL,
                name varchar(255) DEFAULT '',
                settings_type varchar(32) DEFAULT '',
                kind varchar(32) DEFAULT '',
                member_show tinyint(1) DEFAULT 0,
                member_edit tinyint(1) DEFAULT 0,
                position int DEFAULT 0,
                collection varchar(191) DEFAULT '',
                updated_at datetime NULL,
                raw longtext,
                PRIMARY KEY  (field_id)
            ) {$charset};",
            "CREATE TABLE {$base}member_custom_fields (
                member_id varchar(191) NOT NULL,
                field_id varchar(191) NOT NULL,
                value_hash varchar(64) DEFAULT '',
                value_text longtext,
                updated_at datetime NULL,
                raw longtext,
                PRIMARY KEY  (member_id,field_id),
                KEY idx_field (field_id),
                KEY idx_value_hash (value_hash)
            ) {$charset};",
            "CREATE TABLE {$base}custom_field_values (
                field_id varchar(191) NOT NULL,
                value_hash varchar(64) NOT NULL,
                field_label varchar(255) DEFAULT '',
                value_label varchar(255) DEFAULT '',
                value_raw longtext,
                updated_at datetime NULL,
                PRIMARY KEY  (field_id, value_hash),
                KEY idx_field_label (field_id, value_label)
            ) {$charset};"
        ];
    }

    private function ensure_tables_for_prefix($prefix){
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        foreach ($this->get_schema_statements($prefix) as $statement) {
            dbDelta($statement);
        }
    }

    public static function ensure_schema_for_prefix($prefix){
        if (self::$instance instanceof self){
            self::$instance->ensure_tables_for_prefix($prefix);
        }
    }
    public function init(){
        $this->ensure_tables_for_prefix('evg');
        $manual_prefix = EVG_Sync::sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg'));
        if ($manual_prefix !== '' && $manual_prefix !== 'evg'){
            $this->ensure_tables_for_prefix($manual_prefix);
        }
        $nightly_prefix = EVG_Sync::sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix',self::NIGHTLY_TABLE_PREFIX));
        if ($nightly_prefix !== '' && $nightly_prefix !== 'evg' && $nightly_prefix !== $manual_prefix){
            $this->ensure_tables_for_prefix($nightly_prefix);
        }
        $this->migrate_api_paths_to_v3();
        $this->ensure_runtime_option_defaults();
        new EVG_Admin();
        new EVG_Sync();
        new EVG_Frontend();
        new EVG_Api();
        add_action('init',[$this,'maybe_schedule_cron']);
        add_action('rest_api_init',[$this,'register_rest_endpoints']);
    }

    private function collect_sync_db_counts($prefix='evg'){
        global $wpdb;
        $counts = [
            'groups'               => 0,
            'members'              => 0,
            'members_with_contact' => 0,
            'member_groups'        => 0,
            'custom_fields'        => 0,
            'member_custom_fields' => 0,
            'custom_field_values'  => 0,
        ];
        if(!isset($wpdb) || !is_object($wpdb)){
            return $counts;
        }

        $prefix = EVG_Sync::sanitize_table_prefix($prefix);
        $table_map = [
            'groups'               => 'groups',
            'members'              => 'members',
            'member_groups'        => 'member_groups',
            'custom_fields'        => 'custom_fields',
            'member_custom_fields' => 'member_custom_fields',
            'custom_field_values'  => 'custom_field_values',
        ];

        foreach ($table_map as $key => $suffix){
            $table = $this->table_name_for_prefix($prefix, $suffix);
            $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        $members_table = $this->table_name_for_prefix($prefix, 'members');
        $counts['members_with_contact'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$members_table} WHERE contact_details IS NOT NULL AND contact_details <> ''");

        return $counts;
    }

    private function send_sync_report($success,array $db_counts,array $state_counts,$error_message='',$tick_log=array(),$table_prefix='evg',$extra=[]){
        $report_email = trim((string) get_option('evg_sync_report_email',''));
        if ($report_email === '' || !is_email($report_email)){
            $report_email = get_option('admin_email');
        }
        if(!$report_email || !is_email($report_email)){
            return;
        }
        $site_name=wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES);
        if(empty($site_name)){
            $site_name='WordPress';
        }

        $skipped = !empty($extra['skipped']);
        if($skipped){
            $subject=sprintf('[%s] Easyverein Sync übersprungen',$site_name);
        } else {
            $subject=sprintf('[%s] Easyverein Sync %s',$site_name,$success?'erfolgreich':'fehlgeschlagen');
        }

        $timestamp=current_time('timestamp');
        $formatted_time=date_i18n('d.m.Y H:i',$timestamp);

        $lines=[];
        if($skipped){
            $lines[]='Der nächtliche Easyverein Sync wurde übersprungen.';
            $lines[]='';
            $lines[]='Grund: '.$error_message;
            $lines[]='';
            $lines[]='Zeitpunkt: '.$formatted_time;
            $lines[]='';
            $lines[]='Tipp: Der verklemmte State wird beim nächsten Cron-Lauf nach 3h Wartezeit';
            $lines[]='automatisch zurückgesetzt. Manueller Reset: im Backend "Jetzt synchronisieren" klicken.';
            wp_mail($report_email,$subject,implode("\n",$lines));
            return;
        }

        $lines[]=$success
            ? 'Der nächtliche Easyverein Sync wurde erfolgreich abgeschlossen.'
            : 'Der nächtliche Easyverein Sync wurde NICHT erfolgreich abgeschlossen.';

        if(!empty($extra['stuck_info'])){
            $lines[]='';
            $lines[]='[!] '.$extra['stuck_info'];
        }
        if(!$success && !empty($error_message)){
            $lines[]='';
            $lines[]='Fehlerursache: '.$error_message;
        }
        if(!$success && !empty($extra['last_phase'])){
            $lines[]='Zuletzt erreichte Phase: '.$extra['last_phase'];
        }
        if(!$success && !empty($extra['wpdb_error'])){
            $lines[]='Letzter DB-Fehler: '.$extra['wpdb_error'];
        }
        $lines[]='';
        $lines[]='Zeitpunkt: '.$formatted_time;

        if(!empty($extra)){
            $lines[]='';
            $lines[]='=== Lauf-Diagnose ===';
            if(isset($extra['elapsed'])){
                $lines[]='Laufzeit: '.number_format((float)$extra['elapsed'],1,'.',',').'s ('.gmdate('H:i:s',(int)$extra['elapsed']).')';}
            if(isset($extra['iterations'])){
                $lines[]='Tick-Iterationen: '.(int)$extra['iterations'];
            }
            if(isset($extra['peak_memory'])){
                $lines[]='Peak-Speicher: '.round((int)$extra['peak_memory']/1024/1024,1).' MB';
            }
            if(isset($extra['php_version'])){
                $lines[]='PHP: '.$extra['php_version']
                    .' | memory_limit: '.($extra['memory_limit']??'?')
                    .' | max_execution_time: '.($extra['max_exec_time']??'?').'s (gesetzt auf 0)';
            }
            if(isset($extra['wp_version'])){
                $lines[]='WordPress: '.$extra['wp_version'];
            }
            if(!empty($extra['next_cron'])){
                $lines[]='Nächster Cron-Lauf: '.date_i18n('d.m.Y H:i',(int)$extra['next_cron']);
            }
        }

        $lines[]='';
        $lines[]='=== Datenbankstände ('.EVG_Sync::sanitize_table_prefix($table_prefix).') ===';
        if(!empty($db_counts)){
            $lines[]='- Gruppen: '.(int)$db_counts['groups'];
            $lines[]='- Mitglieder gesamt: '.(int)$db_counts['members'];
            $lines[]='- Mitglieder mit Kontakt-Details: '.(int)$db_counts['members_with_contact'];
            $lines[]='- Gruppen-Zuordnungen: '.(int)$db_counts['member_groups'];
            $lines[]='- Custom Fields: '.(int)$db_counts['custom_fields'];
            $lines[]='- Custom-Field-Werte: '.(int)$db_counts['member_custom_fields'];
            $lines[]='- Custom-Field-Optionen: '.(int)$db_counts['custom_field_values'];
        } else {
            $lines[]='(keine Daten — Sync wurde möglicherweise nicht gestartet)';
        }
        if(EVG_Sync::sanitize_table_prefix($table_prefix)!=='evg'){
            $lines[]='Hinweis: Test-Tabellen (Präfix "'.EVG_Sync::sanitize_table_prefix($table_prefix).'" ), nicht die produktiven Tabellen.';
        }

        $state_sum=0;
        foreach(['groups','custom_fields','custom_field_values','members_list','details','member_custom_fields','member_groups'] as $sk){
            $state_sum+=isset($state_counts[$sk]) ? (int)$state_counts[$sk] : 0;
        }
        if($state_sum>0){
            $lines[]='';
            $lines[]='=== API-Zähler ===';
            $lines[]='- Gruppen abgeholt: '.(int)$state_counts['groups'];
            $lines[]='- Custom Fields geladen: '.(int)$state_counts['custom_fields'];
            $lines[]='- Custom-Field-Optionen gespeichert: '.(int)$state_counts['custom_field_values'];
            $lines[]='- Mitglieder gelistet: '.(int)$state_counts['members_list'];
            $lines[]='- Kontakt-Details geladen: '.(int)$state_counts['details'];
            $lines[]='- Custom-Field-Werte importiert: '.(int)$state_counts['member_custom_fields'];
            $lines[]='- Gruppen-Zuordnungen importiert: '.(int)$state_counts['member_groups'];
        }
        if(!empty($state_counts['skip_groups'])){
            $lines[]='';
            $lines[]='Hinweis: Gruppen-Import war für diese Ausführung deaktiviert.';
        }
        if(!$success){
            $lines[]='';
            $lines[]='Tipp: Debug-Modus in den Plugin-Einstellungen aktivieren.';
            $lines[]='Protokolldateien: wp-content/easyverein-debug/';
        }
        if(!empty($tick_log)){
            $lines[]='';
            $lines[]='=== Tick-Log ('.count($tick_log).' Einträge) ===';
            foreach($tick_log as $entry){
                $lines[]='  '.$entry;
            }
        }

        wp_mail($report_email,$subject,implode("\n",$lines));
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
    private function append_nightly_log($log_file, $message){
        $dir = dirname($log_file);
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        @file_put_contents($log_file, '['.date('H:i:s').'] '.$message."\n", FILE_APPEND | LOCK_EX);
    }

    public function run_nightly_sync(){
        if(!get_option('evg_nightly_sync_enabled',0)){
            return;
        }
        if(function_exists('wp_doing_cron') && !wp_doing_cron()){
            return;
        }

        // Zeitlimit aufheben (WP-Cron läuft ggf. unter kurzem PHP-Limit)
        if(function_exists('set_time_limit')){
            @set_time_limit(0);
        }

        $start_time = microtime(true);

        $raw_prefix=get_option('evg_nightly_sync_table_prefix',self::NIGHTLY_TABLE_PREFIX);
        $raw_prefix=apply_filters('evg_nightly_sync_table_prefix',$raw_prefix);
        $nightly_prefix=EVG_Sync::sanitize_table_prefix($raw_prefix);
        $this->ensure_tables_for_prefix($nightly_prefix);
        $sync=new EVG_Sync($nightly_prefix);

        $log_dir  = WP_CONTENT_DIR.'/easyverein-debug';
        $log_file = $log_dir.'/nightly-'.date('Ymd-His').'.log';

        // Stuck-State-Erkennung: verhindert dauerhaftes Blockieren bei Fehlern
        $state=get_option($sync->get_state_option_key(),[]);
        $stuck_info = '';
        if(is_array($state) && !empty($state) && empty($state['done'])){
            $started_at = isset($state['started_at']) ? (int)$state['started_at'] : 0;
            if($started_at > 0 && (time() - $started_at) < 3 * HOUR_IN_SECONDS){
                // Tatsächlich noch laufend (gestartet vor weniger als 3 Stunden)
                $since = date_i18n('d.m.Y H:i', $started_at);
                $this->send_sync_report(
                    false, [], [],
                    'Sync läuft noch (gestartet: '.$since.'). Neuer Start wird übersprungen.',
                    [], $nightly_prefix, ['skipped' => true]
                );
                return;
            }
            // Verklemmt — State war älter als 3 Stunden, wird zurückgesetzt
            $since_label = $started_at ? date_i18n('d.m.Y H:i', $started_at) : 'unbekanntem Zeitpunkt';
            $stuck_info = 'Verklemmter Sync-State von '.$since_label.' wurde automatisch zurückgesetzt.';
        }

        $sync->job_start(0);
        $this->append_nightly_log($log_file, 'START EVG '.EVG_VERSION.' | PHP '.PHP_VERSION
            .' | memory_limit='.ini_get('memory_limit').' | prefix='.$nightly_prefix);
        if ($stuck_info !== '') {
            $this->append_nightly_log($log_file, 'HINWEIS: '.$stuck_info);
        }

        // Startzeitstempel in State schreiben (für künftige Stuck-State-Erkennung)
        $st = get_option($sync->get_state_option_key(), []);
        if(is_array($st)){
            $st['started_at'] = time();
            update_option($sync->get_state_option_key(), $st, false);
        }

        $max_iterations = (int) apply_filters('evg_nightly_sync_iteration_limit', 20000);
        $time_budget    = (int) apply_filters('evg_nightly_sync_time_budget', 60 * MINUTE_IN_SECONDS);
        if ($max_iterations < 100) { $max_iterations = 100; }
        if ($time_budget < 60) { $time_budget = 60; }
        $deadline = microtime(true) + $time_budget;
        $sync_success=false;
        $error_message='';
        $iteration=0;
        $tick_log = [];
        $last_phase_logged = '';
        while(true){
            $result=$sync->job_tick();
            $iteration++;
            $cur_label = isset($result['label']) ? (string)$result['label'] : '';
            // Log every tick to file; detect phase change for email summary
            $this->append_nightly_log($log_file, sprintf('#%d %.1f%% %s', $iteration, floatval($result['percent'] ?? 0), $cur_label));
            $cur_phase = ($cur_label !== '') ? strtok($cur_label, ' ') : '';
            if ($cur_phase !== $last_phase_logged) {
                $last_phase_logged = $cur_phase;
                $tick_log[] = sprintf('#%d %s', $iteration, $cur_label);
            }
            if(empty($result['ok'])){
                $error_message=isset($result['summary']) ? (string)$result['summary'] : 'Unbekannter Fehler';
                $this->append_nightly_log($log_file, 'ERROR: '.$error_message);
                break;
            }
            if(!empty($result['done'])){
                $sync_success=true;
                break;
            }
            $max_iterations--;
            if($max_iterations<=0){
                $error_message='Abbruch nach Iterationslimit ('.apply_filters('evg_nightly_sync_iteration_limit',20000).' Ticks)';
                $this->append_nightly_log($log_file, 'ABBRUCH: '.$error_message);
                break;
            }
            if(microtime(true) >= $deadline){
                $error_message='Abbruch nach Zeitlimit ('.round($time_budget/60).' Minuten)';
                $this->append_nightly_log($log_file, 'ABBRUCH: '.$error_message);
                break;
            }
        }

        $elapsed = microtime(true) - $start_time;
        if ($sync_success) {
            update_option('evg_last_sync_completed', current_time('mysql', 1), false);
            $this->append_nightly_log($log_file, sprintf('FERTIG in %.1fs | %d Iterationen', $elapsed, $iteration));
        } else {
            $this->append_nightly_log($log_file, sprintf('FEHLGESCHLAGEN nach %.1fs | %d Iterationen', $elapsed, $iteration));
        }

        $state_final=get_option($sync->get_state_option_key(),[]);
        $state_counts=[
            'groups'=>isset($state_final['counts']['groups']) ? (int)$state_final['counts']['groups'] : 0,
            'custom_fields'=>isset($state_final['counts']['custom_fields']) ? (int)$state_final['counts']['custom_fields'] : 0,
            'custom_field_values'=>isset($state_final['counts']['custom_field_values']) ? (int)$state_final['counts']['custom_field_values'] : 0,
            'members_list'=>isset($state_final['counts']['members_list']) ? (int)$state_final['counts']['members_list'] : 0,
            'details'=>isset($state_final['counts']['details']) ? (int)$state_final['counts']['details'] : 0,
            'member_custom_fields'=>isset($state_final['counts']['member_custom_fields']) ? (int)$state_final['counts']['member_custom_fields'] : 0,
            'member_groups'=>isset($state_final['counts']['member_groups']) ? (int)$state_final['counts']['member_groups'] : 0,
            'skip_groups'=>!empty($state_final['skip_groups'])
        ];
        $db_counts=$this->collect_sync_db_counts($nightly_prefix);

        global $wpdb;
        $extra = [
            'elapsed'      => $elapsed,
            'iterations'   => $iteration,
            'peak_memory'  => memory_get_peak_usage(true),
            'php_version'  => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_exec_time'=> ini_get('max_execution_time'),
            'wp_version'   => get_bloginfo('version'),
            'next_cron'    => wp_next_scheduled(self::CRON_HOOK),
            'stuck_info'   => $stuck_info,
            'last_phase'   => isset($state_final['phase']) ? $state_final['phase'] : '',
            'wpdb_error'   => $wpdb->last_error,
        ];
        $this->send_sync_report($sync_success,$db_counts,$state_counts,$error_message,$tick_log,$nightly_prefix,$extra);
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
