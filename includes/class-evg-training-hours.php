<?php
/**
 * EVG_Training_Hours – Übungsleiter-Stundenerfassung
 *
 * REST-Endpoints (Namespace: easyverein-go/v1):
 *   GET    /training-hours            – Eigene Stunden (month=YYYY-MM)
 *   POST   /training-hours            – Neuen Eintrag anlegen
 *   DELETE /training-hours/{id}       – Eigenen Pending-Eintrag löschen
 *   GET    /training-groups           – Verfügbare Gruppen für Dropdown
 *
 * Admin-Seite: Freigabe durch manage_options
 */
if (!defined('ABSPATH')) { exit; }

class EVG_Training_Hours {

    const TABLE_SUFFIX = 'evg_training_hours';

    public function __construct() {
        add_action('rest_api_init',            [$this, 'register_endpoints']);
        add_action('admin_menu',               [$this, 'admin_menu'], 30);
        add_action('admin_post_evg_th_action', [$this, 'handle_admin_action']);
        add_action('show_user_profile',        [$this, 'user_profile_field']);
        add_action('edit_user_profile',        [$this, 'user_profile_field']);
        add_action('personal_options_update',  [$this, 'save_user_profile_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_field']);
        add_action('admin_enqueue_scripts',    [$this, 'enqueue_admin_assets']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Datenbank
    // ─────────────────────────────────────────────────────────────────────────

    public static function ensure_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id    BIGINT UNSIGNED NOT NULL,
            member_name   VARCHAR(200)    NOT NULL DEFAULT '',
            group_ev_id   VARCHAR(191)    NOT NULL DEFAULT '',
            group_name    VARCHAR(200)    NOT NULL DEFAULT '',
            training_date DATE            NOT NULL,
            hours         DECIMAL(4,1)    NOT NULL DEFAULT 1.0,
            note          TEXT            NULL,
            status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
            admin_note    TEXT            NULL,
            approved_by   BIGINT UNSIGNED NULL,
            approved_at   DATETIME        NULL,
            paid_at       DATETIME        NULL,
            created_at    DATETIME        NOT NULL,
            updated_at    DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_user   (wp_user_id),
            KEY idx_date   (training_date),
            KEY idx_status (status)
        ) {$charset};");
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REST-Endpoints
    // ─────────────────────────────────────────────────────────────────────────

    public function register_endpoints() {
        $is_auth = function() {
            return is_user_logged_in();
        };
        $is_uebungsleiter = function() {
            return is_user_logged_in() && $this->current_user_is_uebungsleiter();
        };

        register_rest_route('easyverein-go/v1', '/training-hours', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_hours'],
                'permission_callback' => $is_auth,
                'args' => [
                    'month' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => 'Monat im Format YYYY-MM',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_post_hour'],
                'permission_callback' => $is_uebungsleiter,
                'args' => [
                    'group_ev_id'   => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'group_name'    => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'training_date' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'hours'         => ['required' => true,  'type' => 'number',  'minimum' => 0.5, 'maximum' => 24],
                    'note'          => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                ],
            ],
        ]);

        register_rest_route('easyverein-go/v1', '/training-hours/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'rest_delete_hour'],
            'permission_callback' => $is_uebungsleiter,
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ],
        ]);

        register_rest_route('easyverein-go/v1', '/training-groups', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_groups'],
            'permission_callback' => $is_uebungsleiter,
        ]);

        $is_approver = function() {
            return is_user_logged_in() && $this->current_user_can_approve();
        };

        register_rest_route('easyverein-go/v1', '/training-hours/approvals', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_approvals'],
            'permission_callback' => $is_approver,
            'args' => [
                'status'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'month'       => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'group_ev_id' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'member_name' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('easyverein-go/v1', '/training-hours/bulk-action', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'rest_bulk_action'],
            'permission_callback' => $is_approver,
            'args' => [
                'action'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'ids'        => ['required' => true,  'type' => 'array',  'items' => ['type' => 'integer']],
                'admin_note' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REST Callbacks
    // ─────────────────────────────────────────────────────────────────────────

    public function rest_get_hours( WP_REST_Request $req ) {
        global $wpdb;
        $user_id  = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $month    = $req->get_param('month');

        $where  = $is_admin ? '' : $wpdb->prepare(' AND wp_user_id = %d', $user_id);
        $params = [];

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $where .= ' AND YEAR(training_date) = %d AND MONTH(training_date) = %d';
            [$y, $m] = explode('-', $month);
            $params[] = (int)$y;
            $params[] = (int)$m;
        }

        $sql = "SELECT * FROM {$this->table()} WHERE 1=1 {$where} ORDER BY training_date DESC, id DESC";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $total_hours = 0.0;
        $items = array_map(function($r) use (&$total_hours) {
            $total_hours += (float)$r['hours'];
            return [
                'id'            => (int)$r['id'],
                'wp_user_id'    => (int)$r['wp_user_id'],
                'member_name'   => $r['member_name'],
                'group_ev_id'   => $r['group_ev_id'],
                'group_name'    => $r['group_name'],
                'training_date' => $r['training_date'],
                'hours'         => (float)$r['hours'],
                'note'          => $r['note'],
                'status'        => $r['status'],
                'admin_note'    => $r['admin_note'],
                'created_at'    => $r['created_at'],
            ];
        }, $rows ?: []);

        return new WP_REST_Response([
            'items'       => $items,
            'total_hours' => round($total_hours, 1),
        ], 200);
    }

    public function rest_post_hour( WP_REST_Request $req ) {
        global $wpdb;
        $user    = wp_get_current_user();
        $date_raw = $req->get_param('training_date');

        // Datum validieren
        $dt = DateTime::createFromFormat('Y-m-d', $date_raw);
        if (!$dt || $dt->format('Y-m-d') !== $date_raw) {
            return new WP_Error('invalid_date', 'Ungültiges Datum (YYYY-MM-DD erwartet)', ['status' => 400]);
        }
        // Nicht in der Zukunft – Vergleich in WP-Timezone (nicht PHP/UTC)
        if ($date_raw > current_time('Y-m-d')) {
            return new WP_Error('future_date', 'Datum darf nicht in der Zukunft liegen', ['status' => 400]);
        }

        $now = current_time('mysql');
        $wpdb->insert($this->table(), [
            'wp_user_id'    => get_current_user_id(),
            'member_name'   => $user->display_name,
            'group_ev_id'   => $req->get_param('group_ev_id'),
            'group_name'    => $req->get_param('group_name'),
            'training_date' => $date_raw,
            'hours'         => round((float)$req->get_param('hours'), 1),
            'note'          => $req->get_param('note') ?: null,
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        if (!$wpdb->insert_id) {
            return new WP_Error('db_error', 'Eintrag konnte nicht gespeichert werden', ['status' => 500]);
        }

        return new WP_REST_Response(['id' => $wpdb->insert_id, 'status' => 'pending'], 201);
    }

    public function rest_delete_hour( WP_REST_Request $req ) {
        global $wpdb;
        $id      = (int)$req->get_param('id');
        $user_id = get_current_user_id();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, wp_user_id, status FROM {$this->table()} WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$row) {
            return new WP_Error('not_found', 'Eintrag nicht gefunden', ['status' => 404]);
        }
        if ((int)$row['wp_user_id'] !== $user_id && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Kein Zugriff', ['status' => 403]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('not_deletable', 'Nur Einträge mit Status "erfasst" können gelöscht werden', ['status' => 409]);
        }

        $wpdb->delete($this->table(), ['id' => $id]);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    public function rest_get_groups( WP_REST_Request $req ) {
        global $wpdb;
        $g_table = $wpdb->prefix . 'evg_groups';
        $rows    = $wpdb->get_results(
            "SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM {$g_table} ORDER BY label ASC",
            ARRAY_A
        );
        $groups = array_map(function($r) {
            return ['group_ev_id' => (string)$r['group_id'], 'label' => (string)$r['label']];
        }, $rows ?: []);
        return new WP_REST_Response($groups, 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin-Seite
    // ─────────────────────────────────────────────────────────────────────────

    public function admin_menu() {
        add_submenu_page(
            EVG_SLUG,
            'Stunden freigeben',
            'Stunden freigeben',
            'manage_options',
            'evg-training-hours',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'easyverein-go_page_evg-training-hours') return;
        wp_enqueue_style('evg-th-admin', EVG_URL . 'assets/admin/evg-training-hours.css', [], EVG_VERSION);
    }

    public function admin_page() {
        global $wpdb;

        // Filter-Parameter
        $month  = isset($_GET['month'])  ? sanitize_text_field($_GET['month'])  : date('Y-m');
        $status = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'pending';

        // Monatsliste für Auswahl (letzte 12 Monate)
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = date('Y-m', strtotime("-{$i} months"));
        }

        // Daten laden
        $where_parts = [];
        $params      = [];
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            [$y, $m] = explode('-', $month);
            $where_parts[] = 'YEAR(training_date) = %d AND MONTH(training_date) = %d';
            $params[]      = (int)$y;
            $params[]      = (int)$m;
        }
        if (in_array($status, ['pending', 'approved', 'paid', 'rejected', ''], true) && $status !== '') {
            $where_parts[] = 'status = %s';
            $params[]      = $status;
        }

        $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
        $sql       = "SELECT * FROM {$this->table()} {$where_sql} ORDER BY training_date ASC, id ASC";
        $rows      = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        $rows = $rows ?: [];

        // Anzahl ausstehender
        $pending_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE status='pending'");

        // Feedback
        $feedback = '';
        if (!empty($_GET['evg_th_msg'])) {
            $msg = sanitize_text_field($_GET['evg_th_msg']);
            $feedback = "<div class='notice notice-success is-dismissible'><p>{$msg}</p></div>";
        }

        $nonce = wp_create_nonce('evg_th_action');
        ?>
        <div class="wrap evg-th-wrap">
            <h1>Stunden freigeben
                <?php if ($pending_count): ?>
                    <span class="evg-th-badge"><?= (int)$pending_count ?></span>
                <?php endif; ?>
            </h1>
            <?= $feedback ?>

            <form method="get" class="evg-th-filters">
                <input type="hidden" name="page" value="evg-training-hours">
                <select name="month">
                    <?php foreach ($months as $mo): ?>
                        <option value="<?= esc_attr($mo) ?>" <?= selected($month, $mo, false) ?>>
                            <?= esc_html(date('F Y', strtotime($mo . '-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="filter">
                    <option value="pending"  <?= selected($status, 'pending',  false) ?>>Ausstehend</option>
                    <option value="approved" <?= selected($status, 'approved', false) ?>>Freigegeben</option>
                    <option value="paid"     <?= selected($status, 'paid',     false) ?>>Ausgezahlt</option>
                    <option value="rejected" <?= selected($status, 'rejected', false) ?>>Abgelehnt</option>
                    <option value=""         <?= selected($status, '',         false) ?>>Alle</option>
                </select>
                <button type="submit" class="button">Filtern</button>
            </form>

            <?php if (empty($rows)): ?>
                <p>Keine Einträge für diesen Filter.</p>
            <?php else: ?>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <input type="hidden" name="action" value="evg_th_action">
                    <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonce) ?>">
                    <input type="hidden" name="redirect_month" value="<?= esc_attr($month) ?>">
                    <input type="hidden" name="redirect_filter" value="<?= esc_attr($status) ?>">

                    <table class="wp-list-table widefat fixed striped evg-th-table">
                        <thead>
                            <tr>
                                <td class="check-column"><input type="checkbox" id="evg-th-check-all"></td>
                                <th>Übungsleiter</th>
                                <th>Gruppe</th>
                                <th>Datum</th>
                                <th>Stunden</th>
                                <th>Status</th>
                                <th>Notiz</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="check-column">
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?= esc_html($r['member_name']) ?></td>
                                <td><?= esc_html($r['group_name']) ?></td>
                                <td><?= esc_html(date('d.m.Y', strtotime($r['training_date']))) ?></td>
                                <td><?= number_format((float)$r['hours'], 1, ',', '') ?>h</td>
                                <td><span class="evg-th-status evg-th-status--<?= esc_attr($r['status']) ?>">
                                    <?= esc_html($this->status_label($r['status'])) ?>
                                </span></td>
                                <td><?= esc_html($r['note'] ?? '') ?><?php if ($r['admin_note']): ?><br><em><?= esc_html($r['admin_note']) ?></em><?php endif; ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <button type="submit" name="bulk_action" value="approve_one_<?= (int)$r['id'] ?>" class="button button-small button-primary">✓</button>
                                        <button type="submit" name="bulk_action" value="reject_one_<?= (int)$r['id'] ?>"  class="button button-small" onclick="return evgRejectConfirm(this)">✗</button>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <button type="submit" name="bulk_action" value="pay_one_<?= (int)$r['id'] ?>" class="button button-small">💰 Ausgezahlt</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($status === 'pending'): ?>
                        <div class="evg-th-bulk-actions">
                            <label><input type="checkbox" id="evg-th-check-all2"> Alle auswählen</label>
                            <button type="submit" name="bulk_action" value="approve_selected" class="button button-primary">✓ Markierte freigeben</button>
                            <button type="submit" name="bulk_action" value="reject_selected"  class="button" onclick="return evgRejectConfirmBulk(this)">✗ Markierte ablehnen</button>
                        </div>
                    <?php elseif ($status === 'approved'): ?>
                        <div class="evg-th-bulk-actions">
                            <label><input type="checkbox" id="evg-th-check-all2"> Alle auswählen</label>
                            <button type="submit" name="bulk_action" value="pay_selected" class="button button-primary">💰 Markierte als ausgezahlt markieren</button>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="admin_note_input" id="evg-admin-note" value="">
                </form>

                <?php
                // Summen-Tabelle
                $sums = [];
                foreach ($rows as $r) {
                    $key = $r['member_name'];
                    $sums[$key] = ($sums[$key] ?? 0) + (float)$r['hours'];
                }
                arsort($sums);
                ?>
                <h3 style="margin-top:30px">Summen für <?= esc_html(date('F Y', strtotime($month . '-01'))) ?></h3>
                <table class="wp-list-table widefat fixed" style="max-width:400px">
                    <thead><tr><th>Übungsleiter</th><th>Gesamt</th></tr></thead>
                    <tbody>
                    <?php foreach ($sums as $name => $h): ?>
                        <tr><td><?= esc_html($name) ?></td><td><?= number_format($h, 1, ',', '') ?>h</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:20px">
                    <a href="<?= esc_url(add_query_arg([
                        'page' => 'evg-training-hours',
                        'evg_export' => '1',
                        'month' => $month,
                        'filter' => $status,
                        '_wpnonce' => wp_create_nonce('evg_th_export'),
                    ], admin_url('admin.php'))) ?>" class="button">📥 CSV exportieren</a>
                </p>
            <?php endif; ?>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var allCbs = document.querySelectorAll('#evg-th-check-all,#evg-th-check-all2');
            allCbs.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    document.querySelectorAll('input[name="ids[]"]').forEach(function(c){ c.checked = cb.checked; });
                });
            });
        });
        function evgRejectConfirm(btn) {
            var note = prompt('Ablehnungsgrund (Pflicht):');
            if (!note) return false;
            document.getElementById('evg-admin-note').value = note;
            return true;
        }
        function evgRejectConfirmBulk(btn) {
            var note = prompt('Ablehnungsgrund für alle markierten (Pflicht):');
            if (!note) return false;
            document.getElementById('evg-admin-note').value = note;
            return true;
        }
        </script>
        <?php

        // CSV-Export
        if (!empty($_GET['evg_export']) && check_admin_referer('evg_th_export')) {
            $this->output_csv($rows, $month);
        }
    }

    public function handle_admin_action() {
        if (!check_admin_referer('evg_th_action') || !current_user_can('manage_options')) {
            wp_die('Kein Zugriff');
        }

        global $wpdb;
        $action     = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids        = array_map('absint', (array)($_POST['ids'] ?? []));
        $admin_note = sanitize_text_field($_POST['admin_note_input'] ?? '');
        $now        = current_time('mysql');
        $admin_id   = get_current_user_id();

        $redirect_month  = sanitize_text_field($_POST['redirect_month']  ?? date('Y-m'));
        $redirect_filter = sanitize_text_field($_POST['redirect_filter'] ?? 'pending');
        $msg = '';

        // Einzelaktionen (approve_one_ID, reject_one_ID, pay_one_ID)
        if (preg_match('/^(approve|reject|pay)_one_(\d+)$/', $action, $m)) {
            $ids    = [(int)$m[2]];
            $action = $m[1] . '_selected';
        }

        $table = $this->table();

        if ($action === 'approve_selected' && !empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='approved', approved_by=%d, approved_at=%s, updated_at=%s WHERE id IN ({$placeholders}) AND status='pending'",
                array_merge([$admin_id, $now, $now], $ids)
            ));
            $msg = count($ids) . ' Eintrag/Einträge freigegeben.';

        } elseif ($action === 'reject_selected' && !empty($ids)) {
            if (!$admin_note) { wp_redirect(add_query_arg(['page' => 'evg-training-hours', 'month' => $redirect_month, 'filter' => $redirect_filter, 'evg_th_msg' => urlencode('Ablehnungsgrund fehlt.')], admin_url('admin.php'))); exit; }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='rejected', admin_note=%s, approved_by=%d, approved_at=%s, updated_at=%s WHERE id IN ({$placeholders}) AND status='pending'",
                array_merge([$admin_note, $admin_id, $now, $now], $ids)
            ));
            $msg = count($ids) . ' Eintrag/Einträge abgelehnt.';

        } elseif ($action === 'pay_selected' && !empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='paid', paid_at=%s, updated_at=%s WHERE id IN ({$placeholders}) AND status='approved'",
                array_merge([$now, $now], $ids)
            ));
            $msg = count($ids) . ' Eintrag/Einträge als ausgezahlt markiert.';
        }

        wp_redirect(add_query_arg([
            'page'          => 'evg-training-hours',
            'month'         => $redirect_month,
            'filter'        => $redirect_filter,
            'evg_th_msg'    => urlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }

    private function output_csv(array $rows, string $month) {
        // Wird nach dem Seitenaufbau via JS-Link aufgerufen – hier als separate Anfrage
        // (Sicherheitscheck bereits oben im admin_page erfolgt)
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="stunden-' . sanitize_file_name($month) . '.csv"');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM für Excel

        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Übungsleiter', 'Gruppe', 'Datum', 'Stunden', 'Status', 'Notiz', 'Admin-Notiz'], ';');
        $total = 0.0;
        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['member_name'],
                $r['group_name'],
                date('d.m.Y', strtotime($r['training_date'])),
                number_format((float)$r['hours'], 1, ',', ''),
                $this->status_label($r['status']),
                $r['note'] ?? '',
                $r['admin_note'] ?? '',
            ], ';');
            $total += (float)$r['hours'];
        }
        fputcsv($fp, ['', '', 'Gesamt:', number_format($total, 1, ',', '') . 'h', '', '', ''], ';');
        fclose($fp);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // User-Profil-Feld
    // ─────────────────────────────────────────────────────────────────────────

    public function user_profile_field($user) {
        if (!current_user_can('manage_options')) return;
        $is_ueb     = (int)get_user_meta($user->ID, 'evg_is_uebungsleiter',  true);
        $can_approve = (int)get_user_meta($user->ID, 'evg_can_approve_hours', true);
        ?>
        <h3>Easyverein Go – Übungsleiter</h3>
        <table class="form-table">
            <tr>
                <th><label for="evg_is_uebungsleiter">Ist Übungsleiter</label></th>
                <td>
                    <input type="checkbox" name="evg_is_uebungsleiter" id="evg_is_uebungsleiter" value="1" <?= checked(1, $is_ueb, false) ?>>
                    <span class="description">Darf Trainingszeiten erfassen und freigeben lassen</span>
                </td>
            </tr>
            <tr>
                <th><label for="evg_can_approve_hours">Darf erfasste Zeiten genehmigen</label></th>
                <td>
                    <input type="checkbox" name="evg_can_approve_hours" id="evg_can_approve_hours" value="1" <?= checked(1, $can_approve, false) ?>>
                    <span class="description">Sieht und genehmigt Stundeneinträge der eigenen Gruppen in der App</span>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_profile_field($user_id) {
        if (!current_user_can('manage_options')) return;
        update_user_meta($user_id, 'evg_is_uebungsleiter',  isset($_POST['evg_is_uebungsleiter'])  ? 1 : 0);
        update_user_meta($user_id, 'evg_can_approve_hours', isset($_POST['evg_can_approve_hours']) ? 1 : 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────────────

    private function current_user_is_uebungsleiter(): bool {
        $uid = get_current_user_id();
        if (!$uid) return false;
        if (current_user_can('manage_options')) return true;
        return (bool)(int)get_user_meta($uid, 'evg_is_uebungsleiter', true);
    }

    private function current_user_can_approve(): bool {
        $uid = get_current_user_id();
        if (!$uid) return false;
        if (current_user_can('manage_options')) return true;
        return (bool)(int)get_user_meta($uid, 'evg_can_approve_hours', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REST: Genehmigungsansicht
    // ─────────────────────────────────────────────────────────────────────────

    public function rest_get_approvals( WP_REST_Request $req ) {
        global $wpdb;
        $user_id   = get_current_user_id();
        $is_admin  = current_user_can('manage_options');
        $allow_all = (bool)(int)get_user_meta($user_id, 'evg_groups_all', true);
        $allowed   = array_values(array_filter(array_map('strval', (array)get_user_meta($user_id, 'evg_groups', true))));

        $where_parts = [];
        $params      = [];

        // Gruppen-Sichtbarkeit
        if (!$is_admin && !$allow_all) {
            if (empty($allowed)) {
                return new WP_REST_Response(['items' => [], 'total_hours' => 0.0, 'groups' => []], 200);
            }
            $phs         = implode(',', array_fill(0, count($allowed), '%s'));
            $where_parts[] = "group_ev_id IN ($phs)";
            $params        = array_merge($params, $allowed);
        }

        // Filter: Status
        $status = $req->get_param('status');
        if ($status && in_array($status, ['pending', 'approved', 'paid', 'rejected'], true)) {
            $where_parts[] = 'status = %s';
            $params[]      = $status;
        }

        // Filter: Monat
        $month = $req->get_param('month');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            [$y, $m]       = explode('-', $month);
            $where_parts[] = 'YEAR(training_date) = %d AND MONTH(training_date) = %d';
            $params[]      = (int)$y;
            $params[]      = (int)$m;
        }

        // Filter: Gruppe
        $group_ev_id = $req->get_param('group_ev_id');
        if ($group_ev_id) {
            $where_parts[] = 'group_ev_id = %s';
            $params[]      = $group_ev_id;
        }

        // Filter: Übungsleiter (Name)
        $member_name = $req->get_param('member_name');
        if ($member_name) {
            $where_parts[] = 'member_name LIKE %s';
            $params[]      = '%' . $wpdb->esc_like($member_name) . '%';
        }

        $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
        $sql       = "SELECT * FROM {$this->table()} {$where_sql} ORDER BY training_date DESC, id DESC";
        $rows      = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        $rows = $rows ?: [];

        $total_hours = 0.0;
        $group_map   = [];
        $items = array_map(function($r) use (&$total_hours, &$group_map) {
            $total_hours += (float)$r['hours'];
            $group_map[$r['group_ev_id']] = $r['group_name'];
            return [
                'id'            => (int)$r['id'],
                'wp_user_id'    => (int)$r['wp_user_id'],
                'member_name'   => $r['member_name'],
                'group_ev_id'   => $r['group_ev_id'],
                'group_name'    => $r['group_name'],
                'training_date' => $r['training_date'],
                'hours'         => (float)$r['hours'],
                'note'          => $r['note'],
                'status'        => $r['status'],
                'admin_note'    => $r['admin_note'],
                'created_at'    => $r['created_at'],
                'approved_at'   => $r['approved_at'] ?? null,
            ];
        }, $rows);

        $groups = array_values(array_map(
            fn($ev_id) => ['group_ev_id' => $ev_id, 'label' => $group_map[$ev_id]],
            array_keys($group_map)
        ));
        usort($groups, fn($a, $b) => strcmp($a['label'], $b['label']));

        return new WP_REST_Response([
            'items'       => $items,
            'total_hours' => round($total_hours, 1),
            'groups'      => $groups,
        ], 200);
    }

    public function rest_bulk_action( WP_REST_Request $req ) {
        global $wpdb;
        $action     = sanitize_text_field($req->get_param('action'));
        $ids        = array_filter(array_map('absint', (array)$req->get_param('ids')));
        $admin_note = sanitize_text_field($req->get_param('admin_note') ?? '');
        $now        = current_time('mysql');
        $approver   = get_current_user_id();

        if (empty($ids)) {
            return new WP_Error('no_ids', 'Keine IDs angegeben', ['status' => 400]);
        }
        if (!in_array($action, ['approve', 'reject', 'pay'], true)) {
            return new WP_Error('invalid_action', 'Ungültige Aktion', ['status' => 400]);
        }
        if ($action === 'reject' && !$admin_note) {
            return new WP_Error('note_required', 'Ablehnungsgrund ist erforderlich', ['status' => 400]);
        }

        $table = $this->table();
        $phs   = implode(',', array_fill(0, count($ids), '%d'));

        if ($action === 'approve') {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='approved', approved_by=%d, approved_at=%s, updated_at=%s WHERE id IN ({$phs}) AND status='pending'",
                array_merge([$approver, $now, $now], array_values($ids))
            ));
        } elseif ($action === 'reject') {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='rejected', admin_note=%s, approved_by=%d, approved_at=%s, updated_at=%s WHERE id IN ({$phs}) AND status='pending'",
                array_merge([$admin_note, $approver, $now, $now], array_values($ids))
            ));
        } else { // pay
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='paid', paid_at=%s, updated_at=%s WHERE id IN ({$phs}) AND status='approved'",
                array_merge([$now, $now], array_values($ids))
            ));
        }

        return new WP_REST_Response(['updated' => (int)$updated], 200);
    }

    private function status_label(string $status): string {
        return match($status) {
            'pending'  => 'Erfasst',
            'approved' => 'Freigegeben',
            'paid'     => 'Ausgezahlt',
            'rejected' => 'Abgelehnt',
            default    => $status,
        };
    }
}
