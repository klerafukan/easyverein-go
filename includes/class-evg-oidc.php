<?php
/**
 * EVG_Oidc – EasyVerein OIDC Authorization Code Flow für WP-Web-Login
 *
 * Endpoints (Namespace: easyverein-go/v1):
 *   GET /oidc/login    – Startet den OIDC-Flow (Redirect zu EasyVerein)
 *   GET /oidc/callback – Empfängt Code, tauscht Token, setzt WP-Session-Cookie
 *
 * WP-Login:
 *   login_form Hook → "Mit EasyVerein anmelden"-Button
 *
 * WP-Admin:
 *   AJAX evg_wp_usersync_start  – Startet Bulk-Sync (Mitglieder → WP-User)
 *   AJAX evg_wp_usersync_status – Gibt Fortschritt zurück
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVG_Oidc {

    /** EasyVerein OIDC Endpoints */
    const EV_AUTHORIZE_URL = 'https://easyverein.com/oauth2/authorize/';
    const EV_TOKEN_URL     = 'https://easyverein.com/oauth2/token/';
    const EV_USERINFO_URL  = 'https://easyverein.com/oauth2/userinfo/';

    /** Option Keys */
    const OPT_CLIENT_ID     = 'evg_oidc_client_id';
    const OPT_REDIRECT_URI  = 'evg_oidc_redirect_uri';
    const OPT_WEB_LOGIN     = 'evg_oidc_web_login_enabled';

    /** Transient-Prefix für PKCE-State (kurze Lebensdauer: 10 min) */
    const STATE_TRANSIENT_PREFIX = 'evg_oidc_state_';

    /** Option Key für Bulk-Sync-Fortschritt */
    const USERSYNC_PROGRESS_KEY = 'evg_usersync_progress';

    public function __construct() {
        add_action( 'rest_api_init',         [ $this, 'register_endpoints' ] );
        add_action( 'login_form',            [ $this, 'login_form_button' ] );
        add_action( 'login_message',         [ $this, 'login_error_message' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_evg_wp_usersync_start',  [ $this, 'ajax_usersync_start' ] );
        add_action( 'wp_ajax_evg_wp_usersync_status', [ $this, 'ajax_usersync_status' ] );
    }

    // =========================================================================
    // Settings
    // =========================================================================

    public function register_settings() {
        register_setting( 'evg_settings', self::OPT_CLIENT_ID,    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'evg_settings', self::OPT_REDIRECT_URI, [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'evg_settings', self::OPT_WEB_LOGIN,    [ 'type' => 'boolean', 'sanitize_callback' => 'absint', 'default' => 0 ] );
    }

    // =========================================================================
    // REST Endpoints
    // =========================================================================

    public function register_endpoints() {
        register_rest_route( 'easyverein-go/v1', '/oidc/login', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_oidc_login' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'easyverein-go/v1', '/oidc/callback', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_oidc_callback' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // =========================================================================
    // /oidc/login – leitet zu EasyVerein weiter
    // =========================================================================

    public function rest_oidc_login( WP_REST_Request $request ) {
        if ( ! (int) get_option( self::OPT_WEB_LOGIN, 0 ) ) {
            return new WP_Error( 'evg_oidc_disabled', 'OIDC-Web-Login ist deaktiviert.', [ 'status' => 403 ] );
        }

        $client_id    = get_option( self::OPT_CLIENT_ID, '' );
        $redirect_uri = $this->callback_url();

        if ( ! $client_id ) {
            return new WP_Error( 'evg_oidc_no_client', 'Keine Client-ID konfiguriert.', [ 'status' => 500 ] );
        }

        // PKCE + State generieren
        $verifier  = $this->pkce_verifier();
        $challenge = $this->pkce_challenge( $verifier );
        $state     = bin2hex( random_bytes( 16 ) );

        // Wo soll nach Login weitergeleitet werden?
        $return_to = sanitize_url( $request->get_param( 'redirect_to' ) ?? '' );
        if ( ! $return_to ) {
            $return_to = admin_url();
        }

        // State + Verifier + Redirect-Ziel in Transient speichern (10 min)
        set_transient( self::STATE_TRANSIENT_PREFIX . $state, [
            'verifier'   => $verifier,
            'return_to'  => $return_to,
        ], 600 );

        $authorize_url = add_query_arg( [
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'scope'                 => 'openid myself profile',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ], self::EV_AUTHORIZE_URL );

        nocache_headers();
        // wp_safe_redirect() erlaubt nur den eigenen Host – für die externe
        // EasyVerein-Authorize-URL muss wp_redirect() verwendet werden.
        // Die URL wird ausschließlich aus internen Konstanten + PKCE/State gebaut,
        // kein User-Input fließt direkt in die URL ein (redirect_to ist im State-Transient).
        wp_redirect( $authorize_url, 302, 'EasyVerein OIDC' );
        exit;
    }

    // =========================================================================
    // /oidc/callback – tauscht Code gegen Token, setzt WP-Session
    // =========================================================================

    public function rest_oidc_callback( WP_REST_Request $request ) {
        $code  = sanitize_text_field( $request->get_param( 'code' )  ?? '' );
        $state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );
        $error = sanitize_text_field( $request->get_param( 'error' ) ?? '' );

        // Fehlermeldung von EV
        if ( $error ) {
            $this->login_error( esc_html( $error ) );
        }

        if ( ! $code || ! $state ) {
            $this->login_error( 'Ungültige Antwort von EasyVerein (Code oder State fehlt).' );
        }

        // State aus Transient laden und validieren
        $stored = get_transient( self::STATE_TRANSIENT_PREFIX . $state );
        if ( ! $stored ) {
            $this->login_error( 'Login-Session abgelaufen oder ungültiger State. Bitte erneut versuchen.' );
        }
        delete_transient( self::STATE_TRANSIENT_PREFIX . $state );

        $verifier  = $stored['verifier'];
        $return_to = $stored['return_to'] ?? admin_url();

        // Token-Exchange
        $client_id    = get_option( self::OPT_CLIENT_ID, '' );
        $redirect_uri = $this->callback_url();

        $token_resp = wp_remote_post( self::EV_TOKEN_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => http_build_query( [
                'grant_type'    => 'authorization_code',
                'client_id'     => $client_id,
                'redirect_uri'  => $redirect_uri,
                'code'          => $code,
                'code_verifier' => $verifier,
            ] ),
        ] );

        if ( is_wp_error( $token_resp ) || 200 !== wp_remote_retrieve_response_code( $token_resp ) ) {
            $body = is_wp_error( $token_resp ) ? $token_resp->get_error_message() : wp_remote_retrieve_body( $token_resp );
            error_log( '[EVG OIDC] Token-Exchange fehlgeschlagen: ' . $body );
            $this->login_error( 'Anmeldung bei EasyVerein fehlgeschlagen. Bitte erneut versuchen.' );
        }

        $token_data   = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $access_token = $token_data['access_token'] ?? '';

        if ( ! $access_token ) {
            error_log( '[EVG OIDC] Kein access_token in Token-Antwort: ' . wp_remote_retrieve_body( $token_resp ) );
            $this->login_error( 'Kein Access Token erhalten.' );
        }

        // Userinfo abrufen
        $ui_resp = wp_remote_get( self::EV_USERINFO_URL, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( is_wp_error( $ui_resp ) || 200 !== wp_remote_retrieve_response_code( $ui_resp ) ) {
            $this->login_error( 'Userinfo konnte nicht abgerufen werden.' );
        }

        $userinfo = json_decode( wp_remote_retrieve_body( $ui_resp ), true );
        $sub      = sanitize_text_field( $userinfo['sub'] ?? '' );

        if ( ! $sub ) {
            $this->login_error( 'Keine Benutzer-ID (sub) von EasyVerein erhalten.' );
        }

        // WP-User suchen oder anlegen (bestehende Logik aus EVG_Api wiederverwenden)
        $wp_user_id = $this->find_or_provision_user( $sub, $userinfo );

        if ( ! $wp_user_id ) {
            $this->login_error( 'Ihr Konto konnte nicht zugeordnet werden. Bitte kontaktieren Sie den Administrator.' );
        }

        // WP-Session-Cookie setzen → User ist jetzt im Backend "eingeloggt"
        wp_clear_auth_cookie();
        wp_set_current_user( $wp_user_id );
        wp_set_auth_cookie( $wp_user_id, true ); // true = remember me

        do_action( 'wp_login', get_userdata( $wp_user_id )->user_login, get_userdata( $wp_user_id ) );

        nocache_headers();
        wp_safe_redirect( $return_to );
        exit;
    }

    // =========================================================================
    // Login-Form: "Mit EasyVerein anmelden"-Button
    // =========================================================================

    public function login_error_message( $message ) {
        if ( ! empty( $_GET['evg_error'] ) ) {
            $err = sanitize_text_field( wp_unslash( $_GET['evg_error'] ) );
            $message .= '<div id="login_error" style="border-left:4px solid #d52623;padding:8px 12px;background:#fff8f8;margin-bottom:12px">'
                . '<strong>EasyVerein Login:</strong> ' . esc_html( $err )
                . '</div>';
        }
        return $message;
    }

    public function login_form_button() {
        if ( ! (int) get_option( self::OPT_WEB_LOGIN, 0 ) ) {
            return;
        }

        $redirect_to = sanitize_url( $_GET['redirect_to'] ?? admin_url() );
        $login_url   = add_query_arg(
            [ 'redirect_to' => rawurlencode( $redirect_to ) ],
            rest_url( 'easyverein-go/v1/oidc/login' )
        );

        ?>
        <div style="margin: 12px 0; text-align: center;">
            <div style="border-top: 1px solid #ddd; margin: 16px 0; position: relative;">
                <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 10px; color: #666; font-size: 12px;">oder</span>
            </div>
            <a href="<?php echo esc_url( $login_url ); ?>" style="
                display: inline-flex; align-items: center; gap: 8px;
                background: #83c324; color: #fff; text-decoration: none;
                padding: 10px 20px; border-radius: 6px; font-size: 14px;
                font-weight: 600; width: 100%; box-sizing: border-box;
                justify-content: center; transition: opacity 0.2s;
            " onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Mit EasyVerein anmelden
            </a>
        </div>
        <?php
    }

    // =========================================================================
    // WP-User Bulk-Sync: Mitglieder aus DB → WP-User anlegen/aktualisieren
    // =========================================================================

    /**
     * AJAX: Sync starten (speichert Fortschritt in DB, wird über Status-Polling abgerufen)
     * Verarbeitet alle Mitglieder in einem einzigen Request (max. 30 Sek).
     * Bei großen Tabellen empfiehlt sich eine Background-Queue, hier reicht Batch.
     */
    public function ajax_usersync_start() {
        check_ajax_referer( 'evg_sync', '_wpnonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Keine Berechtigung.' );
        }

        // Zeitlimit aufheben – bei vielen Mitgliedern dauert der Sync länger als das PHP-Limit
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        // nginx/fastcgi: Request nicht buffern damit der Client die Antwort bekommt
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            ignore_user_abort( true );
        }

        $dry_run      = ! empty( $_POST['dry_run'] );
        $send_welcome = ! empty( $_POST['send_welcome'] );

        // Fortschritts-Reset
        update_option( self::USERSYNC_PROGRESS_KEY, [
            'status'   => 'running',
            'total'    => 0,
            'done'     => 0,
            'created'  => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => [],
            'dry_run'  => $dry_run,
        ], false );

        global $wpdb;
        $members_tbl = $wpdb->prefix . 'evg_members';
        $groups_tbl  = $wpdb->prefix . 'evg_member_groups';

        $total_in_db = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$members_tbl}" );

        $members_raw = $wpdb->get_results(
            "SELECT member_id, first_name, family_name, email_private, date_of_birth FROM {$members_tbl}
             WHERE email_private IS NOT NULL AND email_private != ''
             ORDER BY family_name, first_name",
            ARRAY_A
        );

        // Duplikate bei gleicher E-Mail-Adresse auflösen:
        // Älteste Person (frühestes date_of_birth) gewinnt – das ist typischerweise
        // der Elternteil, der die E-Mail-Adresse für sich und das Kind nutzt.
        // Mitglieder ohne Geburtsdatum gelten als älter (überschreiben Einträge mit Datum).
        $members_by_email = [];
        $duplicate_count  = 0;
        foreach ( $members_raw as $m ) {
            $email = strtolower( sanitize_email( $m['email_private'] ) );
            if ( $email === '' ) { continue; }

            if ( ! isset( $members_by_email[ $email ] ) ) {
                $members_by_email[ $email ] = $m;
            } else {
                $existing_dob = $members_by_email[ $email ]['date_of_birth'];
                $new_dob      = $m['date_of_birth'];
                // NULL (kein Datum) schlägt jeden konkreten Wert → bleibt stehen
                // Früheres Datum (ältere Person) schlägt späteres Datum
                $replace = false;
                if ( $new_dob === null || $new_dob === '' ) {
                    // Neuer Eintrag hat kein Datum → ältere Person, ersetzen
                    $replace = true;
                } elseif ( $existing_dob !== null && $existing_dob !== '' ) {
                    // Beide haben ein Datum → früheres gewinnt
                    $replace = ( strtotime( $new_dob ) < strtotime( $existing_dob ) );
                }
                if ( $replace ) {
                    $members_by_email[ $email ] = $m;
                }
                $duplicate_count++;
            }
        }
        $members = array_values( $members_by_email );

        $total   = count( $members );
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        update_option( self::USERSYNC_PROGRESS_KEY, array_merge(
            (array) get_option( self::USERSYNC_PROGRESS_KEY, [] ),
            [ 'total' => $total ]
        ), false );

        foreach ( $members as $i => $m ) {
            $email     = sanitize_email( $m['email_private'] );
            $member_id = sanitize_text_field( $m['member_id'] );
            $first     = sanitize_text_field( $m['first_name'] );
            $last      = sanitize_text_field( $m['family_name'] );
            $name      = trim( $first . ' ' . $last );

            if ( ! is_email( $email ) ) {
                $skipped++;
                continue;
            }

            // Im Testlauf: nur zählen, nichts schreiben
            if ( $dry_run ) {
                if ( get_user_by( 'email', $email ) ) {
                    $updated++;
                } else {
                    $created++;
                }
                continue;
            }

            $existing = get_user_by( 'email', $email );

            if ( $existing ) {
                // Bestehenden User aktualisieren
                wp_update_user( [
                    'ID'           => $existing->ID,
                    'display_name' => $name,
                    'first_name'   => $first,
                    'last_name'    => $last,
                ] );
                update_user_meta( $existing->ID, 'evg_member_id', $member_id );
                $this->sync_user_groups( $existing->ID, $member_id, $groups_tbl );
                $updated++;
            } else {
                // Neuen User anlegen
                $username = $this->unique_username( $email );
                $user_id  = wp_insert_user( [
                    'user_login'   => $username,
                    'user_email'   => $email,
                    'user_pass'    => wp_generate_password( 32, true, true ),
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'display_name' => $name,
                    'role'         => 'subscriber',
                ] );

                if ( is_wp_error( $user_id ) ) {
                    $errors[] = $email . ': ' . $user_id->get_error_message();
                    continue;
                }

                update_user_meta( $user_id, 'evg_member_id', $member_id );
                update_user_meta( $user_id, 'evg_groups_all', 0 );
                $this->sync_user_groups( $user_id, $member_id, $groups_tbl );

                if ( $send_welcome ) {
                    // WP Standard-Welcome-E-Mail
                    wp_new_user_notification( $user_id, null, 'user' );
                }

                $created++;
            }

            // Fortschritt alle 50 User speichern
            if ( ( $i + 1 ) % 50 === 0 ) {
                update_option( self::USERSYNC_PROGRESS_KEY, [
                    'status'  => 'running',
                    'total'   => $total,
                    'done'    => $i + 1,
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors'  => array_slice( $errors, -10 ),
                    'dry_run' => $dry_run,
                ], false );
            }
        }

        // Abschlussstatus
        update_option( self::USERSYNC_PROGRESS_KEY, [
            'status'     => 'done',
            'total'      => $total,
            'done'       => $total,
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'duplicates' => $duplicate_count,
            'errors'     => array_slice( $errors, -20 ),
            'dry_run'    => $dry_run,
        ], false );

        wp_send_json_success( [
            'total_in_db' => $total_in_db,
            'total'       => $total,
            'created'     => $created,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'duplicates'  => $duplicate_count,
            'errors'      => count( $errors ),
            'dry_run'     => $dry_run,
        ] );
    }

    public function ajax_usersync_status() {
        check_ajax_referer( 'evg_sync', '_wpnonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Keine Berechtigung.' );
        }
        $progress = get_option( self::USERSYNC_PROGRESS_KEY, [] );
        wp_send_json_success( $progress );
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    /** Gruppen aus evg_member_groups in evg_groups usermeta schreiben */
    private function sync_user_groups( $user_id, $member_id, $groups_tbl ) {
        global $wpdb;
        $group_ids = $wpdb->get_col(
            $wpdb->prepare( "SELECT group_id FROM {$groups_tbl} WHERE member_id = %s", $member_id )
        );
        $group_ids = array_map( 'strval', (array) $group_ids );
        update_user_meta( $user_id, 'evg_groups', $group_ids );
    }

    /** WP-User aus evg_oidc_sub oder E-Mail suchen, ggf. auto-provision */
    private function find_or_provision_user( $sub, array $userinfo ) {
        // 1. Suche per sub (schnellster Pfad)
        $users = get_users( [
            'meta_key'   => 'evg_oidc_sub',
            'meta_value' => $sub,
            'number'     => 1,
            'fields'     => 'ids',
        ] );
        if ( ! empty( $users ) ) {
            return (int) $users[0];
        }

        // 2. Suche per E-Mail (Bulk-Sync hat User bereits angelegt)
        $email = sanitize_email( $userinfo['email'] ?? '' );
        if ( $email ) {
            $existing = get_user_by( 'email', $email );
            if ( $existing ) {
                update_user_meta( $existing->ID, 'evg_oidc_sub', $sub );
                // evg_member_id aus userinfo nachführen falls vorhanden
                $member_id = sanitize_text_field( $userinfo['member_id'] ?? $userinfo['sub'] ?? '' );
                if ( $member_id ) {
                    update_user_meta( $existing->ID, 'evg_member_id', $member_id );
                }
                return $existing->ID;
            }
        }

        // 3. Neuen User anlegen (Fall: kein Bulk-Sync durchgeführt)
        $name  = sanitize_text_field( $userinfo['name']  ?? '' );
        $parts = explode( ' ', $name, 2 );
        $first = sanitize_text_field( $parts[0] ?? '' );
        $last  = sanitize_text_field( $parts[1] ?? '' );
        $username = $email ?: 'ev_' . sanitize_user( preg_replace( '/[^a-z0-9]/i', '', $sub ), true );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 32, true, true ),
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $name ?: $email,
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $user_id ) ) {
            error_log( '[EVG OIDC] Auto-Provision fehlgeschlagen für sub=' . $sub . ': ' . $user_id->get_error_message() );
            return null;
        }

        update_user_meta( $user_id, 'evg_oidc_sub', $sub );
        update_user_meta( $user_id, 'evg_groups_all', 0 );

        error_log( '[EVG OIDC] Neuer WP-User angelegt: ID=' . $user_id . ' sub=' . $sub );
        return (int) $user_id;
    }

    /** Callback-URL für OIDC (muss in EasyVerein als Redirect URI hinterlegt sein) */
    public function callback_url() {
        $custom = get_option( self::OPT_REDIRECT_URI, '' );
        return $custom ?: rest_url( 'easyverein-go/v1/oidc/callback' );
    }

    /** Eindeutigen WP-Benutzernamen aus E-Mail ableiten */
    private function unique_username( $email ) {
        $base = sanitize_user( strstr( $email, '@', true ), true );
        if ( ! $base ) {
            $base = 'user';
        }
        $username = $base;
        $counter  = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $counter;
            $counter++;
        }
        return $username;
    }

    /** PKCE Code Verifier (43–128 Zeichen, RFC 7636) */
    private function pkce_verifier() {
        return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
    }

    /** PKCE Code Challenge S256 */
    private function pkce_challenge( $verifier ) {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }

    /** Login-Fehler anzeigen und abbrechen */
    private function login_error( $message ) {
        $url = add_query_arg( [
            'login' => 'failed',
            'evg_error' => rawurlencode( $message ),
        ], wp_login_url() );
        nocache_headers();
        wp_safe_redirect( $url );
        exit;
    }
}
