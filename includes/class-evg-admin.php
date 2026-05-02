<?php
if (!defined('ABSPATH')) { exit; }

class EVG_Admin {
    public function __construct(){
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'settings']);
        add_action('wp_ajax_evg_sync_start', [$this,'ajax_sync_start']);
        add_action('wp_ajax_evg_sync_tick', [$this,'ajax_sync_tick']);
        add_action('wp_ajax_evg_test_connection', [$this,'ajax_test_connection']);
        add_action('wp_ajax_evg_nightly_log', [$this,'ajax_nightly_log']);
        add_action('wp_ajax_evg_table_swap',  [$this,'ajax_table_swap']);

        add_action('show_user_profile', [$this,'user_groups_fields']);
        add_action('edit_user_profile',  [$this,'user_groups_fields']);
        add_action('personal_options_update', [$this,'save_user_groups']);
        add_action('edit_user_profile_update', [$this,'save_user_groups']);

        add_action('admin_enqueue_scripts', [$this,'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this,'admin_bar_version'], 100);
    }

    public function sanitize_table_prefix($value){
        return evg_sanitize_table_prefix($value);
    }

    public function menu(){
        add_menu_page(__('Easyverein Go','ev-groups'),__('Easyverein Go','ev-groups'),'manage_options',EVG_SLUG,[$this,'page'],'dashicons-groups',58);
    }

    public function settings(){
        register_setting('evg_settings','evg_api_base',['type'=>'string','sanitize_callback'=>'esc_url_raw']);
        register_setting('evg_settings','evg_api_key',['sanitize_callback'=>'sanitize_text_field']);
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
        register_setting('evg_settings','evg_nightly_sync_enabled',['type'=>'boolean','sanitize_callback'=>'absint','default'=>0]);
        register_setting('evg_settings','evg_nightly_sync_table_prefix',['type'=>'string','sanitize_callback'=>[$this,'sanitize_table_prefix'],'default'=>'evg_nightly']);
        register_setting('evg_settings','evg_manual_sync_table_prefix',['type'=>'string','sanitize_callback'=>[$this,'sanitize_table_prefix'],'default'=>'evg']);
        register_setting('evg_settings','evg_sync_report_email',['type'=>'string','sanitize_callback'=>'sanitize_email','default'=>'']);
        register_setting('evg_settings','evg_nightly_auto_swap',['type'=>'boolean','sanitize_callback'=>'absint','default'=>0]);
        // OIDC Web-Login
        register_setting('evg_settings', EVG_Oidc::OPT_CLIENT_ID,    ['sanitize_callback'=>'sanitize_text_field']);
        register_setting('evg_settings', EVG_Oidc::OPT_REDIRECT_URI, ['sanitize_callback'=>'esc_url_raw']);
        register_setting('evg_settings', EVG_Oidc::OPT_WEB_LOGIN,    ['type'=>'boolean','sanitize_callback'=>'absint','default'=>0]);
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
                    <tr><th>API Schlüssel</th><td>
                        <input type="password" name="evg_api_key" class="regular-text" placeholder="sk_live_…" value="<?php echo esc_attr(get_option('evg_api_key','')); ?>">
                        <?php
                        $token_age = evg_api_token_age_days();
                        if ($token_age === null):
                        ?>
                            <p class="description" style="color:#b45309;">
                                ⚠️ <?php esc_html_e('Token-Alter unbekannt – noch kein automatischer Refresh durchgeführt. Empfohlen: mindestens einen Sync-Lauf starten.','ev-groups'); ?>
                            </p>
                        <?php elseif ($token_age >= 25): ?>
                            <p class="description" style="color:#dc2626;font-weight:600;">
                                ⛔ <?php printf(esc_html__('Token ist %d Tage alt – bitte erneuern! (EasyVerein: „GET /api/v3.0/refresh-token" oder nächsten Sync abwarten)','ev-groups'), $token_age); ?>
                            </p>
                        <?php elseif ($token_age >= 15): ?>
                            <p class="description" style="color:#b45309;">
                                ⚠️ <?php printf(esc_html__('Token ist %d Tage alt – Erneuerung beim nächsten Sync-Lauf empfohlen.','ev-groups'), $token_age); ?>
                            </p>
                        <?php else: ?>
                            <p class="description" style="color:#15803d;">
                                ✓ <?php printf(esc_html__('Token zuletzt erneuert vor %d Tag(en).','ev-groups'), $token_age); ?>
                            </p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Auth Header</th><td><select name="evg_auth_header"><?php $v=get_option('evg_auth_header','Authorization Bearer'); ?>
                        <option value="Authorization Bearer" <?php selected($v,'Authorization Bearer'); ?>>Authorization: Bearer {KEY}</option>
                        <option value="X-API-Key" <?php selected($v,'X-API-Key'); ?>>X-API-Key: {KEY}</option>
                    </select></td></tr>
                    <tr><th>Groups Path</th><td><input type="text" name="evg_groups_path" class="regular-text" placeholder="/api/v3.0/member-group" value="<?php echo esc_attr(get_option('evg_groups_path','/api/v3.0/member-group')); ?>"></td></tr>
                    <tr><th>Members Path</th><td><input type="text" name="evg_members_path" class="regular-text" placeholder="/api/v3.0/member" value="<?php echo esc_attr(get_option('evg_members_path','/api/v3.0/member')); ?>"></td></tr>
                    <tr><th>Contact-Details Path</th><td><input type="text" name="evg_contact_details_path" class="regular-text" placeholder="/api/v3.0/contact-details/{id}" value="<?php echo esc_attr(get_option('evg_contact_details_path','/api/v3.0/contact-details/{id}')); ?>"></td></tr>
                    <tr><th>Custom-Fields Path</th><td><input type="text" name="evg_custom_fields_path" class="regular-text" placeholder="/api/v3.0/custom-field" value="<?php echo esc_attr(get_option('evg_custom_fields_path','/api/v3.0/custom-field')); ?>"></td></tr>
                    <tr><th>Member→Custom-Fields Path</th><td><input type="text" name="evg_member_custom_fields_path" class="regular-text" placeholder="/api/v3.0/member-custom-field-assignment?user_object={id}" value="<?php echo esc_attr(get_option('evg_member_custom_fields_path','/api/v3.0/member-custom-field-assignment?user_object={id}')); ?>"></td></tr>
                    <tr><th>Member→Groups Path</th><td><input type="text" name="evg_member_groups_path" class="regular-text" placeholder="/api/v3.0/member-group-assignment?user_object={id}" value="<?php echo esc_attr(get_option('evg_member_groups_path','/api/v3.0/member-group-assignment?user_object={id}')); ?>"></td></tr>
                    <tr>
                        <th>Debug</th>
                        <td>
                            <label>
                                <input type="checkbox" name="evg_debug" value="1" <?php checked(1,(int)get_option('evg_debug',1)); ?>>
                                <?php esc_html_e('aktivieren (API-Aufrufe protokollieren)','ev-groups'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Hinweis: Unabhängig von dieser Option werden HTTP-Fehler (z. B. 4xx/5xx) zur Diagnose gespeichert.','ev-groups'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <h2><?php echo esc_html__('Taktung & Limits','ev-groups'); ?></h2>
                <table class="form-table">
                    <tr><th>Max. Seiten (next)</th><td><input type="number" name="evg_sync_next_pages_max" value="<?php echo esc_attr(get_option('evg_sync_next_pages_max',100)); ?>"></td></tr>
                    <tr><th>Requests/Sek.</th><td><input type="number" name="evg_sync_rate_per_sec" value="<?php echo esc_attr(get_option('evg_sync_rate_per_sec',5)); ?>"></td></tr>
                    <tr><th>Calls pro Tick</th><td><input type="number" name="evg_sync_calls_per_tick" value="<?php echo esc_attr(get_option('evg_sync_calls_per_tick',5)); ?>"></td></tr>
                    <tr><th>Tick-Pause (ms)</th><td><input type="number" name="evg_tick_pause_ms" value="<?php echo esc_attr(get_option('evg_tick_pause_ms',900)); ?>"></td></tr>
                    <tr>
                        <th><?php esc_html_e('Tabellen-Präfix (manuell)','ev-groups'); ?></th>
                        <td>
                            <?php $manual_prefix = evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg')); ?>
                            <input type="text" name="evg_manual_sync_table_prefix" class="regular-text" value="<?php echo esc_attr($manual_prefix); ?>">
                            <p class="description"><?php esc_html_e('Wird für manuelle Sync-Läufe (Buttons unten) verwendet. Standard ist „evg“.','ev-groups'); ?></p>
                        </td>
                    </tr>
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
                    </tr>                    <tr>
                        <th><?php esc_html_e('Auto-Swap nach Nightly Sync','ev-groups'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="evg_nightly_auto_swap" value="1" <?php checked(1,(int)get_option('evg_nightly_auto_swap',0)); ?>>
                                <?php esc_html_e('Nach erfolgreichem nächtlichen Sync die Nightly-Tabellen automatisch in die Live-Tabellen übernehmen','ev-groups'); ?>
                            </label>
                            <p class="description">
                                <?php
                                $live_p = evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg')) ?: 'evg';
                                $nightly_p = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly')) ?: 'evg_nightly';
                                printf(
                                    esc_html__('Die Tabellen mit Präfix „%1$s" werden atomar (RENAME TABLE) durch die frisch synchronisierten Tabellen mit Präfix „%2$s" ersetzt. Schlägt der Sync fehl, findet kein Swap statt.','ev-groups'),
                                    '<code>'.esc_html($nightly_p).'</code>',
                                    '<code>'.esc_html($live_p).'</code>'
                                );
                                $last_swap = get_option('evg_last_table_swap','');
                                if ($last_swap) {
                                    echo ' <strong>'.esc_html__('Letzter Swap:','ev-groups').' '.esc_html($last_swap).'</strong>';
                                }
                                ?>
                            </p>
                        </td>
                    </tr>                    <tr>
                        <th><?php esc_html_e('Empfänger Sync-Protokoll','ev-groups'); ?></th>
                        <td>
                            <?php $report_email = get_option('evg_sync_report_email',''); ?>
                            <input type="email" name="evg_sync_report_email" class="regular-text" value="<?php echo esc_attr($report_email); ?>">
                            <p class="description"><?php esc_html_e('Lässt sich optional nutzen, um Nachtlauf-Protokolle an eine alternative Adresse zu senden. Leer lassen = Admin-E-Mail.','ev-groups'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('EasyVerein OIDC – Web-Login für WordPress','ev-groups'); ?></h2>
                <p class="description"><?php esc_html_e('Ermöglicht die Anmeldung am WP-Backend und Frontend via EasyVerein. Die Callback-URL muss im EasyVerein Identity Provider als Redirect URI eingetragen sein.','ev-groups'); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Web-Login aktivieren','ev-groups'); ?></th>
                        <td><label><input type="checkbox" name="evg_oidc_web_login_enabled" value="1" <?php checked(1,(int)get_option(EVG_Oidc::OPT_WEB_LOGIN,0)); ?>>
                            <?php esc_html_e('„Mit EasyVerein anmelden"-Button auf der WP-Login-Seite anzeigen','ev-groups'); ?></label></td>
                    </tr>
                    <tr>
                        <th>OIDC Client-ID</th>
                        <td><input type="text" name="evg_oidc_client_id" class="regular-text" value="<?php echo esc_attr(get_option(EVG_Oidc::OPT_CLIENT_ID,'')); ?>" placeholder="kTkkaLFd1Qdw…">
                            <p class="description"><?php esc_html_e('Dieselbe Client-ID wie in der mobilen App (Public Client, kein Secret erforderlich).','ev-groups'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Callback-URL','ev-groups'); ?></th>
                        <td>
                            <?php $oidc_inst = new EVG_Oidc(); $cb_url = $oidc_inst->callback_url(); ?>
                            <input type="text" name="evg_oidc_redirect_uri" class="regular-text" value="<?php echo esc_attr(get_option(EVG_Oidc::OPT_REDIRECT_URI,'')); ?>" placeholder="<?php echo esc_attr($cb_url); ?>">
                            <p class="description"><?php printf(esc_html__('Standard (automatisch): %s – Diese URL in EasyVerein als Redirect URI hinterlegen.','ev-groups'), '<code>'.esc_html($cb_url).'</code>'); ?></p>
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
                    <button id="evg-sync-stop" class="button" style="display:none;color:#dc2626;border-color:#dc2626"><?php echo esc_html__('Stop','ev-groups'); ?></button>
                    <button id="evg-test-conn" class="button"><?php echo esc_html__('Verbindung testen','ev-groups'); ?></button>
                    <span id="evg-test-conn-out" style="margin-left:8px;opacity:.8"></span>
                </p>
                <div style="height:12px;background:#e5e7eb;border-radius:6px;overflow:hidden"><span id="evg-bar" style="display:block;height:100%;background:#10b981;width:0%"></span></div>
                <pre id="evg-log" style="max-height:220px;overflow:auto;background:#0b1020;color:#d6deff;padding:8px;border-radius:6px;margin-top:8px"></pre>
            </div>

            <div class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px;margin-top:12px">
                <h2><?php esc_html_e('WP-Benutzer aus Mitgliederdaten anlegen / aktualisieren','ev-groups'); ?></h2>
                <p><?php esc_html_e('Legt für jedes Mitglied mit E-Mail-Adresse einen WordPress-Benutzer an (oder aktualisiert bestehende). Die Gruppenberechtigungen werden übernommen. Login erfolgt ausschließlich per EasyVerein OIDC.','ev-groups'); ?></p>
                <p>
                    <button id="evg-usersync-start" class="button button-primary"><?php esc_html_e('WP-Benutzer jetzt synchronisieren','ev-groups'); ?></button>
                    <button id="evg-usersync-dryrun" class="button" style="margin-left:8px"><?php esc_html_e('Testlauf (keine Änderungen)','ev-groups'); ?></button>
                    <label style="margin-left:16px">
                        <input type="checkbox" id="evg-usersync-welcome">
                        <?php esc_html_e('Willkommens-E-Mail an neu angelegte User senden','ev-groups'); ?>
                    </label>
                </p>
                <pre id="evg-usersync-log" style="max-height:180px;overflow:auto;background:#0b1020;color:#d6deff;padding:8px;border-radius:6px;margin-top:8px;display:none"></pre>
                <script>
                (function(){
                    var ajax = ajaxurl;
                    var n    = <?php echo wp_json_encode(wp_create_nonce('evg_sync')); ?>;
                    var log  = document.getElementById('evg-usersync-log');
                    var timer;
                    function run(dry) {
                        log.style.display = 'block';
                        log.textContent   = dry ? 'Starte Testlauf…' : 'Starte Sync…';
                        var welcome = document.getElementById('evg-usersync-welcome').checked ? 1 : 0;
                        var elapsed = 0;
                        clearInterval(timer);
                        timer = setInterval(function(){
                            elapsed++;
                            log.textContent = (dry ? 'Testlauf läuft… ' : 'Sync läuft… ') + elapsed + 's';
                        }, 1000);
                        jQuery.ajax({
                            url:     ajax,
                            method:  'POST',
                            timeout: 600000, // 10 Minuten
                            data: {
                                action:       'evg_wp_usersync_start',
                                _wpnonce:     n,
                                dry_run:      dry ? 1 : 0,
                                send_welcome: welcome
                            },
                            success: function(r) {
                                clearInterval(timer);
                                if (r && r.success) {
                                    var d = r.data;
                                    var prefix = d.dry_run ? '[TESTLAUF] ' : '';
                                    log.textContent = prefix +
                                        'Gesamt: ' + d.total +
                                        ' | Neu: ' + d.created +
                                        ' | Aktualisiert: ' + d.updated +
                                        ' | Übersprungen (keine E-Mail): ' + d.skipped +
                                        (d.duplicates ? ' | Doppelte E-Mail (älteste Person behalten): ' + d.duplicates : '') +
                                        ' | Fehler: ' + d.errors;
                                } else {
                                    log.textContent = 'Fehler: ' + JSON.stringify(r);
                                }
                            },
                            error: function(xhr, status) {
                                clearInterval(timer);
                                if (status === 'timeout') {
                                    log.textContent = 'Timeout – der Sync läuft möglicherweise noch auf dem Server (PHP set_time_limit). Seite nach 1–2 Min. neu laden und prüfen ob User angelegt wurden.';
                                } else {
                                    log.textContent = 'Netzwerkfehler (Status: ' + xhr.status + ' ' + xhr.statusText + ')';
                                }
                            }
                        });
                    }
                    document.getElementById('evg-usersync-start').onclick  = function(e) { e.preventDefault(); run(false); };
                    document.getElementById('evg-usersync-dryrun').onclick = function(e) { e.preventDefault(); run(true);  };
                })();
                </script>
            </div>

            <div class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px;margin-top:12px">
                <h2><?php echo esc_html__('Nächtlichen Sync simulieren','ev-groups'); ?></h2>
                <p>
                    <?php
                    $nightly_prefix_display = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly')) ?: 'evg_nightly';
                    printf(
                        esc_html__('Führt den vollständigen nächtlichen Sync manuell aus – schreibt in die Spiegel-Tabellen mit Präfix „%s". Die Live-Daten werden nicht verändert.','ev-groups'),
                        '<code>'.esc_html($nightly_prefix_display).'</code>'
                    );
                    ?>
                </p>
                <p>
                    <button id="evg-nightly-sim" class="button button-primary"><?php echo esc_html__('Nächtlichen Sync jetzt simulieren','ev-groups'); ?></button>
                    <span id="evg-nightly-sim-out" style="margin-left:8px;opacity:.8"></span>
                </p>
                <div style="height:12px;background:#e5e7eb;border-radius:6px;overflow:hidden"><span id="evg-nightly-bar" style="display:block;height:100%;background:#6366f1;width:0%"></span></div>
                <pre id="evg-nightly-log" style="max-height:220px;overflow:auto;background:#0b1020;color:#d6deff;padding:8px;border-radius:6px;margin-top:8px"></pre>
            </div>

            <div class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px;margin-top:12px">
                <h2><?php esc_html_e('Nightly → Live übernehmen (manueller Swap)','ev-groups'); ?></h2>
                <?php
                $swap_live_p    = evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg')) ?: 'evg';
                $swap_nightly_p = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly')) ?: 'evg_nightly';
                $last_swap_ts   = get_option('evg_last_table_swap','');
                ?>
                <p>
                    <?php printf(
                        esc_html__('Ersetzt die Live-Tabellen (Präfix „%1$s") atomar durch die zuletzt synchronisierten Nightly-Tabellen (Präfix „%2$s"). Der Vorgang ist nicht umkehrbar – die alten Live-Tabellen werden danach gelöscht.','ev-groups'),
                        '<code>'.esc_html($swap_live_p).'</code>',
                        '<code>'.esc_html($swap_nightly_p).'</code>'
                    ); ?>
                </p>
                <?php if ($last_swap_ts): ?>
                <p><strong><?php esc_html_e('Letzter Swap:','ev-groups'); ?></strong> <?php echo esc_html($last_swap_ts); ?></p>
                <?php endif; ?>
                <p>
                    <button id="evg-table-swap" class="button button-primary" style="background:#d52623;border-color:#b91c1c;color:#fff">
                        <?php esc_html_e('Tabellen jetzt übernehmen','ev-groups'); ?>
                    </button>
                    <span id="evg-table-swap-out" style="margin-left:10px;opacity:.9;font-weight:600"></span>
                </p>
                <script>
                (function(){
                    document.getElementById('evg-table-swap').addEventListener('click', function(e){
                        e.preventDefault();
                        var msg = <?php echo wp_json_encode(__('Achtung: Die Live-Tabellen werden durch die Nightly-Tabellen ersetzt. Die alten Live-Daten gehen verloren. Wirklich fortfahren?','ev-groups')); ?>;
                        if (!window.confirm(msg)) { return; }
                        var out = document.getElementById('evg-table-swap-out');
                        out.style.color = '';
                        out.textContent = <?php echo wp_json_encode(__('Swap läuft …','ev-groups')); ?>;
                        jQuery.post(ajaxurl, {
                            action:         'evg_table_swap',
                            _wpnonce:       <?php echo wp_json_encode(wp_create_nonce('evg_sync')); ?>,
                            nightly_prefix: <?php echo wp_json_encode($swap_nightly_p); ?>,
                            live_prefix:    <?php echo wp_json_encode($swap_live_p); ?>
                        }, function(r){
                            if (r && r.success) {
                                out.style.color = '#16a34a';
                                out.textContent = '✓ ' + r.data.message;
                            } else {
                                out.style.color = '#dc2626';
                                out.textContent = '✗ ' + (r && r.data ? r.data.message : 'Unbekannter Fehler');
                            }
                        }).fail(function(){ out.style.color='#dc2626'; out.textContent='Netzwerkfehler'; });
                    });
                })();
                </script>
            </div>
        <script>
        (function(){
            var EVGAdmin = <?php echo wp_json_encode([
                'nonce'         => wp_create_nonce('evg_sync'),
                'tickPause'     => (int) get_option('evg_tick_pause_ms', 900),
                'manualPrefix'  => evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix', 'evg')) ?: 'evg',
                'nightlyPrefix' => evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix', 'evg_nightly')) ?: 'evg_nightly',
            ]); ?>;
            const ajax      = ajaxurl;
            const n1        = EVGAdmin.nonce;
            const tickPause = EVGAdmin.tickPause || 900;

            // Erstellt einen unabhängigen Sync-Kontext für einen bestimmten Fortschrittsbalken + Log.
            function makeSyncContext(barEl, logEl, stopBtn) {
                let activePrefix = '';
                let finished     = false;
                let stopped      = false;
                function push(line) {
                    const ts = new Date().toLocaleTimeString();
                    logEl.textContent = '['+ts+'] '+line+'\n'+logEl.textContent;
                }
                function setP(p, txt) {
                    if (p < 0) p = 0; if (p > 100) p = 100;
                    barEl.style.width = p.toFixed(1)+'%';
                    if (txt) push(txt+' ('+p.toFixed(1)+'%)');
                }
                function showStop(show) {
                    if (stopBtn) stopBtn.style.display = show ? '' : 'none';
                }
                function tick() {
                    if (stopped) { showStop(false); return; }
                    jQuery.post(ajax, {action:'evg_sync_tick', _wpnonce:n1, prefix:activePrefix}, function(r) {
                        if (stopped) { showStop(false); return; }
                        if (r && r.success) {
                            const d = r.data || {};
                            setP(d.percent || 0, d.label || '…');
                            if (d.done) {
                                if (!finished) { finished = true; push('Abgeschlossen ['+activePrefix+']'); }
                                showStop(false);
                            } else {
                                setTimeout(tick, tickPause);
                            }
                        }
                    });
                }
                if (stopBtn) {
                    stopBtn.onclick = function(e) {
                        e.preventDefault();
                        stopped = true;
                        showStop(false);
                        push('Manuell gestoppt ['+activePrefix+']');
                    };
                }
                return function startJob(prefix, cap) {
                    activePrefix = prefix || 'evg';
                    finished     = false;
                    stopped      = false;
                    showStop(true);
                    const payload = {action:'evg_sync_start', _wpnonce:n1, prefix:activePrefix};
                    if (cap) payload.cap = cap;
                    jQuery.post(ajax, payload, function(r) {
                        if (r && r.success) {
                            const sp = r.data && r.data.prefix ? r.data.prefix : activePrefix;
                            activePrefix = sp || activePrefix;
                            push((cap ? 'Quick-Job ('+cap+')' : 'Job')+' gestartet ['+activePrefix+']');
                            setP(0, 'Start');
                            tick();
                        }
                    });
                };
            }

            const manualSync  = makeSyncContext(
                document.getElementById('evg-bar'),
                document.getElementById('evg-log'),
                document.getElementById('evg-sync-stop')
            );
            const nightlySync = makeSyncContext(
                document.getElementById('evg-nightly-bar'),
                document.getElementById('evg-nightly-log')
            );

            const tOut = document.getElementById('evg-test-conn-out');
            document.getElementById('evg-test-conn').onclick = function(e) {
                e.preventDefault(); tOut.textContent = 'teste…';
                jQuery.post(ajax, {action:'evg_test_connection', _wpnonce:n1}, function(r) {
                    tOut.textContent = (r && r.success && r.data && r.data.message) ? r.data.message : 'OK';
                }).fail(function() { tOut.textContent = 'Netzwerkfehler'; });
            };

            document.getElementById('evg-sync-start').onclick    = function(e) { e.preventDefault(); manualSync(EVGAdmin.manualPrefix); };
            document.getElementById('evg-sync-quick10').onclick   = function(e) { e.preventDefault(); manualSync(EVGAdmin.manualPrefix, 10); };
            document.getElementById('evg-nightly-sim').onclick    = function(e) { e.preventDefault(); nightlySync(EVGAdmin.nightlyPrefix); };
        })();
        </script>

        <?php
        // ── Nightly-Log-Viewer ───────────────────────────────────────────────
        $log_dir = WP_CONTENT_DIR.'/easyverein-debug';
        $log_files = [];
        if (is_dir($log_dir)) {
            foreach (glob($log_dir.'/nightly-*.log') ?: [] as $f) {
                $log_files[] = $f;
            }
            rsort($log_files); // neueste zuerst
        }
        ?>
        <div class="card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;max-width:900px;margin-top:12px">
            <h2><?php esc_html_e('Nighty-Sync Logs','ev-groups'); ?></h2>
            <?php if (empty($log_files)): ?>
                <p><?php esc_html_e('Noch kein Nightly-Log vorhanden. Log-Datei wird beim ersten nächtlichen Lauf angelegt.','ev-groups'); ?></p>
            <?php else: ?>
            <p>
                <select id="evg-log-select" style="max-width:400px">
                <?php foreach (array_slice($log_files, 0, 10) as $f): ?>
                    <option value="<?php echo esc_attr(basename($f)); ?>"><?php echo esc_html(basename($f)); ?></option>
                <?php endforeach; ?>
                </select>
                <button type="button" class="button" id="evg-log-load"><?php esc_html_e('Laden','ev-groups'); ?></button>
                <span id="evg-log-status" style="margin-left:8px;color:#666;"></span>
            </p>
            <textarea id="evg-log-content" readonly style="width:100%;height:400px;font-family:monospace;font-size:12px;background:#0d1117;color:#e6edf3;border:1px solid #30363d;resize:vertical;padding:8px;"></textarea>
            <script>
            (function(){
                var sel  = document.getElementById('evg-log-select');
                var btn  = document.getElementById('evg-log-load');
                var txt  = document.getElementById('evg-log-content');
                var stat = document.getElementById('evg-log-status');
                var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var n    = <?php echo wp_json_encode(wp_create_nonce('evg_sync')); ?>;
                function load() {
                    stat.textContent = 'Lade…';
                    txt.value = '';
                    jQuery.post(ajax, {action:'evg_nightly_log', _wpnonce:n, file: sel.value}, function(r){
                        if (r && r.success) {
                            txt.value = r.data.content || '(leer)';
                            txt.scrollTop = txt.scrollHeight;
                            stat.textContent = '';
                        } else {
                            stat.textContent = 'Fehler beim Laden';
                        }
                    }).fail(function(){ stat.textContent = 'Netzwerkfehler'; });
                }
                btn.onclick = load;
                load();
            })();
            </script>
            <?php endif; ?>
        </div>
    <?php }

    public function admin_bar_version($wp_admin_bar){
        if (!current_user_can('manage_options')) return;
        $wp_admin_bar->add_node([
            'id'    => 'evg-version',
            'title' => 'EVG ' . EVG_VERSION,
            'href'  => admin_url('admin.php?page='.EVG_SLUG),
            'meta'  => ['title' => 'Easyverein Go ' . EVG_VERSION],
        ]);
    }

    public function enqueue_admin_assets($hook){
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') return;
        wp_enqueue_style('evg-admin-picker', EVG_URL.'assets/admin/evg-picker.css', [], EVG_VERSION);
        wp_enqueue_script('evg-admin-picker', EVG_URL.'assets/admin/evg-picker.js', ['jquery'], EVG_VERSION, true);
    }

    public function user_groups_fields($user){
        // Nur WP-Administratoren dürfen Gruppen-Zuordnungen sehen und ändern.
        // Normale Mitglieder (z.B. per OIDC eingeloggt) sollen ihr eigenes Profil
        // nicht manipulieren können.
        if (!current_user_can('manage_options')) return;
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
        if (!current_user_can('manage_options')) return;
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

    public function ajax_nightly_log(){
        check_ajax_referer('evg_sync');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);        $file = isset($_POST['file']) ? sanitize_file_name(wp_unslash($_POST['file'])) : '';
        if (!preg_match('/^nightly-[0-9]{8}-[0-9]{6}\.log$/', $file)) {
            wp_send_json_error(['message'=>'Ungültiger Dateiname']);
        }
        $path = WP_CONTENT_DIR.'/easyverein-debug/'.$file;
        if (!file_exists($path)) {
            wp_send_json_error(['message'=>'Datei nicht gefunden']);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            wp_send_json_error(['message'=>'Datei nicht lesbar']);
        }
        wp_send_json_success(['content' => $content]);
    }

    public function ajax_test_connection(){
        check_ajax_referer('evg_sync'); if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);
        $base=rtrim((string)get_option('evg_api_base',''),'/');
        $members=(string)get_option('evg_members_path','/api/v3.0/member');
        $h=$this->headers();
        $r=evg_http_get($base.$members,$h);
        $c=is_wp_error($r)?'ERR':wp_remote_retrieve_response_code($r);
        $msg = 'Members: '.$c.($c===200?' – OK':($c===404?' – Endpoint falsch':''));
        wp_send_json_success(['message'=>$msg]);
    }

    private function sanitize_prefix_from_request($value){
        $manual = evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg'));
        if ($manual === '') {
            $manual = 'evg';
        }
        $allowed = [$manual];
        if ($manual !== 'evg'){
            $allowed[] = 'evg';
        }
        $nightly = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly'));
        if ($nightly !== '' && !in_array($nightly, $allowed, true)){
            $allowed[] = $nightly;
        }
        $prefix = evg_sanitize_table_prefix((string)$value);
        if ($prefix === '' || !in_array($prefix, $allowed, true)){
            return $manual;
        }
        return $prefix;
    }

    public function ajax_sync_start(){
        check_ajax_referer('evg_sync');
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);
        $cap = isset($_POST['cap']) ? max(0, intval($_POST['cap'])) : 0;
        $requested_prefix = isset($_POST['prefix']) ? wp_unslash($_POST['prefix']) : '';
        $prefix = $this->sanitize_prefix_from_request($requested_prefix);
        if (class_exists('EVG_Plugin')){
            EVG_Plugin::ensure_schema_for_prefix($prefix);
        }
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

    public function ajax_table_swap(){
        check_ajax_referer('evg_sync');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'no capability'],403);

        $allowed_nightly = evg_sanitize_table_prefix(get_option('evg_nightly_sync_table_prefix','evg_nightly'));
        $allowed_live    = evg_sanitize_table_prefix(get_option('evg_manual_sync_table_prefix','evg'));
        if ($allowed_nightly === '') { $allowed_nightly = 'evg_nightly'; }
        if ($allowed_live    === '') { $allowed_live    = 'evg'; }

        $req_nightly = evg_sanitize_table_prefix(isset($_POST['nightly_prefix']) ? wp_unslash($_POST['nightly_prefix']) : '');
        $req_live    = evg_sanitize_table_prefix(isset($_POST['live_prefix'])    ? wp_unslash($_POST['live_prefix'])    : '');

        // Nur die konfigurierten Präfixe akzeptieren – keine Freitexteingaben
        if ($req_nightly !== $allowed_nightly || $req_live !== $allowed_live) {
            wp_send_json_error(['message' => 'Ungültiger Präfix-Parameter.']);
        }

        $result = EVG_Plugin::run_manual_table_swap($req_nightly, $req_live);
        if ($result['ok']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
