<?php
/** Explizite Trainingsgruppen-Zuordnung; EasyVerein bleibt führend für Termine und Sichtbarkeit. */
if (!defined('ABSPATH')) exit;
class EVG_Training_Calendars {
    const OPTION = 'evg_training_calendar_map';
    public function __construct() {
        add_action('admin_menu',[$this,'menu']);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('admin_post_evg_training_calendars',[$this,'save_settings']);
    }
    public function menu() {
        add_submenu_page(EVG_SLUG,'Trainingskalender','Trainingskalender','manage_options','evg-training-calendars',[$this,'page']);
    }
    public function assets($hook) {
        if ($hook!=='easyverein-go_page_evg-training-calendars') return;
        wp_enqueue_script('evg-training-calendars',EVG_URL.'assets/admin/evg-training-calendars.js',[],EVG_VERSION,true);
        wp_enqueue_style('evg-training-calendars',EVG_URL.'assets/admin/evg-training-calendars.css',[],EVG_VERSION);
    }
    /** Nur vorhandene Gruppen und Kalender sind wirksame Zuordnungen, auch nach einem Sync. */
    public static function normalize($input) {
        global $wpdb;
        if (!is_array($input)) return [];
        $groups=array_map('strval',(array)$wpdb->get_col("SELECT group_id FROM {$wpdb->prefix}evg_groups"));
        $calendars=array_map('strval',(array)$wpdb->get_col("SELECT id FROM {$wpdb->prefix}evg_calendars"));
        $map=[];
        foreach ($input as $group=>$ids) {
            if (!in_array((string)$group,$groups,true) || !is_array($ids)) continue;
            $valid=[];
            foreach ($ids as $id) {
                if ((is_string($id) || is_int($id)) && in_array((string)$id,$calendars,true)) $valid[]=(string)$id;
            }
            if ($valid) $map[(string)$group]=array_values(array_unique($valid));
        }
        return $map;
    }
    public static function mappings() { return self::normalize(get_option(self::OPTION,[])); }
    /** Gemeinsamer, testbarer Admin-Schreibweg einschließlich Berechtigung und CSRF-Prüfung. */
    public function save_mapping(array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('evg_calendar_forbidden','Keine Berechtigung.',['status'=>403]);
        if (!isset($input['_wpnonce']) || !is_string($input['_wpnonce']) || !wp_verify_nonce($input['_wpnonce'],'evg_training_calendars')) return new WP_Error('evg_calendar_nonce','Die Sicherheitsprüfung ist abgelaufen. Bitte die Seite neu laden.',['status'=>403]);
        $map=self::normalize($input['mapping']??[]);
        update_option(self::OPTION,$map,false);
        if (get_option(self::OPTION,[])!==$map) return new WP_Error('evg_calendar_storage','Die Zuordnung konnte nicht gespeichert werden. Bitte erneut versuchen.',['status'=>500]);
        return $map;
    }
    public function save_settings() {
        $result=$this->save_mapping(wp_unslash($_POST));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'', ['response'=>$result->get_error_data()['status']??500]);
        wp_safe_redirect(admin_url('admin.php?page=evg-training-calendars&saved=1')); exit;
    }
    public function page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $groups=(array)$wpdb->get_results("SELECT group_id,name FROM {$wpdb->prefix}evg_groups ORDER BY name",ARRAY_A);
        $calendars=(array)$wpdb->get_results("SELECT id,name,allowed_groups FROM {$wpdb->prefix}evg_calendars ORDER BY name",ARRAY_A);
        $map=self::mappings();
        $filter=array_filter(array_map('trim',explode(',',(string)get_option('evg_events_calendar_filter',''))));
        echo '<div class="wrap" data-evg-training-calendars><h1>Trainingskalender</h1><p>Ordne Trainingsgruppen ausdrücklich ihre EasyVerein-Kalender zu. Übungsleiter mit verknüpftem Vereinsmitglied dürfen in den zugeordneten Kalendern ihrer Gruppen Termine anlegen. Administratoren dürfen alle synchronisierten Kalender verwenden.</p><p>Die Kalender-Sichtbarkeit wird weiterhin in EasyVerein gepflegt. Eine Zuordnung öffnet keinen dort gesperrten Kalender. Der Synchronisationsfilter gilt zusätzlich. Ohne Zuordnung entstehen keine Trainerrechte aus einer reinen Sichtbarkeitsfreigabe.</p>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success inline"><p>Kalenderzuordnungen gespeichert.</p></div>';
        if (!$groups || !$calendars) echo '<div class="notice notice-warning inline"><p>Bitte zuerst Gruppen und Kalender aus EasyVerein synchronisieren.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="evg_training_calendars">';
        wp_nonce_field('evg_training_calendars');
        echo '<p data-evg-group-search-control hidden><label>Trainingsgruppe suchen <input type="search" data-evg-group-search placeholder="Gruppenname oder ID …"></label></p><table class="widefat striped"><thead><tr><th>Trainingsgruppe</th><th>Zugeordnete EasyVerein-Kalender</th></tr></thead><tbody>';
        foreach ($groups as $group) {
            $id=(string)$group['group_id']; $name=$group['name'] ?: $id; $selected=$map[$id]??[];
            echo '<tr data-evg-training-group data-group-search="'.esc_attr($name.' '.$id).'"><td><strong>'.esc_html($name).'</strong><br><small>ID '.esc_html($id).'</small></td><td><div data-evg-calendar-chips class="evg-calendar-chips" hidden></div><details class="evg-calendar-picker" data-evg-calendar-picker><summary>Kalender auswählen / ändern ('.count($selected).')</summary><label data-evg-calendar-search-control hidden>Kalender suchen <input type="search" data-evg-calendar-search placeholder="Name oder ID …"></label><div class="evg-calendar-options">';
            foreach ($calendars as $calendar) {
                $cal_id=(string)$calendar['id'];
                $limited=$calendar['allowed_groups']!=='' && !in_array($id,explode(',',$calendar['allowed_groups']),true);
                $excluded=$filter && !in_array($cal_id,$filter,true);
                echo '<label data-evg-calendar-option data-calendar-search="'.esc_attr($calendar['name'].' '.$cal_id).'"><input type="checkbox" name="mapping['.esc_attr($id).'][]" value="'.esc_attr($cal_id).'" data-calendar-name="'.esc_attr($calendar['name']).'" '.checked(in_array($cal_id,$selected,true),true,false).'> '.esc_html($calendar['name']).' <small>(ID '.esc_html($cal_id).')</small>';
                if ($limited) echo '<small class="evg-calendar-hint">Sichtbarkeit in EasyVerein ist auf andere Gruppen beschränkt. Diese Gruppe allein erhält dadurch noch keinen Zugriff.</small>';
                if ($excluded) echo '<small class="evg-calendar-hint">Vom Synchronisationsfilter ausgeschlossen; aktuell kein Schreibziel.</small>';
                echo '</label>';
            }
            echo '</div><p data-evg-calendar-empty hidden>Kein passender Kalender gefunden.</p></details></td></tr>';
        }
        echo '</tbody></table><p data-evg-group-empty hidden>Keine passende Trainingsgruppe gefunden.</p><p>Änderungen werden erst mit „Zuordnungen speichern“ übernommen. Ausgeblendete Suchergebnisse bleiben ausgewählt.</p>';
        submit_button('Zuordnungen speichern');
        echo '</form></div>';
    }
}
