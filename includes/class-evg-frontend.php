<?php
if (!defined('ABSPATH')) { exit; }

class EVG_Frontend {
    // Default order keeps the table slim: split names, omit optional address/member id extras.
    private const DEFAULT_COLUMNS = [
        'first_name',
        'family_name',
        'email_private',
        'date_of_birth',
        'age',
        'birth_year',
        'gender',
        'phone',
        'zip',
        'city',
        'street',
        'groups',
        'custom_fields'
    ];

    private const COLUMN_LABELS = [
        'full_name'      => 'Name',
        'first_name'     => 'Vorname',
        'family_name'    => 'Nachname',
        'email_private'  => 'E-Mail',
        'date_of_birth'  => 'Geburtsdatum',
        'age'            => 'Alter',
        'birth_year'     => 'Jahrgang',
        'gender'         => 'Geschlecht',
        'phone'          => 'Telefon',
        'zip'            => 'PLZ',
        'city'           => 'Ort',
        'street'         => 'Straße',
        'address_suffix' => 'Adresszusatz',
        'group_name'     => 'Gruppe',
        'groups'         => 'Gruppen',
        'custom_fields'  => 'Merkmale',
        'member_number'  => 'Mitgliedsnummer',
        'contact_details'=> 'Kontakt-Details'
    ];

    public function __construct(){
        add_shortcode('easyverein_table',[$this,'shortcode']);
        add_action('wp_enqueue_scripts',[$this,'enqueue']);
        add_action('wp_ajax_evg_fetch_local',[$this,'ajax_fetch_local']);
        add_action('wp_ajax_nopriv_evg_fetch_local',[$this,'ajax_fetch_local']);
    }
    public function enqueue(){
        wp_register_style('evg-style', EVG_URL.'assets/css/ev-frontend.css', [], EVG_VERSION);
        wp_register_script('evg-script', EVG_URL.'assets/js/ev-frontend.js', ['jquery'], EVG_VERSION, true);
        wp_localize_script('evg-script','EVG', array(
            'ajax'          => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('evg_local'),
            'columnsDefault'=> $this->get_default_columns(),
            'columnLabels'  => $this->get_column_labels()
        ));
    }
    public function shortcode($atts=array()){
        if(!is_user_logged_in()){
            return '<div class="evg-notice">'.esc_html__('Bitte einloggen, um die Tabelle zu sehen.','ev-groups').'</div>';
        }
        $atts = shortcode_atts(array('columns'=>''), $atts, 'easyverein_table');
        $cols = $this->resolve_columns($atts['columns']);
        wp_enqueue_style('evg-style'); wp_enqueue_script('evg-script');
        ob_start(); ?>
        <div class="evg-wrap" data-columns="<?php echo esc_attr( wp_json_encode($cols) ); ?>">
            <div class="evg-toolbar">
                <input type="text" class="evg-search" placeholder="<?php echo esc_attr__('Suchen…','ev-groups'); ?>" />
                <select class="evg-group-filter"><option value=""><?php echo esc_html__('Alle Gruppen','ev-groups'); ?></option></select>
                <select class="evg-custom-filter" style="display:none;"><option value=""><?php echo esc_html__('Alle Merkmale','ev-groups'); ?></option></select>
                <button type="button" class="button evg-export"><?php echo esc_html__('CSV exportieren','ev-groups'); ?></button>
            </div>
            <div class="evg-meta">
                <div class="evg-count"><span class="evg-count-current">0</span> / <span class="evg-count-total">0</span> <?php echo esc_html__('Personen','ev-groups'); ?></div>
                <div class="evg-pagination" aria-label="<?php echo esc_attr__('Seitennavigation','ev-groups'); ?>" role="navigation" hidden>
                    <button type="button" class="button button-secondary evg-page-prev" disabled>&lsaquo;</button>
                    <span class="evg-page-info"><?php echo esc_html__('Seite','ev-groups'); ?> <span class="evg-page-current">1</span> / <span class="evg-page-total">1</span></span>
                    <button type="button" class="button button-secondary evg-page-next" disabled>&rsaquo;</button>
                </div>
            </div>
            <div class="evg-table-container">
                <table class="wp-list-table widefat striped table-view-list evg-table">
                    <thead>
                        <tr>
                            <?php foreach ($cols as $col): ?>
                                <th><?php echo esc_html( $this->column_label($col) ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="evg-loading" style="display:none;"><?php echo esc_html__('Laden…','ev-groups'); ?></div>
            <div class="evg-error" style="display:none;"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function ajax_fetch_local(){
        if ( ! wp_verify_nonce(isset($_REQUEST['nonce'])?$_REQUEST['nonce']:'', 'evg_local') ) wp_send_json_error(array('message'=>'Bad nonce'),403);
        if ( ! is_user_logged_in() ) wp_send_json_error(array('message'=>'Login required'),401);

        $user_id = get_current_user_id();

        $allow_all_groups = (int) get_user_meta($user_id, 'evg_groups_all', true);
        $selected_group_ids = get_user_meta($user_id,'evg_groups',true);
        if (!is_array($selected_group_ids)) {
            $selected_group_ids = [];
        }
        $selected_group_ids = array_values(array_filter(array_map('strval', $selected_group_ids)));
        $restrict_groups = !$allow_all_groups && !empty($selected_group_ids);

        $custom_allow_all = (int) get_user_meta($user_id, 'evg_custom_filters_all', true);
        $custom_selected_tokens = get_user_meta($user_id, 'evg_custom_filters', true);
        if (!is_array($custom_selected_tokens)) {
            $custom_selected_tokens = [];
        }
        $custom_selected_tokens = array_values(array_filter(array_map('strval', $custom_selected_tokens)));
        $custom_field_map = [];
        foreach ($custom_selected_tokens as $token){
            if (strpos($token, '|') === false) {
                continue;
            }
            list($field_part, $hash_part) = explode('|', $token, 2);
            $field_part = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field_part);
            $hash_part = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$hash_part));
            if ($field_part === '' || strlen($hash_part) !== 32) {
                continue;
            }
            $field_key = strtolower($field_part);
            if (!isset($custom_field_map[$field_key])) {
                $custom_field_map[$field_key] = [];
            }
            $custom_field_map[$field_key][] = $hash_part;
        }
        foreach ($custom_field_map as $field => $hashes){
            $custom_field_map[$field] = array_values(array_unique($hashes));
            if (empty($custom_field_map[$field])) {
                unset($custom_field_map[$field]);
            }
        }
        $custom_restrict = !$custom_allow_all && !empty($custom_field_map);

        global $wpdb;
        $g_table  = $wpdb->prefix.'evg_groups';
        $m_table  = $wpdb->prefix.'evg_members';
        $x_table  = $wpdb->prefix.'evg_member_groups';
        $mc_table = $wpdb->prefix.'evg_member_custom_fields';
        $cf_table = $wpdb->prefix.'evg_custom_fields';
        $cv_table = $wpdb->prefix.'evg_custom_field_values';

        if ($restrict_groups){
            $in = implode("','", array_map('esc_sql', $selected_group_ids));
            $group_results = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM $g_table WHERE group_id IN ('$in')");
        } else {
            $group_results = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM $g_table");
        }
        $groups = array();
        foreach((array)$group_results as $row){
            $groups[$row->group_id] = $row->label;
        }

        $joins = [];
        $groups_where = '';
        if ($restrict_groups){
            $in = implode("','", array_map('esc_sql', $selected_group_ids));
            $joins[] = "JOIN $x_table x ON x.member_id = m.member_id";
            $groups_where = "x.group_id IN ('$in')";
        } else {
            $joins[] = "LEFT JOIN $x_table x ON x.member_id = m.member_id";
        }
        $joins[] = "LEFT JOIN $g_table g ON g.group_id = x.group_id";
        $joins[] = "LEFT JOIN $mc_table mc ON mc.member_id = m.member_id";
        $joins[] = "LEFT JOIN $cf_table cf ON cf.field_id = mc.field_id";
        $joins[] = "LEFT JOIN $cv_table cv ON cv.field_id = mc.field_id AND cv.value_hash = mc.value_hash";

        $where_clauses = [];
        if ($groups_where !== ''){
            $where_clauses[] = $groups_where;
        }
        if ($custom_restrict){
            $exists_cases = [];
            foreach ($custom_field_map as $field_id => $hashes){
                $field_sql = esc_sql($field_id);
                $hash_sql = implode("','", array_map('esc_sql', $hashes));
                $exists_cases[] = "(mc1.field_id = '{$field_sql}' AND mc1.value_hash IN ('{$hash_sql}'))";
            }
            if (!empty($exists_cases)){
                $exists_sql = "EXISTS (SELECT 1 FROM $mc_table mc1 WHERE mc1.member_id = m.member_id AND (".implode(' OR ', $exists_cases)."))";
                $where_clauses[] = $exists_sql;
            }
        }

        $sql = "SELECT m.*, 
                       COALESCE(GROUP_CONCAT(DISTINCT COALESCE(NULLIF(g.name,''), x.group_id) SEPARATOR '||'), '') AS group_names,
                       COALESCE(GROUP_CONCAT(DISTINCT CONCAT(mc.field_id,'|',mc.value_hash) SEPARATOR '||'), '') AS custom_pairs,
                       COALESCE(GROUP_CONCAT(DISTINCT CONCAT(COALESCE(NULLIF(cf.name,''), mc.field_id),' – ', COALESCE(NULLIF(cv.value_label,''), mc.value_text, mc.value_hash)) SEPARATOR '||'), '') AS custom_labels
                FROM $m_table m
                ".implode(' ', $joins).
                (!empty($where_clauses) ? ' WHERE '.implode(' AND ', $where_clauses) : '').
                ' GROUP BY m.member_id';

        $data = $wpdb->get_results($sql, ARRAY_A);

        if (get_option('evg_debug', 0)) {
            error_log('EVG Frontend SQL: ' . $sql);
            error_log('EVG Frontend Data Count: ' . count($data));
            if (!empty($data)) {
                error_log('EVG Frontend Sample Row: ' . print_r($data[0], true));
            }
        }

        $rows = array();
        foreach ((array)$data as $d){
            $group_name = isset($d['group_names']) ? $d['group_names'] : '';
            $groups_arr = array();
            if ($group_name !== '') {
                $parts = explode('||', $group_name);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') $groups_arr[] = $p;
                }
            }

            $custom_pairs_raw = isset($d['custom_pairs']) ? (string)$d['custom_pairs'] : '';
            $custom_pairs_arr = array();
            if ($custom_pairs_raw !== ''){
                foreach (explode('||', $custom_pairs_raw) as $pair){
                    $pair = strtolower(trim($pair));
                    if ($pair !== ''){
                        $custom_pairs_arr[] = $pair;
                    }
                }
                $custom_pairs_arr = array_values(array_unique($custom_pairs_arr));
            }

            $custom_labels_raw = isset($d['custom_labels']) ? (string)$d['custom_labels'] : '';
            $custom_labels_arr = array();
            if ($custom_labels_raw !== ''){
                foreach (explode('||', $custom_labels_raw) as $label){
                    $label = trim($label);
                    if ($label !== ''){
                        $custom_labels_arr[] = $label;
                    }
                }
                $custom_labels_arr = array_values(array_unique($custom_labels_arr));
            }

            $group_name_text = implode(', ', $groups_arr);
            $birth_year = '';
            if (isset($d['birth_year']) && $d['birth_year'] !== null && $d['birth_year'] !== '') {
                $birth_year = (string) $d['birth_year'];
            } elseif (!empty($d['date_of_birth']) && preg_match('/^\d{4}/', (string)$d['date_of_birth'], $matchYear)) {
                $birth_year = $matchYear[0];
            }
            $gender = '';
            if (isset($d['gender']) && $d['gender'] !== '') {
                $gender = trim((string)$d['gender']);
                if ($gender !== '') {
                    if (function_exists('mb_convert_case')) {
                        $gender = mb_convert_case($gender, MB_CASE_TITLE, 'UTF-8');
                    } else {
                        $gender = ucwords(strtolower($gender));
                    }
                }
            }
            $phone = '';
            if (isset($d['phone']) && $d['phone'] !== '') {
                if (is_array($d['phone'])) {
                    $phone = implode(', ', array_filter(array_map('trim', $d['phone'])));
                } else {
                    $phone = trim((string)$d['phone']);
                }
            }

            $rows[] = array(
                'full_name'     => trim((isset($d['first_name'])?$d['first_name']:'').' '.(isset($d['family_name'])?$d['family_name']:'')),
                'first_name'    => isset($d['first_name'])?$d['first_name']:'',
                'family_name'   => isset($d['family_name'])?$d['family_name']:'',
                'date_of_birth' => isset($d['date_of_birth'])?$d['date_of_birth']:'',
                'age'           => isset($d['age'])?$d['age']:'',
                'email_private' => isset($d['email_private'])?$d['email_private']:'',
                'birth_year'    => $birth_year,
                'gender'        => $gender,
                'phone'         => $phone,
                'zip'           => isset($d['zip'])?$d['zip']:'',
                'city'          => isset($d['city'])?$d['city']:'',
                'street'        => isset($d['street'])?$d['street']:'',
                'address_suffix'=> isset($d['address_suffix'])?$d['address_suffix']:'',
                'group_name'    => $group_name_text,
                'groups'        => $groups_arr,
                'custom_fields' => $custom_labels_arr,
                'member_number' => isset($d['member_number'])?$d['member_number']:'',
                'custom_pairs'  => $custom_pairs_arr,
                'custom_labels' => $custom_labels_arr,
            );
        }

        $custom_filters = [];
        $custom_option_conditions = [];
        if ($custom_restrict){
            foreach ($custom_field_map as $field_id => $hashes){
                $field_sql = esc_sql($field_id);
                $hash_sql = implode("','", array_map('esc_sql', $hashes));
                $custom_option_conditions[] = "(cv.field_id = '{$field_sql}' AND cv.value_hash IN ('{$hash_sql}'))";
            }
        }
        $custom_where_sql = '';
        if (!empty($custom_option_conditions)){
            $custom_where_sql = 'WHERE '.implode(' OR ', $custom_option_conditions);
        }
        $custom_results = $wpdb->get_results(
            "SELECT cv.field_id,
                    cv.value_hash,
                    cv.value_label,
                    COALESCE(NULLIF(cf.name,''), cv.field_label, cv.field_id) AS field_label
             FROM {$cv_table} cv
             LEFT JOIN {$cf_table} cf ON cf.field_id = cv.field_id
             {$custom_where_sql}
             ORDER BY field_label ASC, value_label ASC",
            ARRAY_A
        );
        foreach ((array)$custom_results as $row){
            $field_id = isset($row['field_id']) ? strtolower((string)$row['field_id']) : '';
            $value_hash = isset($row['value_hash']) ? strtolower((string)$row['value_hash']) : '';
            if ($field_id === '' || strlen($value_hash) !== 32){
                continue;
            }
            $field_label = isset($row['field_label']) && $row['field_label'] !== '' ? $row['field_label'] : $field_id;
            $value_label = isset($row['value_label']) && $row['value_label'] !== '' ? $row['value_label'] : $value_hash;
            $token = $field_id.'|'.$value_hash;
            $custom_filters[$token] = $field_label.' – '.$value_label;
        }

        wp_send_json_success(array(
            'rows'            => $rows,
            'groups'          => $groups,
            'custom_filters'  => $custom_filters
        ));
    }

    private function resolve_columns($columns_attr){
        $columns = array();
        if (is_string($columns_attr) && $columns_attr !== '') {
            $columns = array_map('sanitize_text_field', array_map('trim', explode(',', $columns_attr)));
        }
        $columns = array_values(array_filter(array_unique($columns)));
        $columns = $this->filter_known_columns($columns);
        if (empty($columns)) {
            $columns = $this->get_default_columns();
        }
        return $columns;
    }

    private function filter_known_columns($columns){
        if (empty($columns)) {
            return array();
        }
        return array_values(array_intersect($columns, array_keys(self::COLUMN_LABELS)));
    }

    private function get_default_columns(){
        return self::DEFAULT_COLUMNS;
    }

    private function get_column_labels(){
        $labels = array();
        foreach (self::COLUMN_LABELS as $key => $label){
            $labels[$key] = __($label, 'ev-groups');
        }
        return $labels;
    }

    private function column_label($key){
        $labels = $this->get_column_labels();
        if (isset($labels[$key])) {
            return $labels[$key];
        }
        $fallback = str_replace('_',' ',$key);
        return ucwords($fallback);
    }
}
