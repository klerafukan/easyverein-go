/**
 * EVG Calendar Widget – Frontend-Kalender
 *
 * Initialisiert für jedes .evg-calendar-widget-Element eine eigene
 * EvgCalendar-Instanz. Events werden per AJAX pro Monat geladen und
 * gecacht. Klick auf Tag → Popup · Klick auf Termin → Modal.
 *
 * Ansichten: Monat (Raster) und Liste (Standard bei schmalem Container).
 * Badges blenden Kalender ein/aus; Auswahl und Ansicht merkt sich der Browser.
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

    function parseDt(dtStr) {
        if (!dtStr) { return null; }
        var d = new Date(dtStr.replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function shortDate(d) {
        return String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.';
    }

    function longDay(d) {
        return d.toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'long' });
    }

    /** Erster und letzter Kalendertag eines Termins (Ende 00:00 bzw. ganztägig zählt zum Vortag). */
    function eventSpan(ev) {
        var s = parseDt(ev.start_dt);
        var e = parseDt(ev.end_dt) || s;
        if (!s) { return null; }
        if (e > s && (ev.all_day || (e.getHours() === 0 && e.getMinutes() === 0))) {
            e = new Date(e.getTime() - 1);
        }
        if (e < s) { e = s; }
        return {
            start: new Date(s.getFullYear(), s.getMonth(), s.getDate()),
            end:   new Date(e.getFullYear(), e.getMonth(), e.getDate())
        };
    }

    function isMultiDay(ev) {
        var sp = eventSpan(ev);
        return !!sp && sp.end > sp.start;
    }

    /** Kurzes Zeit-Label für Popup und Liste. */
    function timeLabel(ev) {
        if (isMultiDay(ev)) {
            var sp = eventSpan(ev);
            return shortDate(sp.start) + ' – ' + shortDate(sp.end) + (sp.end.getFullYear() !== sp.start.getFullYear() ? sp.end.getFullYear() : '');
        }
        if (ev.all_day) { return 'Ganztägig'; }
        var t = formatTime(ev.start_dt).replace(' Uhr', '');
        return parseDt(ev.end_dt) ? t + '–' + formatTime(ev.end_dt).replace(' Uhr', '') : t + ' Uhr';
    }

    function formatTimeRange(ev) {
        var sp = eventSpan(ev);
        if (ev.all_day) {
            if (sp && sp.end > sp.start) {
                return longDay(sp.start) + ' – ' + longDay(sp.end) + ' ' + sp.end.getFullYear() + ' (ganztägig)';
            }
            return sp ? longDay(sp.start) + ', ganztägig' : 'Ganztägig';
        }
        var s = formatDate(ev.start_dt);
        if (ev.end_dt) {
            var endDate = parseDt(ev.end_dt);
            var startDate = parseDt(ev.start_dt);
            if (endDate && startDate) {
                // Same day: only show end time
                if (endDate.toDateString() === startDate.toDateString()) {
                    return s + ' – ' + formatTime(ev.end_dt);
                }
                return s + ' – ' + formatDate(ev.end_dt);
            }
        }
        return s;
    }

    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* egal */ } }
    };

    // ── EvgCalendar class ─────────────────────────────────────────────────────

    function EvgCalendar(el) {
        this.$el         = $(el);
        this.calFilter   = this.$el.data('calendars') || '';
        this.year        = new Date().getFullYear();
        this.month       = new Date().getMonth() + 1;
        this.cache       = {};        // "YYYY-MM" → {events, calendars}
        this.dayEvents   = {};        // day (int) → [events] for current month
        this.data        = null;      // zuletzt geladener Monat
        this.calendars   = [];        // Badges: [{id, name, color}]
        this.storeKey    = 'evg_cal_hidden_' + this.calFilter;
        this.hidden      = {};
        var self0 = this;
        (store.get(this.storeKey) || '').split(',').forEach(function (id) { if (id !== '') { self0.hidden[id] = true; } });
        this.compact     = this.$el.data('compact') === 1 || this.$el.data('compact') === '1';
        this.view        = this.compact ? 'month' : (store.get('evg_cal_view') || (this.$el.width() < 560 ? 'list' : 'month'));
        this.$popup      = null;
        this.$modal      = null;
        this._build();
        this._loadMonth();
    }

    // ── Build DOM skeleton ────────────────────────────────────────────────────

    EvgCalendar.prototype._build = function () {
        var self = this;

        var weekdayHtml = WEEKDAYS_DE.map(function (d) {
            return '<span>' + (self.compact ? d.charAt(0) : d) + '</span>';
        }).join('');
        var viewsHtml = this.compact ? '' :
            '<div class="evg-cal-views" role="group" aria-label="Ansicht">' +
                '<button type="button" data-view="month">Monat</button>' +
                '<button type="button" data-view="list">Liste</button>' +
            '</div>';

        this.$el.html(
            '<div class="evg-cal-header">' +
                '<button class="evg-cal-prev" aria-label="Vorheriger Monat">&#8249;</button>' +
                '<span class="evg-cal-title-month"></span>' +
                '<button class="evg-cal-next" aria-label="Nächster Monat">&#8250;</button>' +
            '</div>' +
            '<div class="evg-cal-toolbar">' +
                '<div class="evg-cal-badges" role="group" aria-label="Kalender ein- und ausblenden"></div>' +
                viewsHtml +
            '</div>' +
            '<div class="evg-cal-weekdays">' + weekdayHtml + '</div>' +
            '<div class="evg-cal-grid evg-cal-loading"></div>' +
            '<div class="evg-cal-list"></div>'
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

        // Ansicht wechseln
        this.$el.on('click', '.evg-cal-views button', function () {
            self.view = $(this).data('view');
            store.set('evg_cal_view', self.view);
            self._closePopup();
            if (self.data) { self._render(self.data); }
        });

        // Badge: Kalender ein-/ausblenden
        this.$el.on('click', '.evg-cal-badge', function () {
            var id = String($(this).data('cal'));
            if (self.hidden[id]) { delete self.hidden[id]; } else { self.hidden[id] = true; }
            store.set(self.storeKey, Object.keys(self.hidden).join(','));
            self._closePopup();
            if (self.data) { self._render(self.data); }
        });

        // Termin in der Liste → Modal
        this.$el.on('click', '.evg-cal-list-item', function () {
            var evId = parseInt($(this).data('evid'), 10);
            var ev = (self.data.events || []).filter(function (x) { return x.id === evId; })[0];
            if (ev) { self._showModal(ev); }
        });

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
                calendars: this.calFilter
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
        var self   = this;
        this.data  = data;
        this._mergeCalendars(data.calendars || []);
        this._renderBadges();

        var events = (data.events || []).filter(function (ev) { return !self.hidden[String(ev.calendar_id)]; });

        // Termine den Tagen des Monats zuordnen – mehrtägige an jedem ihrer Tage
        var monthStart  = new Date(this.year, this.month - 1, 1);
        var daysInMonth = new Date(this.year, this.month, 0).getDate();
        var monthEnd    = new Date(this.year, this.month - 1, daysInMonth);
        var byDay = {};
        events.forEach(function (ev) {
            var sp = eventSpan(ev);
            if (!sp) { return; }
            var d = sp.start < monthStart ? new Date(monthStart) : new Date(sp.start);
            var last = sp.end > monthEnd ? monthEnd : sp.end;
            for (; d <= last; d.setDate(d.getDate() + 1)) {
                var n = d.getDate();
                (byDay[n] = byDay[n] || []).push(ev);
            }
        });
        this.dayEvents = byDay;

        this.$el.find('.evg-cal-title-month').text(MONTHS_DE[this.month - 1] + ' ' + this.year);
        this.$el.find('.evg-cal-views button').each(function () {
            $(this).toggleClass('is-active', $(this).data('view') === self.view)
                   .attr('aria-pressed', $(this).data('view') === self.view ? 'true' : 'false');
        });
        this.$el.toggleClass('evg-cal--list', this.view === 'list');
        this.$el.find('.evg-cal-grid').removeClass('evg-cal-loading');

        if (this.view === 'list') {
            this._renderList(byDay, daysInMonth);
        } else {
            this._renderGrid(byDay, daysInMonth);
        }
    };

    /** Kalenderliste für die Badges über alle geladenen Monate zusammenführen. */
    EvgCalendar.prototype._mergeCalendars = function (cals) {
        var known = {};
        this.calendars.forEach(function (c) { known[c.id] = true; });
        var self = this;
        cals.forEach(function (c) { if (!known[c.id]) { self.calendars.push(c); } });
    };

    EvgCalendar.prototype._renderBadges = function () {
        var self = this;
        var $b = this.$el.find('.evg-cal-badges');
        if (this.calendars.length < 2) { $b.empty(); return; }
        $b.html(this.calendars.map(function (c) {
            var off = !!self.hidden[String(c.id)];
            return '<button type="button" class="evg-cal-badge' + (off ? ' is-off' : '') + '" data-cal="' + c.id + '"' +
                ' aria-pressed="' + (off ? 'false' : 'true') + '" style="--evg-badge:' + escHtml(c.color) + ';">' +
                '<span class="evg-cal-badge-dot"></span>' + escHtml(c.name) + '</button>';
        }).join(''));
    };

    EvgCalendar.prototype._renderGrid = function (byDay, daysInMonth) {
        var firstWeekday  = (new Date(this.year, this.month - 1, 1).getDay() + 6) % 7; // Mon=0
        var today         = new Date();
        var html      = '';
        var cellCount = 0;

        for (var i = 0; i < firstWeekday; i++) {
            html += '<div class="evg-cal-day evg-cal-day--empty"></div>';
            cellCount++;
        }

        for (var d = 1; d <= daysInMonth; d++) {
            var dayEvs  = byDay[d] || [];
            var isToday = (this.year === today.getFullYear() && this.month === today.getMonth() + 1 && d === today.getDate());
            var classes = 'evg-cal-day';
            if (isToday)       { classes += ' evg-cal-day--today'; }
            if (dayEvs.length) { classes += ' evg-cal-day--has-events'; }

            // Mehrtägige als Balken (max. 2), andere als Punkte (max. 3)
            var multi  = dayEvs.filter(isMultiDay);
            var single = dayEvs.filter(function (ev) { return !isMultiDay(ev); });
            var bars = multi.slice(0, 2).map(function (ev) {
                return '<span class="evg-cal-bar' + (ev.canceled ? ' is-canceled' : '') + '" style="background:' + escHtml(ev.calendar_color) + ';" title="' + escHtml(ev.name) + '"></span>';
            }).join('');
            var dots = single.slice(0, 3).map(function (ev) {
                return '<span class="evg-cal-dot' + (ev.canceled ? ' is-canceled' : '') + '" style="--evg-dot:' + escHtml(ev.calendar_color) + ';"></span>';
            }).join('');
            var rest = Math.max(0, multi.length - 2) + Math.max(0, single.length - 3);
            var more = rest ? '<span class="evg-cal-more">+' + rest + '</span>' : '';

            html += '<div class="' + classes + '" data-day="' + d + '">' +
                '<span class="evg-cal-day-num">' + d + '</span>' +
                (bars ? '<span class="evg-cal-bars">' + bars + '</span>' : '') +
                '<span class="evg-cal-dots">' + dots + more + '</span>' +
                '</div>';
            cellCount++;
        }

        var remainder = cellCount % 7;
        if (remainder !== 0) {
            for (var t = 0; t < 7 - remainder; t++) {
                html += '<div class="evg-cal-day evg-cal-day--empty"></div>';
            }
        }

        var $grid = this.$el.find('.evg-cal-grid');
        $grid.html(html);
        this.$el.find('.evg-cal-list').empty();

        var self = this;
        $grid.off('click.evg-day').on('click.evg-day', '.evg-cal-day--has-events', function (e) {
            var day = parseInt($(this).data('day'), 10);
            self._showPopup($(this), self.dayEvents[day] || []);
            e.stopPropagation();
        });
    };

    /** Liste: jeder Tag mit Terminen; mehrtägige nur am ersten Tag im Monat. */
    EvgCalendar.prototype._renderList = function (byDay, daysInMonth) {
        var shown = {};
        var today = new Date();
        var html  = '';
        for (var d = 1; d <= daysInMonth; d++) {
            var evs = (byDay[d] || []).filter(function (ev) {
                if (shown[ev.id]) { return false; }
                shown[ev.id] = true;
                return true;
            });
            if (!evs.length) { continue; }
            var date = new Date(this.year, this.month - 1, d);
            var isToday = date.toDateString() === today.toDateString();
            html += '<div class="evg-cal-list-day' + (isToday ? ' is-today' : '') + '">' +
                '<div class="evg-cal-list-date">' + escHtml(longDay(date)) + (isToday ? ' · heute' : '') + '</div>' +
                '<ul>' + evs.map(function (ev) {
                    return '<li class="evg-cal-list-item' + (ev.canceled ? ' is-canceled' : '') + '" data-evid="' + ev.id + '" tabindex="0" style="--evg-dot:' + escHtml(ev.calendar_color) + ';">' +
                        '<span class="evg-cal-list-time">' + escHtml(timeLabel(ev)) + '</span>' +
                        '<span class="evg-cal-list-main">' +
                            '<span class="evg-cal-list-name">' + escHtml(ev.name) + (ev.canceled ? ' <em>abgesagt</em>' : '') + '</span>' +
                            (ev.location_name ? '<span class="evg-cal-list-loc">' + escHtml(ev.location_name) + '</span>' : '') +
                        '</span>' +
                    '</li>';
                }).join('') + '</ul></div>';
        }
        this.$el.find('.evg-cal-grid').empty();
        this.$el.find('.evg-cal-list').html(html || '<p class="evg-cal-list-empty">Keine Termine in diesem Monat.</p>');
    };

    // ── Popup ─────────────────────────────────────────────────────────────────

    EvgCalendar.prototype._showPopup = function ($cell, events) {
        var self    = this;
        var dayNum  = parseInt($cell.data('day'), 10);
        var dateStr = longDay(new Date(this.year, this.month - 1, dayNum));

        var itemsHtml = events.map(function (ev) {
            var meta = [ev.location_name, ev.calendar_name].filter(Boolean).join(' · ');
            return '<li class="evg-cal-popup-item' + (ev.canceled ? ' is-canceled' : '') + '" data-evid="' + ev.id + '" tabindex="0">' +
                '<span class="evg-cal-popup-dot" style="background:' + escHtml(ev.calendar_color) + ';"></span>' +
                '<span class="evg-cal-popup-text">' +
                    '<span class="evg-cal-popup-time">' + escHtml(timeLabel(ev)) + (ev.canceled ? ' · abgesagt' : '') + '</span>' +
                    '<span class="evg-cal-popup-name">' + escHtml(ev.name) + '</span>' +
                    (meta ? '<span class="evg-cal-popup-meta">' + escHtml(meta) + '</span>' : '') +
                    (ev.description ? '<span class="evg-cal-popup-more">Details ›</span>' : '') +
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
                (ev.canceled ? '<p class="evg-cal-modal-event-canceled">Dieser Termin ist abgesagt.</p>' : '') +
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
