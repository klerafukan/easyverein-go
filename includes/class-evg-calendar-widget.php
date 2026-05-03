<?php
/**
 * EVG_Calendar_Widget – öffentlicher Frontend-Kalender
 *
 * Shortcode: [evg_calendar]
 *
 * Liest Termine aus wp_evg_events + wp_evg_calendars und rendert
 * ein interaktives Monats-Grid im Frontend.
 * Read-Only – kein Login erforderlich (zeigt standardmäßig nur is_public=1).
 *
 * Attribute:
 *   calendars   – kommagetrennte Kalender-IDs (Standard: alle)
 *   public_only – 1 (Standard) | 0  → auch nicht-öffentliche anzeigen
 *   title       – optionaler Überschriften-Text über dem Kalender
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVG_Calendar_Widget {

    public function __construct() {
        add_shortcode( 'evg_calendar', [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'wp_ajax_evg_calendar_events',        [ $this, 'ajax_get_events' ] );
        add_action( 'wp_ajax_nopriv_evg_calendar_events', [ $this, 'ajax_get_events' ] );
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public function register_assets() {
        wp_register_style(
            'evg-calendar',
            EVG_URL . 'assets/css/evg-calendar.css',
            [],
            EVG_VERSION
        );
        wp_register_script(
            'evg-calendar',
            EVG_URL . 'assets/js/evg-calendar.js',
            [ 'jquery' ],
            EVG_VERSION,
            true
        );
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────

    public function shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [
            'calendars'   => '',
            'public_only' => '1',
            'title'       => '',
        ], $atts, 'evg_calendar' );

        wp_enqueue_style( 'evg-calendar' );
        wp_enqueue_script( 'evg-calendar' );

        // wp_localize_script kann mehrfach aufgerufen werden; letzter Wert gewinnt.
        // Nonce und AJAX-URL sind gleich für alle Instanzen.
        wp_localize_script( 'evg-calendar', 'EVG_CAL', [
            'ajax'       => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'evg_cal_nonce' ),
            'publicOnly' => (int) $atts['public_only'],
        ] );

        $title_html = $atts['title']
            ? '<h3 class="evg-cal-title">' . esc_html( $atts['title'] ) . '</h3>'
            : '';

        $calendars_attr = esc_attr( sanitize_text_field( $atts['calendars'] ) );

        return $title_html
            . '<div class="evg-calendar-widget" data-calendars="' . $calendars_attr . '"></div>';
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajax_get_events(): void {
        check_ajax_referer( 'evg_cal_nonce', 'nonce' );

        $year  = isset( $_POST['year'] )  ? (int) $_POST['year']  : (int) gmdate( 'Y' );
        $month = isset( $_POST['month'] ) ? (int) $_POST['month'] : (int) gmdate( 'n' );

        // Sanity-Check
        if ( $year < 2000 || $year > 2100 ) { $year  = (int) gmdate( 'Y' ); }
        if ( $month < 1   || $month > 12  ) { $month = (int) gmdate( 'n' ); }

        $days_in_month = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
        $from          = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $to            = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, $days_in_month );

        global $wpdb;
        $p = $wpdb->prefix;

        // Calendar filter
        $cal_ids_raw = isset( $_POST['calendars'] ) ? sanitize_text_field( wp_unslash( $_POST['calendars'] ) ) : '';
        $cal_ids     = array_values( array_filter( array_map( 'intval', explode( ',', $cal_ids_raw ) ) ) );

        $public_only = isset( $_POST['publicOnly'] ) ? (int) $_POST['publicOnly'] : 1;
        $public_sql  = $public_only ? ' AND e.is_public = 1' : '';

        $cal_filter_sql = '';
        if ( ! empty( $cal_ids ) ) {
            $placeholders   = implode( ',', array_fill( 0, count( $cal_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLNotPrepared
            $cal_filter_sql = $wpdb->prepare( " AND e.calendar_id IN ($placeholders)", $cal_ids );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT e.id, e.name, e.start_dt, e.end_dt, e.all_day,
                    e.location_name, e.description, e.calendar_id, e.canceled,
                    c.name AS calendar_name, c.color AS calendar_color
             FROM {$p}evg_events e
             LEFT JOIN {$p}evg_calendars c ON c.id = e.calendar_id
             WHERE e.start_dt >= %s
               AND e.start_dt <= %s
               AND e.canceled = 0"
            . $public_sql
            . $cal_filter_sql
            . " ORDER BY e.start_dt ASC",
            $from,
            $to
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $events = [];
        $colors = [];

        foreach ( $rows as $row ) {
            $cal_id = (int) $row['calendar_id'];
            $color  = ! empty( $row['calendar_color'] ) ? $row['calendar_color'] : '#83c324';
            $cal_nm = $row['calendar_name'] ?? '';

            if ( ! isset( $colors[ $cal_id ] ) ) {
                $colors[ $cal_id ] = [
                    'color' => $color,
                    'name'  => $cal_nm,
                ];
            }

            // Beschreibung: nur sicheres HTML erlauben
            $desc = wp_kses( $row['description'] ?? '', [
                'p'      => [],
                'br'     => [],
                'strong' => [],
                'em'     => [],
                'ul'     => [],
                'ol'     => [],
                'li'     => [],
            ] );

            $events[] = [
                'id'             => (int) $row['id'],
                'name'           => $row['name'],
                'day'            => (int) substr( $row['start_dt'], 8, 2 ),
                'start_dt'       => $row['start_dt'],
                'end_dt'         => $row['end_dt'],
                'all_day'        => (int) $row['all_day'],
                'location_name'  => $row['location_name'],
                'description'    => $desc,
                'calendar_id'    => $cal_id,
                'calendar_name'  => $cal_nm,
                'calendar_color' => $color,
            ];
        }

        wp_send_json_success( [
            'events' => $events,
            'colors' => $colors,
        ] );
    }
}
