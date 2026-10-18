<?php
/**
 * EVG_Events_Sync – Holt Termine, Kalender und Orte von der EasyVerein API
 * und speichert sie in den WP-Tabellen evg_events, evg_calendars, evg_locations.
 *
 * Verwendet die selben evg_api_key / evg_auth_header / evg_api_base Optionen
 * wie EVG_Sync.
 *
 * Sync-Ablauf:
 *   1. Kalender holen → wp_evg_calendars
 *   2. Orte holen     → wp_evg_locations
 *   3. Events holen   → wp_evg_events  (Fenster: 7 Tage zurück bis 12 Monate voraus)
 *      Termine im Fenster, die die API nicht mehr liefert (gelöscht, nicht mehr
 *      öffentlich, anderer Kalender), werden entfernt.
 *   4. Veraltete Events bereinigen
 *
 * Zeiten werden als Ortszeit der Website (Einstellungen → Allgemein → Zeitzone)
 * gespeichert – so erwarten es Kalender-Widget und App.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVG_Events_Sync {

    // ── Tabellen-Schema ──────────────────────────────────────────────────────

    public static function ensure_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        dbDelta( "CREATE TABLE {$p}evg_calendars (
            id          BIGINT UNSIGNED     NOT NULL,
            name        VARCHAR(200)        NOT NULL DEFAULT '',
            color       VARCHAR(7)          NOT NULL DEFAULT '',
            short_code  VARCHAR(4)          NOT NULL DEFAULT '',
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
            is_public           TINYINT(1)      NOT NULL DEFAULT 1,
            canceled            TINYINT(1)      NOT NULL DEFAULT 0,
            is_reservation      TINYINT(1)      NOT NULL DEFAULT 0,
            min_participants    SMALLINT UNSIGNED,
            max_participants    SMALLINT UNSIGNED,
            participation_start DATETIME,
            participation_end   DATETIME,
            access_level        TINYINT UNSIGNED NOT NULL DEFAULT 1,
            raw_json            LONGTEXT,
            synced_at           DATETIME         NOT NULL,
            PRIMARY KEY (id),
            KEY idx_start      (start_dt),
            KEY idx_calendar   (calendar_id),
            KEY idx_public     (is_public, canceled)
        ) {$charset};" );
    }

    // ── Haupt-Sync-Einstieg ──────────────────────────────────────────────────

    /**
     * Öffentlicher Einstiegspunkt. Gibt Array mit Statusinfo zurück.
     *
     * @return array{ok: bool, calendars: int, locations: int, events: int, error?: string}
     */
    public function run(): array {
        $result = [
            'ok'        => false,
            'calendars' => 0,
            'locations' => 0,
            'events'    => 0,
        ];

        try {
            $result['calendars'] = $this->sync_calendars();
            $result['locations'] = $this->sync_locations();
            $result['events']    = $this->sync_events();
            $this->cleanup_old_events();
            update_option( 'evg_events_synced_at', current_time( 'mysql' ) );
            $result['ok'] = true;
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            error_log( '[EVG Events Sync] Fehler: ' . $e->getMessage() );
        }

        return $result;
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
                    'synced_at'  => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s' ]
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

    private function sync_events(): int {
        global $wpdb;

        // Zeitfenster: 7 Tage zurück bis 12 Monate voraus (UTC mit „Z“, sonst wertet die API als Ortszeit)
        $from_ts = strtotime( '-7 days' );
        $to_ts   = strtotime( '+12 months' );

        $path = '/api/v3.0/event?limit=100&ordering=start'
              . '&start__gte=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $from_ts ) )
              . '&start__lte=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s\Z', $to_ts ) );

        // Nur öffentliche Termine, wenn Option gesetzt
        if ( (int) get_option( 'evg_events_public_only', 1 ) ) {
            $path .= '&is_public=true';
        }

        // Kalender-Filter wenn konfiguriert (kommaseparierte Kalender-IDs)
        $cal_filter = trim( (string) get_option( 'evg_events_calendar_filter', '' ) );
        if ( $cal_filter !== '' ) {
            $path .= '&calendar__in=' . rawurlencode( $cal_filter );
        }

        $items = $this->fetch_all_pages( $path );
        $now   = current_time( 'mysql' );
        $count = 0;

        // Kalender/Location-Cache für Joins
        $calendars = $this->load_calendar_cache();
        $locations = $this->load_location_cache();

        $seen = [];
        foreach ( $items as $ev ) {
            $id = (int)( $ev['id'] ?? 0 );
            if ( ! $id ) continue;

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
                    'is_public'           => (int)(bool)( $ev['is_public']      ?? $ev['isPublic']       ?? true ),
                    'canceled'            => (int)(bool)( $ev['canceled']       ?? false ),
                    'is_reservation'      => (int)(bool)( $ev['is_reservation'] ?? $ev['isReservation']  ?? false ),
                    'min_participants'    => $this->int_or_null( $ev['min_participators'] ?? $ev['minParticipators'] ?? null ),
                    'max_participants'    => $this->int_or_null( $ev['max_participators'] ?? $ev['maxParticipators'] ?? null ),
                    'participation_start' => $part_start,
                    'participation_end'   => $part_end,
                    'access_level'        => (int)( $ev['access'] ?? 1 ),
                    'raw_json'            => wp_json_encode( $ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'synced_at'           => $now,
                ],
                [
                    '%d', '%s', '%s', '%s', '%s', '%d',
                    '%d', '%s', '%d', '%d', '%d', '%d',
                    '%d', '%d', '%s', '%s', '%d', '%s', '%s',
                ]
            );
            $seen[] = $id;
            $count++;
        }

        // Termine im Fenster, die die API nicht mehr liefert, entfernen
        $from_local = wp_date( 'Y-m-d H:i:s', $from_ts );
        $to_local   = wp_date( 'Y-m-d H:i:s', $to_ts );
        $table      = $wpdb->prefix . 'evg_events';
        if ( $seen ) {
            $ph = implode( ',', array_fill( 0, count( $seen ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE start_dt BETWEEN %s AND %s AND id NOT IN ($ph)",
                array_merge( [ $from_local, $to_local ], $seen )
            ) );
        } else {
            // Leere Antwort: nur leeren, wenn lokal ohnehin kaum etwas steht (Schutz vor API-Aussetzern)
            $local = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE start_dt BETWEEN %s AND %s", $from_local, $to_local ) );
            if ( $local <= 5 ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE start_dt BETWEEN %s AND %s", $from_local, $to_local ) );
            }
        }

        return $count;
    }

    /** Entfernt Kalender/Orte, die die API nicht mehr liefert (nur bei nicht-leerer Liste). */
    private function delete_missing( string $suffix, array $ids ): void {
        global $wpdb;
        if ( ! $ids ) return;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$suffix} WHERE id NOT IN ($ph)", $ids ) );
    }

    private function int_or_null( $v ): ?int {
        return ( $v === null || $v === '' ) ? null : (int) $v;
    }

    /** Löscht Events die älter als 7 Tage sind. */
    private function cleanup_old_events(): void {
        global $wpdb;
        $cutoff = wp_date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        $wpdb->query( $wpdb->prepare(
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
