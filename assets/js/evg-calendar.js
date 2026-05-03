/**
 * EVG Calendar Widget – Frontend-Kalender
 *
 * Initialisiert für jedes .evg-calendar-widget-Element eine eigene
 * EvgCalendar-Instanz. Events werden per AJAX pro Monat geladen und
 * gecacht. Klick auf Tag → Popup · Klick auf Termin → Modal.
 */
/* global EVG_CAL */
(function ($) {
    'use strict';

    var MONTHS_DE   = ['Januar','Februar','März','April','Mai','Juni',
                       'Juli','August','September','Oktober','November','Dezember'];
    var WEEKDAYS_DE = ['Mo','Di','Mi','Do','Fr','Sa','So'];

    // ── Helpers ───────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatTime(dtStr) {
        if (!dtStr) { return ''; }
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) { return dtStr; }
        var h = String(d.getHours()).padStart(2, '0');
        var m = String(d.getMinutes()).padStart(2, '0');
        return h + ':' + m + ' Uhr';
    }

    function formatDate(dtStr) {
        if (!dtStr) { return ''; }
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) { return dtStr; }
        var parts = d.toLocaleDateString('de-DE', {
            weekday: 'short',
            day:     'numeric',
            month:   'long'
        });
        return parts + ', ' + formatTime(dtStr);
    }

    function formatTimeRange(ev) {
        if (ev.all_day) { return 'Ganztägig'; }
        var s = formatDate(ev.start_dt);
        if (ev.end_dt) {
            var endDate = new Date(ev.end_dt.replace(' ', 'T'));
            var startDate = new Date(ev.start_dt.replace(' ', 'T'));
            if (!isNaN(endDate.getTime())) {
                // Same day: only show end time
                if (endDate.toDateString() === startDate.toDateString()) {
                    return s + ' – ' + formatTime(ev.end_dt);
                }
                return s + ' – ' + formatDate(ev.end_dt);
            }
        }
        return s;
    }

    // ── EvgCalendar class ─────────────────────────────────────────────────────

    function EvgCalendar(el) {
        this.$el         = $(el);
        this.calFilter   = this.$el.data('calendars') || '';
        this.year        = new Date().getFullYear();
        this.month       = new Date().getMonth() + 1;
        this.cache       = {};        // "YYYY-MM" → {events, colors}
        this.dayEvents   = {};        // day (int) → [events] for current month
        this.$popup      = null;
        this.$modal      = null;
        this._build();
        this._loadMonth();
    }

    // ── Build DOM skeleton ────────────────────────────────────────────────────

    EvgCalendar.prototype._build = function () {
        var self = this;

        var weekdayHtml = WEEKDAYS_DE.map(function (d) {
            return '<span>' + d + '</span>';
        }).join('');

        this.$el.html(
            '<div class="evg-cal-header">' +
                '<button class="evg-cal-prev" aria-label="Vorheriger Monat">&#8249;</button>' +
                '<span class="evg-cal-title-month"></span>' +
                '<button class="evg-cal-next" aria-label="Nächster Monat">&#8250;</button>' +
            '</div>' +
            '<div class="evg-cal-weekdays">' + weekdayHtml + '</div>' +
            '<div class="evg-cal-grid evg-cal-loading"></div>' +
            '<div class="evg-cal-legend"></div>'
        );

        // Shared popup (one per page)
        if (!$('#evg-cal-popup').length) {
            $('body').append('<div id="evg-cal-popup" class="evg-cal-popup" style="display:none;"></div>');
        }
        this.$popup = $('#evg-cal-popup');

        // Shared modal (one per page)
        if (!$('#evg-cal-modal').length) {
            $('body').append(
                '<div id="evg-cal-modal" class="evg-cal-modal" style="display:none;">' +
                    '<div class="evg-cal-modal-overlay"></div>' +
                    '<div class="evg-cal-modal-content">' +
                        '<button class="evg-cal-modal-close" aria-label="Schließen">&times;</button>' +
                        '<div class="evg-cal-modal-body"></div>' +
                    '</div>' +
                '</div>'
            );
            $('#evg-cal-modal .evg-cal-modal-overlay').on('click', function () {
                $('#evg-cal-modal').hide();
            });
        }
        this.$modal = $('#evg-cal-modal');

        // Wire modal close button each time (may be re-bound on new instances)
        this.$modal.find('.evg-cal-modal-close').off('click.evg').on('click.evg', function () {
            self.$modal.hide();
        });

        // Navigation
        this.$el.on('click', '.evg-cal-prev', function () { self._navigate(-1); });
        this.$el.on('click', '.evg-cal-next', function () { self._navigate(1); });

        // Close popup on outside click
        $(document).on('click.evg-cal-' + this.$el.index(), function (e) {
            if (!$(e.target).closest('.evg-cal-day, #evg-cal-popup').length) {
                self._closePopup();
            }
        });
    };

    // ── Navigation ────────────────────────────────────────────────────────────

    EvgCalendar.prototype._navigate = function (delta) {
        this.month += delta;
        if (this.month > 12) { this.month = 1;  this.year++; }
        if (this.month < 1)  { this.month = 12; this.year--; }
        this._closePopup();
        this._loadMonth();
    };

    // ── AJAX ──────────────────────────────────────────────────────────────────

    EvgCalendar.prototype._cacheKey = function () {
        return this.year + '-' + String(this.month).padStart(2, '0');
    };

    EvgCalendar.prototype._loadMonth = function () {
        var key  = this._cacheKey();
        var self = this;
        if (this.cache[key]) {
            this._render(this.cache[key]);
            return;
        }
        this.$el.find('.evg-cal-grid').addClass('evg-cal-loading');
        $.post(
            EVG_CAL.ajax,
            {
                action:    'evg_calendar_events',
                nonce:     EVG_CAL.nonce,
                year:      this.year,
                month:     this.month,
                calendars: this.calFilter,
                publicOnly: EVG_CAL.publicOnly
            },
            function (resp) {
                if (resp && resp.success) {
                    self.cache[key] = resp.data;
                    self._render(resp.data);
                } else {
                    self.$el.find('.evg-cal-grid').removeClass('evg-cal-loading');
                }
            }
        );
    };

    // ── Render ────────────────────────────────────────────────────────────────

    EvgCalendar.prototype._render = function (data) {
        var events = data.events || [];
        var colors = data.colors || {};

        // Group events by day (number)
        var byDay = {};
        events.forEach(function (ev) {
            var d = ev.day;
            if (!byDay[d]) { byDay[d] = []; }
            byDay[d].push(ev);
        });
        this.dayEvents = byDay;

        // Calendar arithmetic
        var firstWeekday  = (new Date(this.year, this.month - 1, 1).getDay() + 6) % 7; // Mon=0
        var daysInMonth   = new Date(this.year, this.month, 0).getDate();
        var today         = new Date();
        var todayY        = today.getFullYear();
        var todayM        = today.getMonth() + 1;
        var todayD        = today.getDate();

        // Update header
        this.$el.find('.evg-cal-title-month').text(MONTHS_DE[this.month - 1] + ' ' + this.year);

        // Build grid HTML
        var html      = '';
        var cellCount = 0;

        // Leading empty cells
        for (var i = 0; i < firstWeekday; i++) {
            html += '<div class="evg-cal-day evg-cal-day--empty"></div>';
            cellCount++;
        }

        // Day cells
        for (var d = 1; d <= daysInMonth; d++) {
            var dayEvs   = byDay[d] || [];
            var isToday  = (this.year === todayY && this.month === todayM && d === todayD);
            var hasEvs   = dayEvs.length > 0;
            var classes  = 'evg-cal-day';
            if (isToday) { classes += ' evg-cal-day--today'; }
            if (hasEvs)  { classes += ' evg-cal-day--has-events'; }

            // Max 3 visible dots
            var dots = dayEvs.slice(0, 3).map(function (ev) {
                return '<span class="evg-cal-dot" style="background:' + escHtml(ev.calendar_color) + ';"></span>';
            }).join('');
            var more = dayEvs.length > 3
                ? '<span class="evg-cal-more">+' + (dayEvs.length - 3) + '</span>'
                : '';

            html += '<div class="' + classes + '" data-day="' + d + '">' +
                '<span class="evg-cal-day-num">' + d + '</span>' +
                '<span class="evg-cal-dots">' + dots + more + '</span>' +
                '</div>';
            cellCount++;
        }

        // Trailing empty cells
        var remainder = cellCount % 7;
        if (remainder !== 0) {
            for (var t = 0; t < 7 - remainder; t++) {
                html += '<div class="evg-cal-day evg-cal-day--empty"></div>';
            }
        }

        var $grid = this.$el.find('.evg-cal-grid');
        $grid.removeClass('evg-cal-loading').html(html);

        // Day click
        var self = this;
        $grid.off('click.evg-day').on('click.evg-day', '.evg-cal-day--has-events', function (e) {
            var day      = parseInt($(this).data('day'), 10);
            var dayEvts  = self.dayEvents[day] || [];
            self._showPopup($(this), dayEvts);
            e.stopPropagation();
        });

        // Legend
        var legendHtml = Object.keys(colors).map(function (id) {
            var info = colors[id];
            return '<span class="evg-cal-legend-item">' +
                '<span class="evg-cal-legend-dot" style="background:' + escHtml(info.color) + ';"></span>' +
                '<span>' + escHtml(info.name) + '</span>' +
                '</span>';
        }).join('');
        this.$el.find('.evg-cal-legend').html(legendHtml);
    };

    // ── Popup ─────────────────────────────────────────────────────────────────

    EvgCalendar.prototype._showPopup = function ($cell, events) {
        var self    = this;
        var dayNum  = parseInt($cell.data('day'), 10);
        var dateStr = MONTHS_DE[this.month - 1].substring(0, 3) + '. ' + dayNum + '.';

        var itemsHtml = events.map(function (ev) {
            var timeLabel = ev.all_day ? 'Ganztägig' : formatTime(ev.start_dt);
            return '<li class="evg-cal-popup-item" data-evid="' + ev.id + '">' +
                '<span class="evg-cal-popup-dot" style="background:' + escHtml(ev.calendar_color) + ';"></span>' +
                '<span class="evg-cal-popup-text">' +
                    '<span class="evg-cal-popup-time">' + escHtml(timeLabel) + '</span>' +
                    '<span class="evg-cal-popup-name">' + escHtml(ev.name) + '</span>' +
                '</span>' +
                '</li>';
        }).join('');

        var html = '<div class="evg-cal-popup-header">' +
                '<span>' + escHtml(dateStr) + '</span>' +
                '<button class="evg-cal-popup-close" aria-label="Schließen">&times;</button>' +
            '</div>' +
            '<ul class="evg-cal-popup-list">' + itemsHtml + '</ul>';

        this.$popup.html(html).show();

        // Position (fixed, viewport-relative)
        var rect   = $cell[0].getBoundingClientRect();
        var popW   = 270;
        var winW   = $(window).width();
        var left   = rect.left;
        var top    = rect.bottom + 4;

        // Flip right if overflow right
        if (left + popW > winW - 16) {
            left = rect.right - popW;
        }
        if (left < 8) { left = 8; }

        // Flip up if near bottom of viewport
        var winH   = $(window).height();
        var popH   = this.$popup.outerHeight() || 200;
        if (top + popH > winH - 16) {
            top = rect.top - popH - 4;
        }

        this.$popup.css({ top: top, left: left, position: 'fixed' });

        // Close button
        this.$popup.find('.evg-cal-popup-close').on('click', function (e) {
            e.stopPropagation();
            self._closePopup();
        });

        // Event click → modal
        this.$popup.find('.evg-cal-popup-item').on('click', function (e) {
            e.stopPropagation();
            var evId = parseInt($(this).data('evid'), 10);
            var ev   = events.filter(function (x) { return x.id === evId; })[0];
            if (ev) { self._showModal(ev); }
        });
    };

    EvgCalendar.prototype._closePopup = function () {
        if (this.$popup) { this.$popup.hide(); }
    };

    // ── Modal ─────────────────────────────────────────────────────────────────

    EvgCalendar.prototype._showModal = function (ev) {
        this._closePopup();

        var timeStr = formatTimeRange(ev);

        var locHtml = ev.location_name
            ? '<div class="evg-cal-modal-event-row">' +
                  '<span class="evg-icon">📍</span>' +
                  '<span>' + escHtml(ev.location_name) + '</span>' +
              '</div>'
            : '';

        var descHtml = ev.description
            ? '<div class="evg-cal-modal-event-desc">' + ev.description + '</div>'
            : '';

        // Close button color: overlay on colored bar → white
        var html =
            '<div class="evg-cal-modal-event-color" style="background:' + escHtml(ev.calendar_color) + ';"></div>' +
            '<button class="evg-cal-modal-close" aria-label="Schließen">&times;</button>' +
            '<div class="evg-cal-modal-body">' +
                '<h3 class="evg-cal-modal-event-title">' + escHtml(ev.name) + '</h3>' +
                '<p class="evg-cal-modal-event-calendar">' + escHtml(ev.calendar_name) + '</p>' +
                '<div class="evg-cal-modal-event-meta">' +
                    '<div class="evg-cal-modal-event-row">' +
                        '<span class="evg-icon">🕐</span>' +
                        '<span>' + escHtml(timeStr) + '</span>' +
                    '</div>' +
                    locHtml +
                    descHtml +
                '</div>' +
            '</div>';

        var self = this;
        this.$modal.find('.evg-cal-modal-content').html(html);
        this.$modal.show();

        // Re-bind close button
        this.$modal.find('.evg-cal-modal-close').on('click', function () {
            self.$modal.hide();
        });
    };

    // ── Init ──────────────────────────────────────────────────────────────────

    $(function () {
        if (typeof EVG_CAL === 'undefined') { return; }
        $('.evg-calendar-widget').each(function () {
            new EvgCalendar(this);
        });
    });

}(jQuery));
