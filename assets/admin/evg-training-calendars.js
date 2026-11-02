(function () {
    'use strict';
    var root = document.querySelector('[data-evg-training-calendars]');
    if (!root) return;
    var rows = Array.from(root.querySelectorAll('[data-evg-training-group]'));
    var refreshers = [];
    rows.forEach(function (row) {
        var picker = row.querySelector('[data-evg-calendar-picker]');
        var options = Array.from(picker.querySelectorAll('[data-evg-calendar-option]'));
        var chips = row.querySelector('[data-evg-calendar-chips]');
        var search = picker.querySelector('[data-evg-calendar-search]');
        function refresh() {
            chips.replaceChildren();
            var selected = options.filter(function (option) { return option.querySelector('input').checked; });
            picker.querySelector('summary').textContent = 'Kalender auswählen / ändern (' + selected.length + ')';
            if (!selected.length) chips.textContent = 'Keine Kalender zugeordnet';
            selected.forEach(function (option) {
                var input = option.querySelector('input');
                var chip = document.createElement('span');
                var remove = document.createElement('button');
                chip.className = 'evg-calendar-chip';
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', input.dataset.calendarName + ' entfernen');
                remove.addEventListener('click', function () {
                    input.checked = false;
                    refresh();
                    picker.querySelector('summary').focus();
                });
                chip.append(document.createTextNode(input.dataset.calendarName + ' '), remove);
                chips.append(chip);
            });
        }
        search.addEventListener('input', function () {
            var query = search.value.trim().toLocaleLowerCase('de');
            options.forEach(function (option) { option.hidden = !option.dataset.calendarSearch.toLocaleLowerCase('de').includes(query); });
            picker.querySelector('[data-evg-calendar-empty]').hidden = options.some(function (option) { return !option.hidden; });
        });
        options.forEach(function (option) { option.querySelector('input').addEventListener('change', refresh); });
        picker.querySelector('[data-evg-calendar-search-control]').hidden = false;
        chips.hidden = false;
        refresh(); refreshers.push(refresh);
    });
    root.querySelector('[data-evg-group-search]').addEventListener('input', function (event) {
        var query = event.target.value.trim().toLocaleLowerCase('de');
        rows.forEach(function (row) { row.hidden = !row.dataset.groupSearch.toLocaleLowerCase('de').includes(query); });
        root.querySelector('[data-evg-group-empty]').hidden = rows.some(function (row) { return !row.hidden; });
    });
    root.querySelector('[data-evg-group-search-control]').hidden = false;
    root.querySelector('form').addEventListener('reset', function () { setTimeout(function () { refreshers.forEach(function (refresh) { refresh(); }); }, 0); });
})();
