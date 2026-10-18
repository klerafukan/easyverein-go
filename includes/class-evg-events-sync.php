<?php
/**
 * EVG_Events_Sync – Holt Termine, Kalender und Orte von der EasyVerein API
 * und speichert sie in den WP-Tabellen evg_events, evg_calendars, evg_locations.
 *
 * Verwendet die selben evg_api_key / evg_auth_header / evg_api_base Optionen
 * wie EVG_Sync.
 *
 * Sync-Ablauf (alle 15 Minuten, komplett über das Zeitfenster – ein Delta lohnt
 * nicht, weil gelöschte Termine nur über die vollständige Liste erkennbar sind):
 *   1. Kalender holen → wp_evg_calendars (inkl. „Sichtbar für Gruppen“)
 *   2. Orte holen     → wp_evg_locations
 *   3. Termine holen  → wp_evg_events  (Fenster: 30 Tage zurück bis 12 Monate voraus,
 *      alle Termine außer Raumreservierungen). Termine im Fenster, die die API nicht
 *      mehr liefert, werden entfernt.
 *   4. Ältere Termine bereinigen
 *
 * Wer welchen Termin sieht, entscheidet erst die Anzeige (visibility_sql()):
 *   - in easyVerein öffentlich        → alle, auch Gäste
 *   - Kalender mit Gruppen            → angemeldete Mitglieder in einer dieser Gruppen
 *   - sonst (nicht öffentlich)        → alle angemeldeten Mitglieder
 *   - Admins sehen alles
 *
 * Zeiten werden als Ortszeit der Website (Einstellungen → Allgemein → Zeitzone)
 * gespeichert – so erwarten es Kalender-Widget und App.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVG_Events_Sync {

    private const SCHEMA_VERSION = 2;
    private const LOCK           = 'evg_events_lock';
    public  const RESULT_OPTION  = 'evg_events_last_result';
    private const DAYS_BACK      = 30;

    /** @var int API-Aufrufe im aktuellen Lauf */
    private $calls = 0;

    // ── Tabellen-Schema ──────────────────────────────────────────────────────

    public static function ensure_tables() {
        global $wpdb;
        if ( (int) get_option( 'evg_events_schema', 0 ) === self::SCHEMA_VERSION
            && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'evg_events' ) ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        dbDelta( "CREATE TABLE {$p}evg_calendars (
            id          BIGINT UNSIGNED     NOT NULL,
            name        VARCHAR(200)        NOT NULL DEFAULT '',
            color       VARCHAR(7)          NOT NULL DEFAULT '',
            short_code  VARCHAR(4)          NOT NULL DEFAULT '',
            allowed_groups VARCHAR(2000)    NOT NULL DEFAULT '',
            synced_at   DATETIME            NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}evg_locations (
            id            BIGINT UNSIGNED NOT NULL,
            name          VARCHAR(500)    NOT NULL DEFAULT '',
            street        VARCHAR(500)    NOT NULL DEFAULT '',
            city          VARCHAR(500)    NOT NULL DEFAULT '',
            zip           VARCHAR(20)     NOT NULL DEFAULT '',
            country_code  VARCHAR(5)      NOT NULL DEFAULT '',
            description   TEXT,
            is_reservable TINYINT(1)      NOT NULL DEFAULT 0,
            synced_at     DATETIME        NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}evg_events (
            id                  BIGINT UNSIGNED NOT NULL,
            name                VARCHAR(500)    NOT NULL DEFAULT '',
            description         LONGTEXT,
            start_dt            DATETIME        NOT NULL,
            end_dt              DATETIME,
            all_day             TINYINT(1)      NOT NULL DEFAULT 0,
            location_id         BIGINT UNSIGNED,
            location_name       VARCHAR(500)    NOT NULL DEFAULT '',
            calendar_id         BIGINT UNSIGNED,
            series_id           BIGINT UNSIGNED,
            is_public           TINYINT(1)      NOT NULL DEFAULT 1,
            canceled            TINYINT(1)      NOT NULL DEFAULT 0,
            is_reservation      TINYINT(1)      NOT NULL DEFAULT 0,
            min_participants    SMALLINT UNSIGNED,
            max_participants    SMALLINT UNSIGNED,
            participation_start DATETIME,
            participation_end   DATETIME,
            access_level        TINYINT UNSIGNED NOT NULL DEFAULT 1,
            synced_at           DATETIME         NOT NULL,
            PRIMARY KEY (id),
            KEY idx_start      (start_dt),
            KEY idx_calendar   (calendar_id),
            KEY idx_public     (is_public, canceled)
        ) {$charset};" );

        // raw_json enthielt die komplette API-Antwort inkl. Mailadressen und Notizen – nicht mehr speichern
        if ( $wpdb->get_var( "SHOW COLUMNS FROM {$p}evg_events LIKE 'raw_json'" ) ) {
            $wpdb->query( "ALTER TABLE {$p}evg_events DROP COLUMN raw_json" );
        }
        update_option( 'evg_events_schema', self::SCHEMA_VERSION );
    }

    // ── Haupt-Sync-Einstieg ──────────────────────────────────────────────────

    /**
     * Öffentlicher Einstiegspunkt. Schreibt Ergebnis-Option und (bei Fehlern,
     * Änderungen oder manuellem Start) eine Zeile ins Monats-Log termine-JJJJMM.log.
     *
     * @param string $source 'cron' | 'manual' | 'cli'
     * @return array{ok: bool, message: string, calendars: int, locations: int, events: int, new: int, deleted: int, calls: int, seconds: float, finished: int}
     */
    public function run( string $source = 'cron' ): array {
        $t0     = microtime( true );
        $result = [ 'ok' => false, 'message' => '', 'calendars' => 0, 'locations' => 0,
                    'events' => 0, 'new' => 0, 'deleted' => 0, 'calls' => 0, 'seconds' => 0.0, 'finished' => 0 ];

        if ( get_transient( self::LOCK ) ) {
            $result['message'] = 'Läuft bereits.';
            $result['skipped'] = true;
            return $result;
        }
        set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

        try {
            self::ensure_tables();
            $result['calendars'] = $this->sync_calendars();
            $result['locations'] = $this->sync_locations();
            [ $result['events'], $result['new'], $result['deleted'] ] = $this->sync_events();
            $result['deleted'] += $this->cleanup_old_events();
            update_option( 'evg_events_synced_at', current_time( 'mysql' ) );
            $result['ok']      = true;
            $result['message'] = sprintf( '%d Termine (%d neu, %d entfernt), %d Kalender',
                $result['events'], $result['new'], $result['deleted'], $result['calendars'] );
        } catch ( \Throwable $e ) {
            $result['message'] = $e->getMessage();
            error_log( '[EVG Events Sync] Fehler: ' . $e->getMessage() );
        } finally {
            delete_transient( self::LOCK );
        }

        $result['calls']    = $this->calls;
        $result['seconds']  = round( microtime( true ) - $t0, 1 );
        $result['finished'] = time();
        update_option( self::RESULT_OPTION, $result, false );

        if ( ! $result['ok'] || $result['new'] || $result['deleted'] || $source !== 'cron' ) {
            @file_put_contents( self::log_file(), sprintf( "[%s] %s | %s | %.1fs | %d Aufrufe | %s\n",
                date( 'Y-m-d H:i:s' ), $result['ok'] ? 'OK' : 'FEHLER', $source,
                $result['seconds'], $result['calls'], $result['message'] ), FILE_APPEND | LOCK_EX );
        }

        return $result;
    }

    public static function log_file( $ym = null ): string {
        return evg_debug_dir() . '/termine-' . ( $ym ?: date( 'Ym' ) ) . '.log';
    }

    // ── Sichtbarkeit ─────────────────────────────────────────────────────────

    /**
     * SQL-Bedingung, welche Termine der Benutzer sehen darf. Erwartet die Aliase
     * e (evg_events) und c (LEFT JOIN evg_calendars).
     */
    public static function visibility_sql( int $user_id ): string {
        global $wpdb;
        if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
            return '1=1';
        }
        $member_id = $user_id ? (string) get_user_meta( $user_id, 'evg_member_id', true ) : '';
        if ( $member_id === '' ) {
            return 'e.is_public = 1';
        }

        $parts  = [ 'e.is_public = 1', "COALESCE(c.allowed_groups, '') = ''" ];
        foreach ( self::member_group_ids( $user_id, $member_id ) as $gid ) {
            $parts[] = $wpdb->prepare( 'FIND_IN_SET(%s, c.allowed_groups)', $gid );
        }
        return '(' . implode( ' OR ', $parts ) . ')';
    }

    /**
     * Gruppen des Mitglieds – live aus den synchronisierten Mitgliedsdaten (aktuell
     * nach dem stündlichen Delta-Sync) plus am WP-Benutzer zugeteilte Gruppen.
     *
     * @return string[]
     */
    public static function member_group_ids( int $user_id, string $member_id ): array {
        global $wpdb;
        $ids = array_map( 'strval', (array) get_user_meta( $user_id, 'evg_groups', true ) );
        $tbl = $wpdb->prefix . EVG_Plugin::live_table_prefix() . '_member_groups';
        $ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT group_id FROM {$tbl} WHERE member_id = %s", $member_id
        ) ) );
        return array_values( array_unique( array_filter( $ids, 'ctype_digit' ) ) );
    }

    // ── Kalender ─────────────────────────────────────────────────────────────

    private function sync_calendars(): int {
        global $wpdb;
        $items = $this->fetch_all_pages( '/api/v3.0/calendar?limit=100' );
        $now   = current_time( 'mysql' );
        $count = 0;
        $ids   = [];

        foreach ( $items as $cal ) {
            $id = (int)( $cal['id'] ?? 0 );
            if ( ! $id ) continue;
            $ids[] = $id;

            $wpdb->replace(
                $wpdb->prefix . 'evg_calendars',
                [
                    'id'         => $id,
                    'name'       => substr( (string)( $cal['name']  ?? '' ), 0, 200 ),
                    'color'      => substr( (string)( $cal['color'] ?? '' ), 0, 7 ),
                    'short_code' => substr( (string)( $cal['short'] ?? '' ), 0, 4 ),
                    'allowed_groups' => $this->id_list( $cal['allowed_groups'] ?? $cal['allowedGroups'] ?? [] ),
                    'synced_at'  => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
            $count++;
        }
        $this->delete_missing( 'evg_calendars', $ids );

        return $count;
    }

    // ── Orte ─────────────────────────────────────────────────────────────────

    private function sync_locations(): int {
        global $wpdb;
        $items = $this->fetch_all_pages( '/api/v3.0/location?limit=100' );
        $now   = current_time( 'mysql' );
        $count = 0;
        $ids   = [];

        foreach ( $items as $loc ) {
            $id = (int)( $loc['id'] ?? 0 );
            if ( ! $id ) continue;
            $ids[] = $id;

            $wpdb->replace(
                $wpdb->prefix . 'evg_locations',
                [
                    'id'            => $id,
                    'name'          => substr( (string)( $loc['name']         ?? '' ), 0, 500 ),
                    'street'        => substr( (string)( $loc['street']       ?? '' ), 0, 500 ),
                    'city'          => substr( (string)( $loc['city']         ?? '' ), 0, 500 ),
                    'zip'           => substr( (string)( $loc['zip']          ?? '' ), 0, 20 ),
                    'country_code'  => substr( (string)( $loc['country_code'] ?? $loc['countryCode'] ?? '' ), 0, 5 ),
                    'description'   => (string)( $loc['description'] ?? '' ),
                    'is_reservable' => (int)( $loc['is_reservable']  ?? $loc['isReservable'] ?? 0 ),
                    'synced_at'     => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );
            $count++;
        }
        $this->delete_missing( 'evg_locations', $ids );

        return $count;
    }

    // ── Events ────────────────────────────────────────────────────────────────

    /** @return int[] [Anzahl, neu, entfernt] */
    private function sync_events(): array {
        global $wpdb;

        // Zeitfenster: 30 Tage zurück bis 12 Monate voraus (UTC mit „Z“, sonst wertet die API als Ortszeit)
        $from_ts = strtotime( '-' . self::DAYS_BACK . ' days' );
        $to_ts   = strtotime( '+12 months' );

        $path = '/api/v3.0/event?limit=100&ordering=start'
              . '&start__gte=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $from_ts ) )
              . '&start__lte=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $to_ts ) );

        // Alle Termine holen – wer was sieht, entscheidet visibility_sql(). Raumreservierungen bleiben draußen.
        // Kalender-Filter wenn konfiguriert (kommaseparierte Kalender-IDs)
        $cal_filter = trim( (string) get_option( 'evg_events_calendar_filter', '' ) );
        if ( $cal_filter !== '' ) {
            $path .= '&calendar__in=' . rawurlencode( $cal_filter );
        }

        $items = $this->fetch_all_pages( $path );
        $now   = current_time( 'mysql' );
        $count = 0;
        $table      = $wpdb->prefix . 'evg_events';
        $from_local = wp_date( 'Y-m-d H:i:s', $from_ts );
        $to_local   = wp_date( 'Y-m-d H:i:s', $to_ts );
        $known      = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table}" ) ) );
        $new        = 0;

        // Kalender/Location-Cache für Joins
        $calendars = $this->load_calendar_cache();
        $locations = $this->load_location_cache();

        $seen = [];
        foreach ( $items as $ev ) {
            $id = (int)( $ev['id'] ?? 0 );
            if ( ! $id ) continue;
            if ( ! empty( $ev['is_reservation'] ?? $ev['isReservation'] ?? false ) ) continue;

            // Datumsfelder normalisieren (EV gibt ISO 8601 zurück)
            $start_dt = $this->parse_dt( (string)( $ev['start'] ?? '' ) );
            $end_dt   = $this->parse_dt( (string)( $ev['end']   ?? '' ) );
            if ( ! $start_dt ) continue;

            // Location: FK oder Freitext
            $loc_id   = null;
            $loc_name = '';
            $loc_ref = $ev['location_object'] ?? $ev['locationObject'] ?? null;
            if ( ! empty( $loc_ref ) ) {
                $loc_id   = (int) $this->extract_id( $loc_ref );
                $loc_name = $locations[ $loc_id ] ?? '';
            }
            $loc_text = $ev['location_name'] ?? $ev['locationName'] ?? '';
            if ( $loc_name === '' && ! empty( $loc_text ) ) {
                $loc_name = (string) $loc_text;
            }

            // Calendar FK
            $cal_id = null;
            if ( ! empty( $ev['calendar'] ) ) {
                $cal_id = (int) $this->extract_id( $ev['calendar'] );
            }

            // Participation-Zeitraum
            $part_start = $this->parse_dt( (string)( $ev['start_participation'] ?? $ev['startParticipation'] ?? '' ) );
            $part_end   = $this->parse_dt( (string)( $ev['end_participation']   ?? $ev['endParticipation']   ?? '' ) );

            $wpdb->replace(
                $wpdb->prefix . 'evg_events',
                [
                    'id'                  => $id,
                    'name'                => substr( (string)( $ev['name'] ?? '' ), 0, 500 ),
                    'description'         => (string)( $ev['description'] ?? '' ),
                    'start_dt'            => $start_dt,
                    'end_dt'              => $end_dt,
                    'all_day'             => (int)(bool)( $ev['all_day']        ?? $ev['allDay']         ?? false ),
                    'location_id'         => $loc_id,
                    'location_name'       => substr( $loc_name, 0, 500 ),
                    'calendar_id'         => $cal_id,
                    'series_id'           => $this->extract_id( $ev['interval'] ?? null ),
                    'is_public'           => (int)(bool)( $ev['is_public']      ?? $ev['isPublic']       ?? true ),
                    'canceled'            => (int)(bool)( $ev['canceled']       ?? false ),
                    'is_reservation'      => (int)(bool)( $ev['is_reservation'] ?? $ev['isReservation']  ?? false ),
                    'min_participants'    => $this->int_or_null( $ev['min_participators'] ?? $ev['minParticipators'] ?? null ),
                    'max_participants'    => $this->int_or_null( $ev['max_participators'] ?? $ev['maxParticipators'] ?? null ),
                    'participation_start' => $part_start,
                    'participation_end'   => $part_end,
                    'access_level'        => (int)( $ev['access'] ?? 1 ),
                    'synced_at'           => $now,
                ],
                [
                    '%d', '%s', '%s', '%s', '%s', '%d',
                    '%d', '%s', '%d', '%d', '%d', '%d', '%d',
                    '%d', '%d', '%s', '%s', '%d', '%s',
                ]
            );
            if ( ! isset( $known[ $id ] ) ) $new++;
            $seen[] = $id;
            $count++;
        }

        // Termine im Fenster, die die API nicht mehr liefert, entfernen
        $deleted = 0;
        if ( $seen ) {
            $ph = implode( ',', array_fill( 0, count( $seen ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE start_dt BETWEEN %s AND %s AND id NOT IN ($ph)",
                array_merge( [ $from_local, $to_local ], $seen )
            ) );
        } else {
            // Leere Antwort: nur leeren, wenn lokal ohnehin kaum etwas steht (Schutz vor API-Aussetzern)
            $local = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE start_dt BETWEEN %s AND %s", $from_local, $to_local ) );
            if ( $local <= 5 ) {
                $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE start_dt BETWEEN %s AND %s", $from_local, $to_local ) );
            }
        }

        return [ $count, $new, $deleted ];
    }

    /** Entfernt Kalender/Orte, die die API nicht mehr liefert (nur bei nicht-leerer Liste). */
    private function delete_missing( string $suffix, array $ids ): void {
        global $wpdb;
        if ( ! $ids ) return;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$suffix} WHERE id NOT IN ($ph)", $ids ) );
    }

    /** FK-Liste (URLs/IDs/Objekte) → "12,34" */
    private function id_list( $refs ): string {
        $ids = [];
        foreach ( (array) $refs as $r ) {
            $id = is_array( $r ) ? $this->extract_id( $r['id'] ?? null ) : $this->extract_id( $r );
            if ( $id ) $ids[] = $id;
        }
        return implode( ',', array_unique( $ids ) );
    }

    private function int_or_null( $v ): ?int {
        return ( $v === null || $v === '' ) ? null : (int) $v;
    }

    /** Löscht Termine vor dem Zeitfenster. */
    private function cleanup_old_events(): int {
        global $wpdb;
        $cutoff = wp_date( 'Y-m-d H:i:s', strtotime( '-' . self::DAYS_BACK . ' days' ) );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}evg_events WHERE start_dt < %s",
            $cutoff
        ) );
    }

    // ── Hilfsfunktionen ───────────────────────────────────────────────────────

    /** Alle Seiten eines paginierten Endpoints holen. */
    private function fetch_all_pages( string $path ): array {
        $items   = [];
        $url     = $this->build_url( $path );
        $headers = $this->headers();
        $pages   = 0;

        while ( $url && $pages < 200 ) {
            $pages++;
            $this->calls++;
            $resp = evg_http_get( $url, $headers );

            if ( is_wp_error( $resp ) ) {
                throw new \RuntimeException( 'HTTP-Fehler: ' . $resp->get_error_message() );
            }

            $code = (int) wp_remote_retrieve_response_code( $resp );
            if ( $code < 200 || $code >= 300 ) {
                throw new \RuntimeException( "API antwortete mit HTTP {$code} für {$url}" );
            }

            $body    = wp_remote_retrieve_body( $resp );
            $payload = json_decode( $body, true ) ?? [];

            // EasyVerein paginated: { next, previous, results: [...] }
            $page_items = [];
            if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
                $page_items = $payload['results'];
            } elseif ( is_array( $payload ) && isset( $payload[0] ) ) {
                $page_items = $payload;
            } elseif ( is_array( $payload ) ) {
                // Single-object response
                $page_items = [ $payload ];
            }

            $items = array_merge( $items, $page_items );

            // Nächste Seite
            $next = $payload['next'] ?? null;
            $url  = ( $next && is_string( $next ) ) ? $next : null;
        }

        return $items;
    }

    /** Kalender-Cache: id => name */
    private function load_calendar_cache(): array {
        global $wpdb;
        $rows  = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}evg_calendars", ARRAY_A );
        $cache = [];
        foreach ( (array) $rows as $r ) {
            $cache[ (int) $r['id'] ] = $r['name'];
        }
        return $cache;
    }

    /** Locations-Cache: id => name */
    private function load_location_cache(): array {
        global $wpdb;
        $rows  = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}evg_locations", ARRAY_A );
        $cache = [];
        foreach ( (array) $rows as $r ) {
            $cache[ (int) $r['id'] ] = $r['name'];
        }
        return $cache;
    }

    /**
     * Extrahiert eine numerische ID aus einem EasyVerein-FK-Wert.
     * EV liefert FK-Felder als URL-String ("https://…/event/123") oder integer.
     */
    private function extract_id( $value ): ?int {
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        if ( is_string( $value ) && preg_match( '#/(\d+)/?$#', $value, $m ) ) {
            return (int) $m[1];
        }
        return null;
    }

    /** ISO-8601 → MySQL DATETIME in Ortszeit der Website. Gibt null zurück wenn leer/ungültig. */
    private function parse_dt( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) return null;
        try {
            $dt = new \DateTimeImmutable( $value );
        } catch ( \Exception $e ) {
            return null;
        }
        return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
    }

    /** Authorization-Header analog zu EVG_Sync::headers() */
    private function headers(): array {
        $key = (string) get_option( 'evg_api_key', '' );
        $hdr = (string) get_option( 'evg_auth_header', 'Authorization Bearer' );
        $h   = [ 'Accept' => 'application/json' ];
        if ( $hdr === 'Authorization Bearer' ) {
            $h['Authorization'] = 'Bearer ' . $key;
        } else {
            $h['X-API-Key'] = $key;
        }
        return $h;
    }

    /** Vollständige URL aus relativem Pfad bauen. */
    private function build_url( string $path ): string {
        $base = rtrim( (string) get_option( 'evg_api_base', 'https://easyverein.com' ), '/' );
        if ( preg_match( '#^https?://#i', $path ) ) {
            return $path;
        }
        return $base . ( $path[0] === '/' ? $path : '/' . $path );
    }
}
