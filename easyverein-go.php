<?php
/**
 * Plugin Name: Easyverein Go
 * Description: Mitglieder-Sync + lokales Frontend. Speichert und nutzt den contact-details Link pro Mitglied. Benutzerbezogene Gruppenfreigabe.
 * Version: 2.1.1
 * Author: Tilmann Laux
 * Text Domain: ev-groups
 */
if (!defined('ABSPATH')) { exit; }

define('EVG_VERSION','2.1.1');
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
            'evg_columns'      => ['full_name','email_private','date_of_birth','age','birth_year','gender','phone','zip','city','street','address_suffix','member_number','groups'],
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
        while($max_iterations>0){
            $result=$sync->job_tick();
            if(empty($result['ok'])){
                if(get_option('evg_debug',0)){
                    error_log('EVG nightly sync failed: '.(isset($result['summary'])?$result['summary']:'unknown error'));
                }
                break;
            }
            if(!empty($result['done'])){
                break;
            }
            $max_iterations--;
        }
    }
}
new EVG_Plugin();
