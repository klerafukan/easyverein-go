<?php
/**
 * EVG_Api – Neue REST-Endpoints und EasyVerein OIDC Token-Authentifizierung
 *
 * Endpoints (Namespace: easyverein-go/v1):
 *   GET  /me               – WP-User-Profil + Gruppen-Konfiguration
 *   GET  /groups           – Für den User sichtbare Gruppen
 *   POST /change-requests  – Änderungsanfrage einreichen
 *   GET  /change-requests  – Eigene offene Anfragen abrufen
 *
 * Authentifizierung:
 *   Bearer {ev_access_token} → /oauth2/userinfo/ → WP-User lookup/auto-provision
 */
if (!defined('ABSPATH')) { exit; }

class EVG_Api {

    /** Tabelle für Änderungsanfragen (ohne WP-Präfix). */
    const CHANGE_REQUESTS_SUFFIX = 'evg_change_requests';

    public function __construct() {
        add_filter('determine_current_user',   [$this, 'authenticate_via_ev_token'], 20);
        add_action('rest_api_init',             [$this, 'register_endpoints']);
        add_action('admin_menu',                [$this, 'admin_submenu'], 20);
        add_action('wp_ajax_evg_cr_action',     [$this, 'ajax_change_request_action']);
        add_action('admin_post_evg_cr_action',  [$this, 'ajax_change_request_action']);
        add_action('admin_enqueue_scripts',     [$this, 'enqueue_admin_assets']);
    }

    // -------------------------------------------------------------------------
    // Tabellen-Schema
    // -------------------------------------------------------------------------

    public static function ensure_change_requests_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = $wpdb->prefix . self::CHANGE_REQUESTS_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id    VARCHAR(191)    NOT NULL DEFAULT '',
            field_name   VARCHAR(128)    NOT NULL DEFAULT '',
            old_value    TEXT            NULL,
            new_value    TEXT            NOT NULL DEFAULT '',
            reason       TEXT            NULL,
            requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            requested_at DATETIME        NOT NULL,
            reviewed_by  BIGINT UNSIGNED NULL,
            reviewed_at  DATETIME        NULL,
            status       VARCHAR(16)     NOT NULL DEFAULT 'pending',
            ev_response  TEXT            NULL,
            PRIMARY KEY  (id),
            KEY idx_requested_by (requested_by),
            KEY idx_member (member_id(64)),
            KEY idx_status (status)
        ) {$charset};");
    }

    // -------------------------------------------------------------------------
    // EasyVerein OIDC Bearer-Token Authentifizierung
    // -------------------------------------------------------------------------

    /**
     * WordPress determine_current_user Filter.
     * Prüft ob ein EasyVerein Bearer-Token im Authorization-Header steckt.
     * Wenn ja: Userinfo abrufen, WP-User suchen oder anlegen.
     */
    public function authenticate_via_ev_token( $user_id ) {
        // Bereits authentifiziert → nicht überschreiben
        if ( $user_id ) {
            return $user_id;
        }
        // Nur für REST-API-Requests auswerten
        if ( ! defined('REST_REQUEST') || ! REST_REQUEST ) {
            return $user_id;
        }

        $token = $this->extract_bearer_token();
        if ( ! $token ) {
            return $user_id;
        }

        $found = $this->get_wp_user_id_for_ev_token( $token );
        return $found ?: $user_id;
    }

    /** Liest Bearer-Token aus dem Authorization-Header. */
    private function extract_bearer_token() {
        $auth = '';
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif ( function_exists('apache_request_headers') ) {
            $headers = apache_request_headers();
            $auth    = $headers['Authorization'] ?? '';
        }
        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( (string) $auth ), $m ) ) {
            return $m[1];
        }
        return null;
    }

    /**
     * Ruft EasyVerein /userinfo/ ab, legt WP-User an falls nötig, gibt WP-User-ID zurück.
     * Ergebnis wird 5 Minuten in einem Transient gecacht.
     */
    private function get_wp_user_id_for_ev_token( $token ) {
        $cache_key = 'evg_ui_' . md5( $token );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            $uid = (int) $cached;
            return ( $uid > 0 ) ? $uid : null;
        }

        $response = wp_remote_get( 'https://easyverein.com/oauth2/userinfo/', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $cache_key, 0, 60 ); // Fehler 1 Minute cachen
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $data || empty( $data['sub'] ) ) {
            set_transient( $cache_key, 0, 60 );
            return null;
        }

        $sub        = sanitize_text_field( $data['sub'] );
        $wp_user_id = $this->find_or_provision_user( $sub, $data );

        set_transient( $cache_key, $wp_user_id ?: 0, 300 ); // 5 Minuten cachen
        return $wp_user_id;
    }

    /**
     * Sucht einen WP-User anhand des EV sub-Claims.
     * Legt ihn bei Bedarf automatisch an (Auto-Provisioning).
     */
    private function find_or_provision_user( $sub, array $userinfo ) {
        // Bestehende Zuordnung suchen
        $users = get_users( [
            'meta_key'   => 'evg_oidc_sub',
            'meta_value' => $sub,
            'number'     => 1,
            'fields'     => 'ids',
        ] );
        if ( ! empty( $users ) ) {
            return (int) $users[0];
        }

        // E-Mail aus userinfo
        $email = isset( $userinfo['email'] ) ? sanitize_email( $userinfo['email'] ) : '';
        $name  = isset( $userinfo['name'] )  ? sanitize_text_field( $userinfo['name'] ) : $sub;
        $parts = explode( ' ', $name, 2 );
        $first = sanitize_text_field( $parts[0] ?? '' );
        $last  = sanitize_text_field( $parts[1] ?? '' );

        // Prüfen ob E-Mail bereits existiert → dann nur sub hinzufügen
        if ( $email ) {
            $existing = get_user_by( 'email', $email );
            if ( $existing ) {
                update_user_meta( $existing->ID, 'evg_oidc_sub', $sub );
                return $existing->ID;
            }
        }

        // Benutzername: E-Mail bevorzugen, sonst sicherer Fallback
        $username = $email ?: 'ev_' . sanitize_user( preg_replace( '/[^a-z0-9]/i', '', $sub ), true );

        // WP-User anlegen
        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 32, true, true ),
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $name,
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $user_id ) ) {
            evg_debug_log_api( [
                'url'    => 'provision_user',
                'method' => 'INTERNAL',
                'status' => 'ERR',
                'note'   => 'auto-provision failed: ' . $user_id->get_error_message(),
            ] );
            return null;
        }

        update_user_meta( $user_id, 'evg_oidc_sub', $sub );
        // Admin muss Gruppen manuell zuweisen – neuer User sieht standardmäßig nichts
        update_user_meta( $user_id, 'evg_groups_all', 0 );

        return $user_id;
    }

    // -------------------------------------------------------------------------
    // REST-Endpoint-Registrierung
    // -------------------------------------------------------------------------

    public function register_endpoints() {
        $auth = function() {
            return is_user_logged_in();
        };

        register_rest_route( 'easyverein-go/v1', '/me', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_me'],
            'permission_callback' => $auth,
        ] );

        register_rest_route( 'easyverein-go/v1', '/members', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_members'],
            'permission_callback' => $auth,
            'args' => [
                'modified_after' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'per_page'       => [ 'required' => false, 'type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 500 ],
                'offset'         => [ 'required' => false, 'type' => 'integer', 'default' => 0,   'minimum' => 0 ],
            ],
        ] );

        register_rest_route( 'easyverein-go/v1', '/groups', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_groups'],
            'permission_callback' => $auth,
        ] );

        // OAuth-Callback: EasyVerein redirectet hierher (HTTPS), wir leiten weiter zur App
        register_rest_route( 'easyverein-go/v1', '/oauth/callback', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_oauth_callback'],
            'permission_callback' => '__return_true',
        ] );

        // Öffentlich – kein Token nötig, auch vor dem Login abrufbar
        register_rest_route( 'easyverein-go/v1', '/app-version', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_app_version'],
            'permission_callback' => '__return_true',
        ] );

        // ── Termine & Kalender ───────────────────────────────────────────────
        register_rest_route( 'easyverein-go/v1', '/events', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_events'],
            'permission_callback' => $auth,
            'args' => [
                'from'     => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'to'       => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'calendar' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'limit'    => [ 'required' => false, 'type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 500 ],
                'offset'   => [ 'required' => false, 'type' => 'integer', 'default' => 0,   'minimum' => 0 ],
            ],
        ] );

        register_rest_route( 'easyverein-go/v1', '/events/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_event_detail'],
            'permission_callback' => $auth,
            'args' => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( 'easyverein-go/v1', '/calendars', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_calendars'],
            'permission_callback' => $auth,
        ] );

        register_rest_route( 'easyverein-go/v1', '/change-requests', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_change_requests'],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_post_change_request'],
                'permission_callback' => $auth,
                'args' => [
                    'member_id'  => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'field_name' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'new_value'  => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'old_value'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'reason'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // /app-version  – Öffentlich; teilt der App mit welche Mindestversion nötig ist
    // -------------------------------------------------------------------------

    public function rest_app_version(): WP_REST_Response {
        return new WP_REST_Response( [
            'min_version'  => get_option( 'evg_app_min_version',      '1.0.0' ),
            'message'      => get_option( 'evg_app_update_message',    'Bitte aktualisiere die App, um sie weiter nutzen zu können.' ),
            'ios_url'      => get_option( 'evg_app_store_ios',         '' ),
            'android_url'  => get_option( 'evg_app_store_android',     'https://play.google.com/store/apps/details?id=de.tvmiesbach.app' ),
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // /events  – Terminliste aus der lokalen WP-DB
    // -------------------------------------------------------------------------

    public function rest_events( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $from     = sanitize_text_field( $request->get_param('from') ?? '' );
        $to       = sanitize_text_field( $request->get_param('to')   ?? '' );
        $calendar = sanitize_text_field( $request->get_param('calendar') ?? '' );
        $limit    = max( 1, min( 500, (int) $request->get_param('limit')  ) );
        $offset   = max( 0, (int) $request->get_param('offset') );

        // Defaults: heute bis 12 Monate voraus
        if ( $from === '' ) $from = current_time('Y-m-d') . ' 00:00:00';
        if ( $to   === '' ) $to   = gmdate('Y-m-d H:i:s', strtotime('+12 months'));

        $where   = 'WHERE e.start_dt >= %s AND e.start_dt <= %s AND e.is_reservation = 0';
        $params  = [ $from, $to ];

        if ( $calendar !== '' ) {
            $ids = array_map('intval', explode(',', $calendar));
            $ids = array_filter($ids);
            if ( $ids ) {
                $phs    = implode(',', array_fill(0, count($ids), '%d'));
                $where .= " AND e.calendar_id IN ({$phs})";
                $params  = array_merge($params, $ids);
            }
        }

        $sql_count  = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}evg_events e {$where}", ...$params );
        $total      = (int) $wpdb->get_var( $sql_count );

        $params[]   = $limit;
        $params[]   = $offset;
        $sql_items  = $wpdb->prepare(
            "SELECT e.*, c.name AS cal_name, c.color AS cal_color, c.short_code AS cal_short,
                    l.city AS loc_city, l.street AS loc_street, l.zip AS loc_zip
             FROM {$wpdb->prefix}evg_events e
             LEFT JOIN {$wpdb->prefix}evg_calendars c ON c.id = e.calendar_id
             LEFT JOIN {$wpdb->prefix}evg_locations l ON l.id = e.location_id
             {$where}
             ORDER BY e.start_dt ASC
             LIMIT %d OFFSET %d",
            ...$params
        );

        $rows   = $wpdb->get_results( $sql_items, ARRAY_A ) ?: [];
        $events = array_map( [$this, 'format_event_row'], $rows );

        return new WP_REST_Response( [
            'total'     => $total,
            'synced_at' => get_option( 'evg_events_synced_at', '' ),
            'events'    => $events,
        ], 200 );
    }

    public function rest_event_detail( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, c.name AS cal_name, c.color AS cal_color, c.short_code AS cal_short,
                    l.city AS loc_city, l.street AS loc_street, l.zip AS loc_zip
             FROM {$wpdb->prefix}evg_events e
             LEFT JOIN {$wpdb->prefix}evg_calendars c ON c.id = e.calendar_id
             LEFT JOIN {$wpdb->prefix}evg_locations l ON l.id = e.location_id
             WHERE e.id = %d",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_REST_Response( ['message' => 'Termin nicht gefunden.'], 404 );
        }

        return new WP_REST_Response( $this->format_event_row( $row ), 200 );
    }

    // -------------------------------------------------------------------------
    // /calendars  – Kalendergruppen-Liste
    // -------------------------------------------------------------------------

    public function rest_calendars(): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, color, short_code FROM {$wpdb->prefix}evg_calendars ORDER BY name",
            ARRAY_A
        ) ?: [];

        return new WP_REST_Response(
            array_map( function( $r ) {
                return [
                    'calendar_id' => (string) $r['id'],
                    'name'        => $r['name'],
                    'color'       => $r['color'] ?: null,
                    'short'       => $r['short_code'] ?: null,
                ];
            }, $rows ),
            200
        );
    }

    /** Formatiert eine DB-Zeile zu einem App-tauglichen Event-Objekt */
    private function format_event_row( array $r ): array {
        $participation_open = false;
        if ( (int) $r['access_level'] === 1 ) {
            $now = current_time('mysql');
            $p_start = $r['participation_start'] ?? '';
            $p_end   = $r['participation_end']   ?? '';
            $after_start  = ( $p_start === '' || $now >= $p_start );
            $before_end   = ( $p_end   === '' || $now <= $p_end   );
            $participation_open = $after_start && $before_end;
        }

        $location = null;
        if ( $r['location_name'] !== '' ) {
            $location = [
                'id'     => $r['location_id'] ? (string) $r['location_id'] : null,
                'name'   => $r['location_name'],
                'city'   => $r['loc_city']   ?? null,
                'street' => $r['loc_street'] ?? null,
                'zip'    => $r['loc_zip']    ?? null,
            ];
        }

        $calendar = null;
        if ( $r['calendar_id'] ) {
            $calendar = [
                'id'    => (string) $r['calendar_id'],
                'name'  => $r['cal_name']  ?? '',
                'color' => $r['cal_color'] ?: null,
                'short' => $r['cal_short'] ?: null,
            ];
        }

        return [
            'event_id'           => (string) $r['id'],
            'name'               => $r['name'],
            'description'        => $r['description'] ?: null,
            'start'              => $r['start_dt'],
            'end'                => $r['end_dt']   ?: null,
            'all_day'            => (bool)(int) $r['all_day'],
            'location'           => $location,
            'calendar'           => $calendar,
            'is_public'          => (bool)(int) $r['is_public'],
            'canceled'           => (bool)(int) $r['canceled'],
            'max_participants'   => isset($r['max_participants']) && $r['max_participants'] !== null
                                    ? (int) $r['max_participants'] : null,
            'participation_open' => $participation_open,
        ];
    }

    // -------------------------------------------------------------------------
    // /oauth/callback  – Empfängt EasyVerein-Redirect, leitet zur App weiter
    // -------------------------------------------------------------------------

    public function rest_oauth_callback( WP_REST_Request $request ) {
        $code  = sanitize_text_field( $request->get_param( 'code' )  ?? '' );
        $state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );
        $error = sanitize_text_field( $request->get_param( 'error' ) ?? '' );

        error_log( '[EVG OAuth] callback received – code=' . ( $code ? substr($code, 0, 10) . '…' : 'EMPTY' )
            . ' state=' . ( $state ?: 'EMPTY' )
            . ' error=' . ( $error ?: 'none' ) );

        if ( $error ) {
            $app_scheme = 'tvmiesbach://auth/callback?error=' . rawurlencode( $error );
        } elseif ( ! $code ) {
            error_log( '[EVG OAuth] callback missing code – params: ' . json_encode( $request->get_params() ) );
            $app_scheme = 'tvmiesbach://auth/callback?error=missing_code';
        } else {
            $params     = array_filter( [ 'code' => $code, 'state' => $state ] );
            $app_scheme = 'tvmiesbach://auth/callback?' . http_build_query( $params );
        }

        error_log( '[EVG OAuth] serving JS redirect to: ' . $app_scheme );

        // Android Chrome Custom Tab blockiert direkte Custom-Scheme-Redirects (302).
        // Lösung: HTTPS-Seite mit JavaScript + Meta-Refresh die die App öffnet.
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        $escaped = esc_js( $app_scheme );
        $attr    = esc_attr( $app_scheme );
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0;url={$attr}">
<title>Weiterleitung…</title>
<script>window.location.replace("{$escaped}");</script>
</head>
<body>
<p>Weiterleitung zur App…</p>
<p><a href="{$attr}">Hier klicken falls die App nicht öffnet</a></p>
</body>
</html>
HTML;
        exit;
    }

    // -------------------------------------------------------------------------
    // /members
    // -------------------------------------------------------------------------

    public function rest_members( WP_REST_Request $request ) {
        global $wpdb;

        $user_id    = get_current_user_id();
        $allow_all  = (bool) get_user_meta( $user_id, 'evg_groups_all', true );
        $allowed    = (array) get_user_meta( $user_id, 'evg_groups', true );
        $per_page   = (int) $request->get_param( 'per_page' );
        $offset     = (int) $request->get_param( 'offset' );
        $mod_after  = sanitize_text_field( $request->get_param( 'modified_after' ) ?? '' );

        $members_tbl = $wpdb->prefix . 'evg_members';
        $groups_tbl  = $wpdb->prefix . 'evg_member_groups';

        // WHERE-Klauseln aufbauen
        $where  = '1=1';
        $params = [];

        if ( $mod_after ) {
            $where   .= ' AND m.updated_at > %s';
            $params[] = $mod_after;
        }

        // Gruppen-Filter: nur Mitglieder sichtbar deren Gruppe erlaubt ist
        if ( ! $allow_all && ! empty( $allowed ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
            $where       .= " AND EXISTS (
                SELECT 1 FROM {$groups_tbl} mg
                WHERE mg.member_id = m.member_id AND mg.group_id IN ({$placeholders})
            )";
            $params = array_merge( $params, $allowed );
        } elseif ( ! $allow_all && empty( $allowed ) ) {
            // Keine Gruppen erlaubt → leere Liste zurückgeben
            return new WP_REST_Response( [], 200 );
        }

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.member_id, m.member_number, m.first_name, m.family_name,
                        CONCAT(m.first_name, ' ', m.family_name) AS full_name,
                        m.email_private, m.phone, m.date_of_birth, m.age, m.birth_year,
                        m.gender, m.zip, m.city, m.street, m.address_suffix, m.updated_at
                 FROM {$members_tbl} m
                 WHERE {$where}
                 ORDER BY m.family_name, m.first_name
                 LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        if ( $rows === null ) {
            return new WP_REST_Response( [], 200 );
        }

        // Gruppen pro Mitglied laden (nur die erlaubten)
        $member_ids = array_column( $rows, 'member_id' );
        $groups_map = [];

        if ( ! empty( $member_ids ) ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%s' ) );
            $group_params    = $member_ids;

            if ( ! $allow_all && ! empty( $allowed ) ) {
                $g_placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $group_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT member_id, group_id FROM {$groups_tbl}
                         WHERE member_id IN ({$id_placeholders}) AND group_id IN ({$g_placeholders})",
                        ...array_merge( $member_ids, $allowed )
                    ),
                    ARRAY_A
                );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $group_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT member_id, group_id FROM {$groups_tbl} WHERE member_id IN ({$id_placeholders})",
                        ...$member_ids
                    ),
                    ARRAY_A
                );
            }

            foreach ( (array) $group_rows as $gr ) {
                $groups_map[ $gr['member_id'] ][] = $gr['group_id'];
            }
        }

        $result = array_map( function( $row ) use ( $groups_map ) {
            return [
                'member_id'      => $row['member_id'],
                'member_number'  => $row['member_number'] ?? '',
                'first_name'     => $row['first_name']    ?? '',
                'family_name'    => $row['family_name']   ?? '',
                'full_name'      => $row['full_name']     ?? '',
                'email_private'  => $row['email_private'] ?? '',
                'phone'          => $row['phone']         ?? '',
                'date_of_birth'  => $row['date_of_birth'] ?? null,
                'age'            => isset( $row['age'] )  ? (int) $row['age'] : null,
                'birth_year'     => isset( $row['birth_year'] ) ? (int) $row['birth_year'] : null,
                'gender'         => $row['gender']        ?? '',
                'zip'            => $row['zip']           ?? '',
                'city'           => $row['city']          ?? '',
                'street'         => $row['street']        ?? '',
                'address_suffix' => $row['address_suffix'] ?? '',
                'groups'         => $groups_map[ $row['member_id'] ] ?? [],
                'custom_fields'  => [],
                'updated_at'     => $row['updated_at'] ?? null,
            ];
        }, $rows );

        return new WP_REST_Response( $result, 200 );
    }

    // -------------------------------------------------------------------------
    // /me
    // -------------------------------------------------------------------------

    public function rest_me( WP_REST_Request $request ) {
        $user_id      = get_current_user_id();
        $user         = get_userdata( $user_id );
        $allow_all    = (int) get_user_meta( $user_id, 'evg_groups_all', true );
        $groups       = (array) get_user_meta( $user_id, 'evg_groups', true );
        $groups       = array_values( array_filter( array_map( 'strval', $groups ) ) );
        $ev_sub       = (string) get_user_meta( $user_id, 'evg_oidc_sub', true );
        $direct_write = (bool) $user->has_cap( 'evg_direct_write' );

        return new WP_REST_Response( [
            'wp_user_id'       => $user_id,
            'display_name'     => $user->display_name,
            'email'            => $user->user_email,
            'ev_sub'           => $ev_sub,
            'allow_all_groups' => (bool) $allow_all,
            'allowed_group_ids'=> $allow_all ? [] : $groups,
            'can_submit_changes' => true,
            'can_direct_write'   => $direct_write,
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // /groups
    // -------------------------------------------------------------------------

    public function rest_groups( WP_REST_Request $request ) {
        $user_id   = get_current_user_id();
        $allow_all = (int) get_user_meta( $user_id, 'evg_groups_all', true );
        $allowed   = (array) get_user_meta( $user_id, 'evg_groups', true );
        $allowed   = array_values( array_filter( array_map( 'strval', $allowed ) ) );

        global $wpdb;
        $g_table = $wpdb->prefix . 'evg_groups';

        if ( $allow_all ) {
            $rows = $wpdb->get_results(
                "SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM {$g_table} ORDER BY label ASC",
                ARRAY_A
            );
        } elseif ( ! empty( $allowed ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT group_id, COALESCE(NULLIF(name,''), group_id) AS label FROM {$g_table} WHERE group_id IN ({$placeholders}) ORDER BY label ASC", ...$allowed ),
                ARRAY_A
            );
        } else {
            $rows = [];
        }

        $groups = array_map( function( $row ) {
            return [
                'group_id' => (string) $row['group_id'],
                'label'    => (string) $row['label'],
            ];
        }, (array) $rows );

        return new WP_REST_Response( $groups, 200 );
    }

    // -------------------------------------------------------------------------
    // /change-requests GET
    // -------------------------------------------------------------------------

    public function rest_get_change_requests( WP_REST_Request $request ) {
        global $wpdb;
        $table   = $wpdb->prefix . self::CHANGE_REQUESTS_SUFFIX;
        $user_id = get_current_user_id();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, member_id, field_name, old_value, new_value, reason, requested_at, reviewed_at, status
                 FROM {$table}
                 WHERE requested_by = %d
                 ORDER BY requested_at DESC
                 LIMIT 100",
                $user_id
            ),
            ARRAY_A
        );

        $items = array_map( function( $row ) {
            return [
                'id'           => (int)    $row['id'],
                'member_id'    => (string) $row['member_id'],
                'field_name'   => (string) $row['field_name'],
                'old_value'    => isset( $row['old_value'] ) ? (string) $row['old_value'] : null,
                'new_value'    => (string) $row['new_value'],
                'reason'       => isset( $row['reason'] ) ? (string) $row['reason'] : null,
                'requested_at' => (string) $row['requested_at'],
                'reviewed_at'  => isset( $row['reviewed_at'] ) ? (string) $row['reviewed_at'] : null,
                'status'       => (string) $row['status'],
            ];
        }, (array) $rows );

        return new WP_REST_Response( [ 'items' => $items ], 200 );
    }

    // -------------------------------------------------------------------------
    // /change-requests POST
    // -------------------------------------------------------------------------

    public function rest_post_change_request( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . self::CHANGE_REQUESTS_SUFFIX;

        $member_id  = $request->get_param( 'member_id' );
        $field_name = $request->get_param( 'field_name' );
        $new_value  = $request->get_param( 'new_value' );
        $old_value  = $request->get_param( 'old_value' );
        $reason     = $request->get_param( 'reason' );

        if ( $member_id === '' || $field_name === '' || $new_value === '' ) {
            return new WP_Error( 'evg_cr_invalid', __( 'member_id, field_name und new_value sind Pflichtfelder.', 'ev-groups' ), [ 'status' => 400 ] );
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'member_id'    => $member_id,
                'field_name'   => $field_name,
                'old_value'    => $old_value !== null ? $old_value : null,
                'new_value'    => $new_value,
                'reason'       => $reason !== null ? $reason : null,
                'requested_by' => get_current_user_id(),
                'requested_at' => current_time( 'mysql', true ),
                'status'       => 'pending',
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'evg_cr_db', __( 'Datenbankfehler beim Speichern.', 'ev-groups' ), [ 'status' => 500 ] );
        }

        $new_id = (int) $wpdb->insert_id;

        // E-Mail-Benachrichtigung an Admin
        $this->notify_admin_new_request( $new_id, $member_id, $field_name, $new_value, $reason );

        return new WP_REST_Response( [
            'id'     => $new_id,
            'status' => 'pending',
        ], 201 );
    }

    private function notify_admin_new_request( $id, $member_id, $field_name, $new_value, $reason ) {
        $email = get_option( 'evg_sync_report_email', '' );
        if ( ! $email || ! is_email( $email ) ) {
            $email = get_option( 'admin_email' );
        }
        if ( ! $email || ! is_email( $email ) ) {
            return;
        }

        $admin_url = admin_url( 'admin.php?page=evg-change-requests' );
        $site      = wp_specialchars_decode( get_bloginfo( 'name' ) );
        $subject   = sprintf( '[%s] Neue Änderungsanfrage #%d', $site, $id );
        $body      = sprintf(
            "Neue Änderungsanfrage eingegangen:\n\nMitglieds-ID: %s\nFeld: %s\nNeuer Wert: %s\nBegründung: %s\n\nJetzt prüfen: %s",
            $member_id, $field_name, $new_value, $reason ?: '–', $admin_url
        );
        wp_mail( $email, $subject, $body );
    }

    // -------------------------------------------------------------------------
    // Admin: Änderungsanfragen
    // -------------------------------------------------------------------------

    public function admin_submenu() {
        add_submenu_page(
            EVG_SLUG,
            __( 'Änderungsanfragen', 'ev-groups' ),
            __( 'Änderungsanfragen', 'ev-groups' ),
            'manage_options',
            'evg-change-requests',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'easyverein-go_page_evg-change-requests' ) {
            return;
        }
        wp_enqueue_style( 'evg-admin-cr', EVG_URL . 'assets/admin/evg-change-requests.css', [], EVG_VERSION );
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::CHANGE_REQUESTS_SUFFIX;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Änderungsanfragen', 'ev-groups' ); ?></h1>

            <?php
            // Status-Rückmeldung nach Aktion
            $notice = get_transient( 'evg_cr_notice_' . get_current_user_id() );
            if ( $notice ) {
                delete_transient( 'evg_cr_notice_' . get_current_user_id() );
                $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
                echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
            }
            ?>

            <?php
            $filter       = isset( $_GET['cr_status'] ) ? sanitize_key( $_GET['cr_status'] ) : 'pending';
            $valid_filters = [ 'pending', 'approved', 'rejected', 'all' ];
            if ( ! in_array( $filter, $valid_filters, true ) ) {
                $filter = 'pending';
            }

            // Tabs
            $base_url = admin_url( 'admin.php?page=evg-change-requests' );
            echo '<ul class="subsubsub">';
            foreach ( [ 'pending' => 'Offen', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'all' => 'Alle' ] as $key => $label ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" . ( $key !== 'all' ? " WHERE status = %s" : "" ), ...( $key !== 'all' ? [ $key ] : [] ) ) );
                $active = $filter === $key ? ' class="current"' : '';
                echo '<li><a href="' . esc_url( add_query_arg( 'cr_status', $key, $base_url ) ) . '"' . $active . '>' . esc_html( $label ) . ' <span class="count">(' . $count . ')</span></a> | </li>';
            }
            echo '</ul>';
            ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top:1em">
                <thead>
                    <tr>
                        <th width="50"><?php esc_html_e( 'ID', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Mitglied', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Feld', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Alt', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Neu', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Begründung', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Angefragt von', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Datum', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'ev-groups' ); ?></th>
                        <th><?php esc_html_e( 'Aktion', 'ev-groups' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $where = $filter !== 'all' ? $wpdb->prepare( " WHERE status = %s", $filter ) : '';
                $rows  = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY requested_at DESC LIMIT 200", ARRAY_A );

                if ( empty( $rows ) ) {
                    echo '<tr><td colspan="10">' . esc_html__( 'Keine Einträge.', 'ev-groups' ) . '</td></tr>';
                }
                foreach ( (array) $rows as $row ) :
                    $user = get_userdata( (int) $row['requested_by'] );
                    $date = get_date_from_gmt( $row['requested_at'], 'd.m.Y H:i' );
                    $nonce_approve = wp_create_nonce( 'evg_cr_approve_' . $row['id'] );
                    $nonce_reject  = wp_create_nonce( 'evg_cr_reject_' . $row['id'] );

                    // Member-Name aus DB holen
                    $m_table     = $wpdb->prefix . 'evg_members';
                    $member_name = $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(first_name,' ',family_name) FROM {$m_table} WHERE member_id = %s", $row['member_id'] ) );
                    $member_display = $member_name ? esc_html( trim( $member_name ) ) . ' <small>(' . esc_html( $row['member_id'] ) . ')</small>' : esc_html( $row['member_id'] );
                    ?>
                    <tr>
                        <td><?php echo (int) $row['id']; ?></td>
                        <td><?php echo $member_display; // pre-escaped above ?></td>
                        <td><code><?php echo esc_html( $row['field_name'] ); ?></code></td>
                        <td><?php echo esc_html( $row['old_value'] ?? '–' ); ?></td>
                        <td><strong><?php echo esc_html( $row['new_value'] ); ?></strong></td>
                        <td><?php echo esc_html( $row['reason'] ?? '–' ); ?></td>
                        <td><?php echo $user ? esc_html( $user->display_name ) : esc_html( '#' . $row['requested_by'] ); ?></td>
                        <td><?php echo esc_html( $date ); ?></td>
                        <td>
                            <?php
                            $status_labels = [ 'pending' => '🕐 Offen', 'approved' => '✅ Genehmigt', 'rejected' => '❌ Abgelehnt' ];
                            echo esc_html( $status_labels[ $row['status'] ] ?? $row['status'] );
                            ?>
                        </td>
                        <td>
                            <?php if ( $row['status'] === 'pending' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="evg_cr_action">
                                    <input type="hidden" name="cr_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="cr_action" value="approve">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_approve ); ?>">
                                    <input type="hidden" name="_redirect_status" value="<?php echo esc_attr( $filter ); ?>">
                                    <?php submit_button( __( 'Genehmigen', 'ev-groups' ), 'small', '', false ); ?>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="evg_cr_action">
                                    <input type="hidden" name="cr_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="cr_action" value="reject">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_reject ); ?>">
                                    <input type="hidden" name="_redirect_status" value="<?php echo esc_attr( $filter ); ?>">
                                    <?php submit_button( __( 'Ablehnen', 'ev-groups' ), 'small delete', '', false ); ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** AJAX/Admin-Post Handler für Genehmigen/Ablehnen. */
    public function ajax_change_request_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Nicht erlaubt.', 403 );
        }

        $cr_id     = isset( $_POST['cr_id'] ) ? (int) $_POST['cr_id'] : 0;
        $cr_action = isset( $_POST['cr_action'] ) ? sanitize_key( $_POST['cr_action'] ) : '';
        $status    = isset( $_POST['_redirect_status'] ) ? sanitize_key( $_POST['_redirect_status'] ) : 'pending';

        if ( ! in_array( $cr_action, [ 'approve', 'reject' ], true ) || $cr_id <= 0 ) {
            wp_die( 'Ungültige Aktion.' );
        }

        $nonce_action = 'evg_cr_' . $cr_action . '_' . $cr_id;
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], $nonce_action ) ) {
            wp_die( 'Nonce ungültig.' );
        }

        global $wpdb;
        $table      = $wpdb->prefix . self::CHANGE_REQUESTS_SUFFIX;
        $new_status = $cr_action === 'approve' ? 'approved' : 'rejected';

        $wpdb->update(
            $table,
            [
                'status'      => $new_status,
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $cr_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        $msg = $cr_action === 'approve'
            ? sprintf( __( 'Anfrage #%d wurde genehmigt.', 'ev-groups' ), $cr_id )
            : sprintf( __( 'Anfrage #%d wurde abgelehnt.', 'ev-groups' ), $cr_id );

        set_transient( 'evg_cr_notice_' . get_current_user_id(), [ 'type' => 'success', 'msg' => $msg ], 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=evg-change-requests&cr_status=' . $status ) );
        exit;
    }
}
