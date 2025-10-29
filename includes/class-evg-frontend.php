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
        'groups'
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
        $allow_all = (int) get_user_meta($user_id, 'evg_groups_all', true);
        $selected_ids = get_user_meta($user_id,'evg_groups',true);
        if (!is_array($selected_ids)) $selected_ids = [];
        $restrict = !$allow_all && !empty($selected_ids);

        global $wpdb;
        $g_table = $wpdb->prefix.'evg_groups';
        $m_table = $wpdb->prefix.'evg_members';
        $x_table = $wpdb->prefix.'evg_member_groups';

        // Get available groups for filter dropdown
        if ($restrict){
            $in = implode("','", array_map('esc_sql',$selected_ids));
            $gr = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM $g_table WHERE group_id IN ('$in')");
        } else {
            $gr = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM $g_table");
        }
        $groups = array(); 
        foreach((array)$gr as $row){ 
            $groups[$row->group_id] = $row->label; 
        }

        // Get members with their group names properly aggregated
        if ($restrict){
            $in = implode("','", array_map('esc_sql',$selected_ids));
            $sql = "SELECT m.*, 
                           COALESCE(GROUP_CONCAT(COALESCE(NULLIF(g.name,''), x.group_id) SEPARATOR '||'), '') AS group_names
                    FROM $m_table m
                    JOIN $x_table x ON x.member_id = m.member_id
                    LEFT JOIN $g_table g ON g.group_id = x.group_id
                    WHERE x.group_id IN ('$in')
                    GROUP BY m.member_id";
        } else {
            $sql = "SELECT m.*, 
                           COALESCE(GROUP_CONCAT(COALESCE(NULLIF(g.name,''), x.group_id) SEPARATOR '||'), '') AS group_names
                    FROM $m_table m
                    LEFT JOIN $x_table x ON x.member_id = m.member_id
                    LEFT JOIN $g_table g ON g.group_id = x.group_id
                    GROUP BY m.member_id";
        }
        $data = $wpdb->get_results($sql, ARRAY_A);

        // Debug logging if enabled
        if (get_option('evg_debug', 0)) {
            error_log('EVG Frontend SQL: ' . $sql);
            error_log('EVG Frontend Data Count: ' . count($data));
            if (!empty($data)) {
                error_log('EVG Frontend Sample Row: ' . print_r($data[0], true));
            }
        }

        $rows = array();
        foreach((array)$data as $d){
            $group_name = isset($d['group_names']) ? $d['group_names'] : '';
            $groups_arr = array();
            if ($group_name !== '') {
                $parts = explode('||', $group_name);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') $groups_arr[] = $p;
                }
            }
            
            // Debug individual row if debug is enabled
            if (get_option('evg_debug', 0) && !empty($group_name)) {
                error_log('EVG Frontend Group Name: ' . $group_name);
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
                'member_number' => isset($d['member_number'])?$d['member_number']:'',
            );
        }

        wp_send_json_success(array('rows'=>$rows,'groups'=>$groups));
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
