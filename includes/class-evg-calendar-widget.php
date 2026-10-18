<?php
/**
 * EVG_Calendar_Widget – öffentlicher Frontend-Kalender
 *
 * Shortcode: [evg_calendar]
 *
 * Liest Termine aus wp_evg_events + wp_evg_calendars und rendert
 * ein interaktives Monats-Grid im Frontend.
 * Read-Only. Gäste sehen öffentliche Termine, angemeldete Mitglieder zusätzlich
 * die für sie freigegebenen (Regeln: EVG_Events_Sync::visibility_sql()).
 *
 * Attribute:
 *   calendars   – kommagetrennte Kalender-IDs (Standard: alle)
 *   title       – optionaler Überschriften-Text über dem Kalender
 *   compact     – 1 = kleine Monatsansicht ohne Rahmen, z. B. für den Footer
 *   more        – URL für den Link „Alle Termine“ unter dem Kalender
 *   more_label  – Linktext (Standard: „Alle Termine“)
 *
 * Zusätzlich gibt es das Widget „Termine (easyVerein)“ (EVG_Calendar_WP_Widget),
 * das den Kalender kompakt in einen Widget-Bereich (z. B. Footer) setzt.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVG_Calendar_Widget {

    public function __construct() {
        add_shortcode( 'evg_calendar', [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'wp_ajax_evg_calendar_events',        [ $this, 'ajax_get_events' ] );
        add_action( 'wp_ajax_nopriv_evg_calendar_events', [ $this, 'ajax_get_events' ] );
        add_action( 'widgets_init', function () { register_widget( 'EVG_Calendar_WP_Widget' ); } );
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

        // Auf Seiten mit dem Shortcode das CSS schon im <head> laden (sonst erst im Footer → kurz ungestylt)
        $post = get_post();
        if ( is_singular() && $post && has_shortcode( $post->post_content, 'evg_calendar' ) ) {
            wp_enqueue_style( 'evg-calendar' );
        }
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────

    public function shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [
            'calendars'   => '',
            'title'       => '',
            'compact'     => '0',
            'more'        => '',
            'more_label'  => __( 'Alle Termine', 'ev-groups' ),
        ], $atts, 'evg_calendar' );

        wp_enqueue_style( 'evg-calendar' );
        wp_enqueue_script( 'evg-calendar' );

        // wp_localize_script kann mehrfach aufgerufen werden; letzter Wert gewinnt.
        // Nonce und AJAX-URL sind gleich für alle Instanzen.
        wp_localize_script( 'evg-calendar', 'EVG_CAL', [
            'ajax'       => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'evg_cal_nonce' ),
        ] );

        $title_html = $atts['title']
            ? '<h3 class="evg-cal-title">' . esc_html( $atts['title'] ) . '</h3>'
            : '';

        $calendars_attr = esc_attr( sanitize_text_field( $atts['calendars'] ) );
        $compact        = (int) $atts['compact'] === 1;

        $more_html = $atts['more'] !== ''
            ? '<a class="evg-cal-more-link" href="' . esc_url( $atts['more'] ) . '">' . esc_html( $atts['more_label'] ) . ' →</a>'
            : '';

        return $title_html
            . '<div class="evg-calendar-widget' . ( $compact ? ' evg-cal--compact' : '' ) . '" data-calendars="' . $calendars_attr . '"'
            . ( $compact ? ' data-compact="1"' : '' ) . '></div>'
            . $more_html;
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajax_get_events(): void {
        // Nur Lesen: Bei ungültiger Nonce (z. B. Seite aus dem Cache) gibt es die Gastansicht statt eines Fehlers.
        $user_id = check_ajax_referer( 'evg_cal_nonce', 'nonce', false ) ? get_current_user_id() : 0;

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

        $visible_sql = ' AND ' . EVG_Events_Sync::visibility_sql( $user_id );

        $cal_filter_sql = '';
        if ( ! empty( $cal_ids ) ) {
            $placeholders   = implode( ',', array_fill( 0, count( $cal_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLNotPrepared
            $cal_filter_sql = $wpdb->prepare( " AND e.calendar_id IN ($placeholders)", $cal_ids );
        }

        // Alle Termine, die den Monat berühren – auch mehrtägige, die vorher beginnen
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT e.id, e.name, e.start_dt, e.end_dt, e.all_day,
                    e.location_name, e.description, e.calendar_id, e.canceled,
                    c.name AS calendar_name, c.color AS calendar_color
             FROM {$p}evg_events e
             LEFT JOIN {$p}evg_calendars c ON c.id = e.calendar_id
             WHERE e.start_dt <= %s
               AND COALESCE(e.end_dt, e.start_dt) >= %s
               AND e.is_reservation = 0"
            . $visible_sql
            . $cal_filter_sql
            . " ORDER BY e.start_dt ASC",
            $to,
            $from
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $events = [];
        foreach ( $rows as $row ) {
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
                'start_dt'       => $row['start_dt'],
                'end_dt'         => $row['end_dt'],
                'all_day'        => (int) $row['all_day'],
                'canceled'       => (int) $row['canceled'],
                'location_name'  => $row['location_name'],
                'description'    => $desc,
                'calendar_id'    => (int) $row['calendar_id'],
                'calendar_name'  => $row['calendar_name'] ?? '',
                'calendar_color' => $this->color( $row['calendar_color'] ?? '' ),
            ];
        }

        // Badges: alle Kalender, in denen der Benutzer überhaupt Termine sieht
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cal_rows = $wpdb->get_results(
            "SELECT DISTINCT COALESCE(e.calendar_id, 0) AS id, c.name, c.color
             FROM {$p}evg_events e
             LEFT JOIN {$p}evg_calendars c ON c.id = e.calendar_id
             WHERE e.is_reservation = 0" . $visible_sql . $cal_filter_sql . "
             ORDER BY c.name IS NULL, c.name",
            ARRAY_A
        );
        $calendars = [];
        foreach ( (array) $cal_rows as $c ) {
            $calendars[] = [
                'id'    => (int) $c['id'],
                'name'  => $c['name'] ?: __( 'Weitere Termine', 'ev-groups' ),
                'color' => $this->color( $c['color'] ?? '' ),
            ];
        }

        wp_send_json_success( [
            'events'    => $events,
            'calendars' => $calendars,
        ] );
    }

    /** Nur gültige Hex-Farben durchlassen (landen im style-Attribut). */
    private function color( string $c ): string {
        return preg_match( '/^#[0-9a-f]{3,6}$/i', $c ) ? $c : '#83c324';
    }
}

/**
 * Widget „Termine (easyVerein)“ – kompakter Monatskalender mit Badges für
 * Widget-Bereiche wie den Footer. Ersetzt das WordPress-Kalender-Widget (Beiträge).
 */
class EVG_Calendar_WP_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct( 'evg_calendar_widget', __( 'Termine (easyVerein)', 'ev-groups' ), [
            'description' => __( 'Kompakter Termin-Kalender aus easyVerein mit Kalender-Badges.', 'ev-groups' ),
        ] );
    }

    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', $instance['title'] ?? '', $instance, $this->id_base );
        $atts  = [
            'compact'   => '1',
            'calendars' => $instance['calendars'] ?? '',
            'more'      => $instance['more'] ?? '',
        ];
        $sc = '[evg_calendar';
        foreach ( $atts as $k => $v ) {
            $sc .= ' ' . $k . '="' . esc_attr( $v ) . '"';
        }
        $sc .= ']';

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
        if ( $title !== '' ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo do_shortcode( $sc ); // phpcs:ignore WordPress.Security.EscapeOutput
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form( $instance ) {
        $fields = [
            'title'     => __( 'Titel', 'ev-groups' ),
            'more'      => __( 'Link „Alle Termine“ (URL der Kalenderseite)', 'ev-groups' ),
            'calendars' => __( 'Nur diese Kalender-IDs (kommagetrennt, leer = alle)', 'ev-groups' ),
        ];
        foreach ( $fields as $key => $label ) {
            printf(
                '<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s"></p>',
                esc_attr( $this->get_field_id( $key ) ),
                esc_html( $label ),
                esc_attr( $this->get_field_name( $key ) ),
                esc_attr( $instance[ $key ] ?? '' )
            );
        }
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'     => sanitize_text_field( $new_instance['title'] ?? '' ),
            'more'      => esc_url_raw( $new_instance['more'] ?? '' ),
            'calendars' => preg_replace( '/[^0-9,]/', '', (string) ( $new_instance['calendars'] ?? '' ) ),
        ];
    }
}
