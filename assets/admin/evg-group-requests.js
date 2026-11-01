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

    function updateSelection() {
        var selected = groups.filter(function (group) { return group.checked; }).length;
        toggle.checked = groups.length > 0 && selected === groups.length;
        toggle.indeterminate = selected > 0 && selected < groups.length;
        toggle.disabled = groups.length === 0;
        selectAll.disabled = selected === groups.length;
        deselectAll.disabled = selected === 0;
        count.textContent = selected + ' von ' + groups.length + ' Gruppen ausgewählt';
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
        setTimeout(updateSelection, 0);
    });

    updateSelection();
    controls.hidden = false;
    table.querySelector('[data-evg-select-header]').hidden = false;
})();
