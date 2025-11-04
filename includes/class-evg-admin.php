<?php
if (!defined('ABSPATH')) { exit; }

class EVG_Admin {
    public function __construct(){
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'settings']);
        add_action('wp_ajax_evg_sync_start', [$this,'ajax_sync_start']);
        add_action('wp_ajax_evg_sync_tick', [$this,'ajax_sync_tick']);
        add_action('wp_ajax_evg_test_connection', [$this,'ajax_test_connection']);

        add_action('show_user_profile', [$this,'user_groups_fields']);
        add_action('edit_user_profile',  [$this,'user_groups_fields']);
        add_action('personal_options_update', [$this,'save_user_groups']);
        add_action('edit_user_profile_update', [$this,'save_user_groups']);

        add_action('admin_enqueue_scripts', [$this,'enqueue_admin_assets']);
    }

    public function sanitize_table_prefix($value){
        return evg_sanitize_table_prefix($value);
    }

    public function menu(){
        add_menu_page(__('Easyverein Go','ev-groups'),__('Easyverein Go','ev-groups'),'manage_options',EVG_SLUG,[$this,'page'],'dashicons-groups',58);
    }

    public function settings(){
        register_setting('evg_settings','evg_api_base',['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('evg_settings','evg_api_key');
        register_setting('evg_settings','evg_auth_header');
        register_setting('evg_settings','evg_groups_path');
        register_setting('evg_settings','evg_members_path');
        register_setting('evg_settings','evg_contact_details_path');
        register_setting('evg_settings','evg_member_groups_path');
        register_setting('evg_settings','evg_custom_fields_path');
        register_setting('evg_settings','evg_member_custom_fields_path');
        register_setting('evg_settings','evg_debug',['type'=>'boolean','sanitize_callback'=>'absint','default'=>1]);
        register_setting('evg_settings','evg_sync_next_pages_max',['type'=>'integer','sanitize_callback'=>'absint','default'=>100]);
        register_setting('evg_settings','evg_sync_rate_per_sec',['type'=>'integer','sanitize_callback'=>'absint','default'=>5]);
        register_setting('evg_settings','evg_sync_calls_per_tick',['type'=>'integer','sanitize_callback'=>'absint','default'=>5]);
        register_setting('evg_settings','evg_tick_pause_ms',['type'=>'integer','sanitize_callback'=>'absint','default'=>900]);
        register_setting('evg_settings','evg_sync_skip_groups',['type'=>'boolean','sanitize_callback'=>'absint','default'=>0]);
        register_setting('evg_settings','evg_nightly_sync_enabled',['type'=>'boolean','sanitize_callback'=>'absint','default'=>0]);
        register_setting('evg_settings','evg_nightly_sync_table_prefix',['type'=>'string','sanitize_callback'=>[$this,'sanitize_table_prefix'],'default'=>'evg_nightly']);
    }

    private function headers(){
        $key=get_option('evg_api_key',''); $hdr=get_option('evg_auth_header','Authorization Bearer');
        $h=['Accept'=>'application/json']; if($hdr==='Authorization Bearer') $h['Authorization']='Bearer '.$key; else $h['X-API-Key']=$key; return $h;
    }

    public function page(){ ?>
        <div class="wrap"><h1><?php echo esc_html__('Easyverein Go','ev-groups'); ?></h1>
            <form method="post" action="options.php" class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px">
                <?php settings_fields('evg_settings'); ?>
                <h2><?php echo esc_html__('API','ev-groups'); ?></h2>
                <table class="form-table">
                    <tr><th>API Base URL</th><td><input type="text" name="evg_api_base" class="regular-text" placeholder="https://easyverein.com" value="<?php echo esc_attr(get_option('evg_api_base','')); ?>"></td></tr>
                    <tr><th>API Schlüssel</th><td><input type="text" name="evg_api_key" class="regular-text" placeholder="sk_live_…" value="<?php echo esc_attr(get_option('evg_api_key','')); ?>"></td></tr>
                    <tr><th>Auth Header</th><td><select name="evg_auth_header"><?php $v=get_option('evg_auth_header','Authorization Bearer'); ?>
                        <option value="Authorization Bearer" <?php selected($v,'Authorization Bearer'); ?>>Authorization: Bearer {KEY}</option>
                        <option value="X-API-Key" <?php selected($v,'X-API-Key'); ?>>X-API-Key: {KEY}</option>
                    </select></td></tr>
                    <tr><th>Groups Path</th><td><input type="text" name="evg_groups_path" class="regular-text" placeholder="/api/v2.0/member-group" value="<?php echo esc_attr(get_option('evg_groups_path','/api/v2.0/member-group')); ?>"></td></tr>
                    <tr><th>Members Path</th><td><input type="text" name="evg_members_path" class="regular-text" placeholder="/api/v2.0/member" value="<?php echo esc_attr(get_option('evg_members_path','/api/v2.0/member')); ?>"></td></tr>
                    <tr><th>Contact-Details Path</th><td><input type="text" name="evg_contact_details_path" class="regular-text" placeholder="/api/v2.0/contact-details/{id}" value="<?php echo esc_attr(get_option('evg_contact_details_path','/api/v2.0/contact-details/{id}')); ?>"></td></tr>
                    <tr><th>Custom-Fields Path</th><td><input type="text" name="evg_custom_fields_path" class="regular-text" placeholder="/api/v2.0/custom-field" value="<?php echo esc_attr(get_option('evg_custom_fields_path','/api/v2.0/custom-field')); ?>"></td></tr>
                    <tr><th>Member→Custom-Fields Path</th><td><input type="text" name="evg_member_custom_fields_path" class="regular-text" placeholder="/api/v2.0/member/{id}/custom-fields" value="<?php echo esc_attr(get_option('evg_member_custom_fields_path','/api/v2.0/member/{id}/custom-fields')); ?>"></td></tr>
                    <tr><th>Member→Groups Path</th><td><input type="text" name="evg_member_groups_path" class="regular-text" placeholder="/api/v2.0/member/{id}/groups" value="<?php echo esc_attr(get_option('evg_member_groups_path','/api/v2.0/member/{id}/groups')); ?>"></td></tr>
                    <tr><th>Debug</th><td><label><input type="checkbox" name="evg_debug" value="1" <?php checked(1,(int)get_option('evg_debug',1)); ?>> aktivieren</label></td></tr>
                </table>
                <h2><?php echo esc_html__('Taktung & Limits','ev-groups'); ?></h2>
                <table class="form-table">
                    <tr><th>Max. Seiten (next)</th><td><input type="number" name="evg_sync_next_pages_max" value="<?php echo esc_attr(get_option('evg_sync_next_pages_max',100)); ?>"></td></tr>
                    <tr><th>Requests/Sek.</th><td><input type="number" name="evg_sync_rate_per_sec" value="<?php echo esc_attr(get_option('evg_sync_rate_per_sec',5)); ?>"></td></tr>
                    <tr><th>Calls pro Tick</th><td><input type="number" name="evg_sync_calls_per_tick" value="<?php echo esc_attr(get_option('evg_sync_calls_per_tick',5)); ?>"></td></tr>
                    <tr><th>Tick-Pause (ms)</th><td><input type="number" name="evg_tick_pause_ms" value="<?php echo esc_attr(get_option('evg_tick_pause_ms',900)); ?>"></td></tr>
                    <tr><th>Nur Mitglieder + Details (ohne Gruppen)</th><td><label><input type="checkbox" name="evg_sync_skip_groups" value="1" <?php checked(1,(int)get_option('evg_sync_skip_groups',0)); ?>> aktivieren</label></td></tr>
                    <tr>
                        <th><?php esc_html_e('Nächtlicher Sync','ev-groups'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="evg_nightly_sync_enabled" value="1" <?php checked(1,(int)get_option('evg_nightly_sync_enabled',0)); ?>>
                                <?php esc_html_e('Automatischen nächtlichen Vollabgleich (ca. 03:00 Uhr) aktivieren','ev-groups'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Nutzen Sie diese Option, um einmal täglich (über WP-Cron) einen kompletten Sync aller Mitglieder und Gruppen auszuführen.','ev-groups'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Tabellen-Präfix (nächtlich)','ev-groups'); ?></th>
                        <td>
                            <?php $nightly_prefix = get_option('evg_nightly_sync_table_prefix','evg_nightly'); ?>
                            <input type="text" name="evg_nightly_sync_table_prefix" class="regular-text" value="<?php echo esc_attr($nightly_prefix); ?>">
                            <p class="description">
                                <?php esc_html_e('Standard: evg_nightly – für produktive Tabellen „evg“ eintragen. Eigene Werte werden automatisch bereinigt (a–z, 0–9, Unterstrich).','ev-groups'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <div class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px;margin-top:12px">
                <h2><?php echo esc_html__('Manueller Sync','ev-groups'); ?></h2>
                <p><?php echo esc_html__('Ablauf: Mitglieder (paginiert) → Contact-Details → optional Member→Groups.','ev-groups'); ?></p>
                <p>
                    <button id="evg-sync-start" class="button button-primary"><?php echo esc_html__('Jetzt synchronisieren','ev-groups'); ?></button>
                    <button id="evg-sync-quick10" class="button"><?php echo esc_html__('Nur 10 Mitglieder testen','ev-groups'); ?></button>
                    <button id="evg-test-conn" class="button"><?php echo esc_html__('Verbindung testen','ev-groups'); ?></button>
                    <span id="evg-test-conn-out" style="margin-left:8px;opacity:.8"></span>
                </p>
                <div style="height:12px;background:#e5e7eb;border-radius:6px;overflow:hidden"><span id="evg-bar" style="display:block;height:100%;background:#10b981;width:0%"></span></div>
                <pre id="evg-log" style="max-height:220px;overflow:auto;background:#0b1020;color:#d6deff;padding:8px;border-radius:6px;margin-top:8px"></pre>
            </div>
        </div>
        <script>
        (function(){
            const ajax=ajaxurl, n1='<?php echo wp_create_nonce('evg_sync'); ?>';
            const bar=document.getElementById('evg-bar');
            const log=document.getElementById('evg-log');
            const tOut=document.getElementById('evg-test-conn-out');
            const tickPause = parseInt('<?php echo (int) get_option("evg_tick_pause_ms",900); ?>',10)||900;
            const nightlyPrefixRaw = '<?php echo esc_js(evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly'))); ?>';
            const nightlyPrefix = nightlyPrefixRaw || 'evg_nightly';
            let currentPrefix = 'evg';
            let jobFinished = false;
            function push(line){ const ts=new Date().toLocaleTimeString(); log.textContent='['+ts+'] '+line+'\n'+log.textContent; }
            function setP(p,txt){ if(p<0)p=0;if(p>100)p=100; bar.style.width=p.toFixed(1)+'%'; if(txt) push(txt+' ('+p.toFixed(1)+'%)'); }

            document.getElementById('evg-test-conn').onclick=function(e){ e.preventDefault(); tOut.textContent='teste…';
                jQuery.post(ajax,{action:'evg_test_connection',_wpnonce:n1},function(r){ tOut.textContent=(r&&r.success&&r.data&&r.data.message)?r.data.message:'OK'; }).fail(function(){ tOut.textContent='Netzwerkfehler'; });
            };
            function tick(){
                jQuery.post(ajax,{action:'evg_sync_tick',_wpnonce:n1,prefix:currentPrefix},function(r){
                    if(r&&r.success){
                        const d=r.data||{};
                        setP(d.percent||0, d.label||'…');
                        if(d.done){
                            if(!jobFinished){
                                jobFinished = true;
                                push('Job abgeschlossen ['+currentPrefix+']');
                            }
                        } else {
                            setTimeout(tick,tickPause);
                        }
                    }
                });
            }
            function startSync(prefix,cap){
                currentPrefix = (prefix && prefix.length) ? prefix : 'evg';
                jobFinished = false;
                const payload = {action:'evg_sync_start',_wpnonce:n1,prefix:currentPrefix};
                if(cap){ payload.cap = cap; }
                jQuery.post(ajax,payload,function(r){
                    if(r&&r.success){
                        const serverPrefix = r.data && r.data.prefix ? r.data.prefix : currentPrefix;
                        currentPrefix = serverPrefix || currentPrefix;
                        const title = cap ? 'Quick-Job ('+cap+')' : 'Job';
                        push(title+' gestartet ['+currentPrefix+']');
                        setP(0,'Start');
                        tick();
                    }
                });
            }
            document.getElementById('evg-sync-start').onclick=function(e){ e.preventDefault(); startSync('evg'); };
            document.getElementById('evg-sync-quick10').onclick=function(e){ e.preventDefault(); startSync(nightlyPrefix,10); };
        })();
        </script>
    <?php }

    public function enqueue_admin_assets($hook){
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') return;
        wp_enqueue_style('evg-admin-picker', EVG_URL.'assets/admin/evg-picker.css', [], EVG_VERSION);
        wp_enqueue_script('evg-admin-picker', EVG_URL.'assets/admin/evg-picker.js', ['jquery'], EVG_VERSION, true);
    }

    public function user_groups_fields($user){
        if (!current_user_can('edit_user', $user->ID)) return;
        global $wpdb;
        $g_table = $wpdb->prefix.'evg_groups';
        $groups = $wpdb->get_results("SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label, COALESCE(NULLIF(short,''), '') AS short FROM $g_table ORDER BY label ASC", ARRAY_A);
        $group_rows = [];
        foreach ((array) $groups as $g) {
            $gid = isset($g['group_id']) ? (string) $g['group_id'] : '';
            if ($gid === '') { continue; }
            $label = isset($g['label']) && $g['label'] !== '' ? $g['label'] : $gid;
            $short = isset($g['short']) ? (string) $g['short'] : '';
            $group_rows[] = [
                'id'     => $gid,
                'label'  => $label,
                'short'  => $short,
                'search' => strtolower(trim($label.' '.$short.' '.$gid))
            ];
        }
        $selected = get_user_meta($user->ID, 'evg_groups', true);
        if (!is_array($selected)) $selected = [];
        $selected_ids = [];
        foreach ($selected as $one){
            if (is_array($one) && isset($one['id'])) $selected_ids[] = (string)$one['id'];
            elseif (is_string($one)) $selected_ids[] = $one;
        }
        $allow_all = (int) get_user_meta($user->ID, 'evg_groups_all', true);

        $cv_table = $wpdb->prefix.'evg_custom_field_values';
        $cf_table = $wpdb->prefix.'evg_custom_fields';
        $custom_rows = $wpdb->get_results(
            "SELECT cv.field_id,
                    cv.value_hash,
                    cv.value_label,
                    COALESCE(NULLIF(cf.name,''), cv.field_label, cv.field_id) AS field_label
             FROM {$cv_table} cv
             LEFT JOIN {$cf_table} cf ON cf.field_id = cv.field_id
             ORDER BY field_label ASC, value_label ASC",
            ARRAY_A
        );
        $custom_allow_all = (int) get_user_meta($user->ID, 'evg_custom_filters_all', true);
        $custom_selected = get_user_meta($user->ID, 'evg_custom_filters', true);
        if (!is_array($custom_selected)) {
            $custom_selected = [];
        }
        $custom_selected = array_map('strval', $custom_selected);
        ?>
        <h2><?php esc_html_e('EasyVerein Gruppen-Zuordnung','ev-groups'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Sichtbare Gruppen','ev-groups'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="evg_groups_all" id="evg-groups-all" value="1" <?php checked(1,$allow_all); ?>>
                        <?php esc_html_e('Alle (keine Einschränkung)','ev-groups'); ?>
                    </label>
                    <div class="evg-group-select <?php echo $allow_all ? 'is-disabled' : ''; ?>">
                        <div class="evg-group-select__toolbar">
                            <input type="search" class="regular-text evg-groups-search" placeholder="<?php echo esc_attr__('Gruppen filtern…','ev-groups'); ?>" value="" <?php disabled($allow_all); ?>>
                            <span class="description"><?php esc_html_e('Klicke auf eine Zeile, um sie zu (de-)aktivieren.','ev-groups'); ?></span>
                        </div>
                        <div class="evg-group-table-wrapper">
                            <table class="widefat fixed striped evg-groups-table">
                                <thead>
                                    <tr>
                                        <td class="check-column"><input type="checkbox" class="evg-select-toggle" aria-label="<?php esc_attr_e('Alle markieren','ev-groups'); ?>"></td>
                                        <th class="column-title"><?php esc_html_e('Gruppe','ev-groups'); ?></th>
                                        <th class="column-short"><?php esc_html_e('Kurz','ev-groups'); ?></th>
                                        <th class="column-id"><?php esc_html_e('ID','ev-groups'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($group_rows)) : ?>
                                    <tr>
                                        <td colspan="4" class="evg-no-groups"><?php esc_html_e('Keine Gruppen synchronisiert. Bitte zuerst den Sync ausführen.','ev-groups'); ?></td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($group_rows as $row):
                                        $gid = $row['id'];
                                        $is_selected = in_array($gid, $selected_ids, true);
                                        $search = $row['search'] !== '' ? $row['search'] : strtolower($gid);
                                    ?>
                                        <tr class="evg-group-row <?php echo $is_selected ? 'is-selected' : ''; ?>" data-search="<?php echo esc_attr($search); ?>">
                                            <th scope="row" class="check-column">
                                                <input type="checkbox" class="evg-group-checkbox" name="evg_groups_selected[]" value="<?php echo esc_attr($gid); ?>" <?php checked($is_selected); ?>>
                                            </th>
                                            <td class="column-title">
                                                <strong><?php echo esc_html($row['label']); ?></strong>
                                            </td>
                                            <td class="column-short">
                                                <?php echo esc_html($row['short']); ?>
                                            </td>
                                            <td class="column-id">
                                                <code><?php echo esc_html($gid); ?></code>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p class="description"><?php esc_html_e('Ausgewählte Gruppen bestimmen, welche Datensätze der Nutzer im Frontend sieht. Ohne Auswahl gelten alle Gruppen.','ev-groups'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Sichtbare Custom-Field-Werte','ev-groups'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="evg_cf_all" value="1" <?php checked(1, $custom_allow_all); ?>>
                        <?php esc_html_e('Alle Custom-Field-Werte erlauben','ev-groups'); ?>
                    </label>
                    <?php if (!empty($custom_rows)) : ?>
                        <p>
                            <select name="evg_cf_selected[]" class="large-text" multiple size="8">
                                <?php
                                $custom_seen = [];
                                foreach ($custom_rows as $row):
                                    $field_id = isset($row['field_id']) ? (string)$row['field_id'] : '';
                                    $value_hash = isset($row['value_hash']) ? strtolower((string)$row['value_hash']) : '';
                                    if ($field_id === '' || $value_hash === '') {
                                        continue;
                                    }
                                    $token = strtolower($field_id).'|'.$value_hash;
                                    if (isset($custom_seen[$token])) {
                                        continue;
                                    }
                                    $custom_seen[$token] = true;
                                    $field_label = isset($row['field_label']) && $row['field_label'] !== '' ? $row['field_label'] : $field_id;
                                    $value_label = isset($row['value_label']) && $row['value_label'] !== '' ? $row['value_label'] : $value_hash;
                                    ?>
                                    <option value="<?php echo esc_attr($token); ?>" <?php selected(in_array($token, $custom_selected, true)); ?>>
                                        <?php echo esc_html($field_label.' – '.$value_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Keine Custom-Field-Werte gefunden. Bitte starte zuerst einen Sync.','ev-groups'); ?></p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Optional lassen sich hier Custom-Field-Werte auswählen, die ein Benutzer sehen darf. Ohne Auswahl gelten alle Werte.','ev-groups'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_groups($user_id){
        if (!current_user_can('edit_user', $user_id)) return;
        $allow_all = isset($_POST['evg_groups_all']) ? 1 : 0;
        update_user_meta($user_id, 'evg_groups_all', $allow_all);
        $ids = isset($_POST['evg_groups_selected']) ? (array) $_POST['evg_groups_selected'] : [];
        $clean_ids = [];
        foreach ($ids as $gid){
            $gid = sanitize_text_field($gid);
            if ($gid !== '') $clean_ids[] = $gid;
        }
        update_user_meta($user_id, 'evg_groups', $clean_ids);

        $allow_all_custom = isset($_POST['evg_cf_all']) ? 1 : 0;
        update_user_meta($user_id, 'evg_custom_filters_all', $allow_all_custom);
        $selected_tokens = [];
        if (!$allow_all_custom && isset($_POST['evg_cf_selected']) && is_array($_POST['evg_cf_selected'])){
            foreach ($_POST['evg_cf_selected'] as $token){
                $token = sanitize_text_field($token);
                if (strpos($token, '|') === false) {
                    continue;
                }
                list($field_part, $hash_part) = explode('|', $token, 2);
                $field_part = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field_part);
                $hash_part = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$hash_part));
                if ($field_part === '' || strlen($hash_part) !== 32) {
                    continue;
                }
                $selected_tokens[] = strtolower($field_part).'|'.$hash_part;
            }
        }
        update_user_meta($user_id, 'evg_custom_filters', $allow_all_custom ? [] : array_values(array_unique($selected_tokens)));
    }

    public function ajax_test_connection(){
        check_ajax_referer('evg_sync'); if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);
        $base=rtrim((string)get_option('evg_api_base',''),'/');
        $members=(string)get_option('evg_members_path','/api/v2.0/member');
        $h=$this->headers();
        $r=evg_http_get($base.$members,$h);
        $c=is_wp_error($r)?'ERR':wp_remote_retrieve_response_code($r);
        $msg = 'Members: '.$c.($c===200?' – OK':($c===404?' – Endpoint falsch':''));
        wp_send_json_success(['message'=>$msg]);
    }

    private function sanitize_prefix_from_request($value){
        $allowed = ['evg'];
        $nightly = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly'));
        if ($nightly !== '' && !in_array($nightly, $allowed, true)){
            $allowed[] = $nightly;
        }
        $prefix = evg_sanitize_table_prefix((string)$value);
        if ($prefix === '' || !in_array($prefix, $allowed, true)){
            return 'evg';
        }
        return $prefix;
    }

    public function ajax_sync_start(){
        check_ajax_referer('evg_sync');
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);
        $cap = isset($_POST['cap']) ? max(0, intval($_POST['cap'])) : 0;
        $requested_prefix = isset($_POST['prefix']) ? wp_unslash($_POST['prefix']) : '';
        $prefix = $this->sanitize_prefix_from_request($requested_prefix);
        $sync = new EVG_Sync($prefix);
        $sync->job_start($cap);
        wp_send_json_success(['prefix'=>$sync->get_table_prefix()]);
    }

    public function ajax_sync_tick(){
        check_ajax_referer('evg_sync');
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);
        $requested_prefix = isset($_POST['prefix']) ? wp_unslash($_POST['prefix']) : '';
        $prefix = $this->sanitize_prefix_from_request($requested_prefix);
        $sync=new EVG_Sync($prefix);
        $res=$sync->job_tick();
        if($res['ok']) wp_send_json_success($res);
        wp_send_json_error(['message'=>$res['summary']]);
    }
}
