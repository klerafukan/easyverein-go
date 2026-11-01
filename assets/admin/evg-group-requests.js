(function () {
    'use strict';

    var table = document.querySelector('[data-evg-group-rules]');
    var controls = document.querySelector('[data-evg-group-selection]');
    if (!table || !controls) return;

    var groups = Array.from(table.querySelectorAll('[data-evg-group-enabled]'));
    var toggle = table.querySelector('[data-evg-toggle-all]');
    var selectAll = controls.querySelector('[data-evg-select-all]');
    var deselectAll = controls.querySelector('[data-evg-deselect-all]');
    var count = controls.querySelector('[data-evg-selection-count]');
    var bulk = document.querySelector('[data-evg-approver-bulk]');
    var rows = Array.from(table.querySelectorAll('tbody tr'));

    // Der native Select bleibt die einzige Quelle der gesendeten Genehmiger-IDs.
    function enhancePicker(select) {
        var wrapper = document.createElement('div');
        wrapper.className = 'evg-approver-picker';
        wrapper.setAttribute('role', 'group');
        wrapper.setAttribute('aria-label', select.getAttribute('aria-label'));
        var chips = document.createElement('div');
        chips.className = 'evg-approver-chips';
        var details = document.createElement('details');
        var summary = document.createElement('summary');
        summary.textContent = 'Person hinzufügen / suchen';
        var search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Name oder Benutzername suchen …';
        search.setAttribute('aria-label', 'Person suchen');
        var list = document.createElement('div');
        list.className = 'evg-approver-options';
        var empty = document.createElement('p');
        empty.textContent = 'Keine Personen gefunden.';
        var options = Array.from(select.options).map(function (option) {
            var label = document.createElement('label');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            label.append(checkbox, document.createTextNode(' ' + option.textContent));
            list.append(label);
            checkbox.addEventListener('change', function () {
                option.selected = checkbox.checked;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            return { option: option, checkbox: checkbox, label: label };
        });
        search.addEventListener('input', function () {
            var query = search.value.trim().toLocaleLowerCase('de');
            options.forEach(function (item) {
                item.label.hidden = !item.option.textContent.toLocaleLowerCase('de').includes(query);
            });
            empty.hidden = options.some(function (item) { return !item.label.hidden; });
        });
        details.append(summary, search, list, empty);
        wrapper.append(chips, details);
        select.after(wrapper);
        select.hidden = true;

        function refresh() {
            chips.replaceChildren();
            options.forEach(function (item) {
                item.checkbox.checked = item.option.selected;
                item.checkbox.disabled = select.disabled;
                if (!item.option.selected) return;
                var chip = document.createElement('span');
                chip.className = 'evg-approver-chip';
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.disabled = select.disabled;
                remove.setAttribute('aria-label', item.option.textContent + ' entfernen');
                remove.addEventListener('click', function () {
                    item.option.selected = false;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                chip.append(document.createTextNode(item.option.textContent + ' '), remove);
                chips.append(chip);
            });
            if (!select.selectedOptions.length) chips.textContent = 'Keine Personen ausgewählt';
            wrapper.classList.toggle('evg-approver-disabled', select.disabled);
            summary.setAttribute('aria-disabled', String(select.disabled));
            search.disabled = select.disabled;
            if (select.disabled) details.open = false;
            empty.hidden = options.some(function (item) { return !item.label.hidden; });
        }
        summary.addEventListener('click', function (event) {
            if (select.disabled) event.preventDefault();
        });
        select.addEventListener('change', refresh);
        refresh();
        return refresh;
    }

    var pickers = Array.from(document.querySelectorAll('[data-evg-approvers]')).map(function (select) {
        return { select: select, refresh: enhancePicker(select) };
    });
    var people = bulk.querySelector('[data-evg-approvers]');
    var action = bulk.querySelector('[data-evg-approver-action]');
    var apply = bulk.querySelector('[data-evg-apply-approvers]');
    var markToggle = table.querySelector('[data-evg-mark-toggle]');
    var preview = bulk.querySelector('[data-evg-approver-preview]');
    var result = bulk.querySelector('[data-evg-approver-result]');

    function markedRows() {
        return rows.filter(function (row) {
            var mark = row.querySelector('[data-evg-mark-group]');
            return mark.checked && !mark.disabled;
        });
    }

    function updateBulk() {
        var eligible = rows.filter(function (row) { return row.querySelector('[data-evg-group-enabled]').checked; });
        rows.forEach(function (row) {
            var enabled = row.querySelector('[data-evg-group-enabled]').checked;
            var mark = row.querySelector('[data-evg-mark-group]');
            mark.disabled = !enabled;
            if (!enabled) mark.checked = false;
            var picker = row.querySelector('[data-evg-approvers]');
            // Nicht beantragbare Gruppen werden serverseitig nicht gespeichert.
            picker.disabled = !enabled;
        });
        pickers.forEach(function (picker) { picker.refresh(); });
        var marked = markedRows().length;
        markToggle.checked = marked > 0 && marked === eligible.length;
        markToggle.indeterminate = marked > 0 && marked < eligible.length;
        markToggle.disabled = !eligible.length;
        bulk.querySelector('[data-evg-mark-count]').textContent = marked + ' von ' + eligible.length + ' beantragbaren Gruppen markiert';
        bulk.querySelector('[data-evg-mark-all]').disabled = marked === eligible.length;
        bulk.querySelector('[data-evg-unmark-all]').disabled = !marked;
        var selected = Array.from(people.selectedOptions);
        var verb = { add: 'hinzufügen', remove: 'entfernen', replace: 'ersetzen durch' }[action.value];
        preview.textContent = marked && selected.length
            ? 'In ' + marked + (marked === 1 ? ' Gruppe' : ' Gruppen') + ' Genehmiger ' + verb + ': ' + selected.map(function (option) { return option.textContent; }).join(', ') + '.'
            : 'Gruppen markieren und mindestens eine Person auswählen.';
        apply.disabled = !marked || !selected.length;
    }

    function markGroups(checked) {
        rows.forEach(function (row) {
            var mark = row.querySelector('[data-evg-mark-group]');
            mark.checked = checked && !mark.disabled;
        });
        updateBulk();
    }
    bulk.querySelector('[data-evg-mark-all]').addEventListener('click', function () { markGroups(true); });
    bulk.querySelector('[data-evg-unmark-all]').addEventListener('click', function () { markGroups(false); });
    markToggle.addEventListener('change', function () { markGroups(markToggle.checked); });
    rows.forEach(function (row) { row.querySelector('[data-evg-mark-group]').addEventListener('change', updateBulk); });
    people.addEventListener('change', updateBulk);
    action.addEventListener('change', updateBulk);
    apply.addEventListener('click', function () {
        var selected = Array.from(people.selectedOptions).map(function (option) { return option.value; });
        var targets = markedRows();
        if (!selected.length || !targets.length) return;
        var description = preview.textContent;
        targets.forEach(function (row) {
            var select = row.querySelector('[data-evg-approvers]');
            Array.from(select.options).forEach(function (option) {
                var included = selected.includes(option.value);
                if (action.value === 'replace') option.selected = included;
                else if (included) option.selected = action.value === 'add';
            });
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        result.textContent = 'Zuletzt ins Formular übernommen: ' + description + ' Bitte Einstellungen speichern.';
        updateBulk();
    });

    function updateSelection() {
        var selected = groups.filter(function (group) { return group.checked; }).length;
        toggle.checked = groups.length > 0 && selected === groups.length;
        toggle.indeterminate = selected > 0 && selected < groups.length;
        toggle.disabled = groups.length === 0;
        selectAll.disabled = selected === groups.length;
        deselectAll.disabled = selected === 0;
        count.textContent = selected + ' von ' + groups.length + ' Gruppen ausgewählt';
        updateBulk();
    }

    function selectGroups(checked) {
        groups.forEach(function (group) { group.checked = checked; });
        updateSelection();
    }

    selectAll.addEventListener('click', function () { selectGroups(true); });
    deselectAll.addEventListener('click', function () { selectGroups(false); });
    toggle.addEventListener('change', function () { selectGroups(toggle.checked); });
    groups.forEach(function (group) { group.addEventListener('change', updateSelection); });
    table.closest('form').addEventListener('reset', function () {
        // Der Browser setzt Checkboxen erst nach dem reset-Ereignis zurück.
        setTimeout(function () { result.textContent = ''; updateSelection(); }, 0);
    });

    updateSelection();
    controls.hidden = false;
    table.querySelector('[data-evg-select-header]').hidden = false;
    bulk.hidden = false;
    table.querySelectorAll('[data-evg-bulk-column]').forEach(function (cell) { cell.hidden = false; });
})();
