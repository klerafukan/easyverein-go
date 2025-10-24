jQuery(function($){
  const $allToggle = $('#evg-groups-all');

  $('.evg-group-select').each(function(){
    const $wrap = $(this);
    const $table = $wrap.find('.evg-groups-table');
    const $rows = $table.find('tbody tr');
    const $search = $wrap.find('.evg-groups-search');
    const $selectToggle = $wrap.find('.evg-select-toggle');

    function refreshRowState($checkbox){
      const $row = $checkbox.closest('tr');
      $row.toggleClass('is-selected', $checkbox.is(':checked'));
    }

    function refreshToggleState(){
      if (!$selectToggle.length) return;
      const $visible = $table.find('tbody tr:visible');
      if (!$visible.length){
        $selectToggle.prop({checked:false, indeterminate:false});
        return;
      }
      const total = $visible.find('.evg-group-checkbox').length;
      const selected = $visible.find('.evg-group-checkbox:checked').length;
      if (selected === 0){
        $selectToggle.prop({checked:false, indeterminate:false});
      } else if (selected === total){
        $selectToggle.prop({checked:true, indeterminate:false});
      } else {
        $selectToggle.prop({checked:false, indeterminate:true});
      }
    }

    function filterRows(query){
      const q = (query || '').toLowerCase().trim();
      $rows.each(function(){
        const hay = String($(this).data('search') || '');
        const match = !q || hay.indexOf(q) !== -1;
        $(this).toggle(match);
      });
      refreshToggleState();
    }

    function setDisabledState(){
      const isAll = $allToggle.is(':checked');
      $wrap.toggleClass('is-disabled', isAll);
      if ($search.length){
        $search.prop('disabled', isAll);
        if (isAll){
          $search.val('');
          filterRows('');
        }
      }
      if ($selectToggle.length){
        if (isAll){
          $selectToggle.prop({checked:false, indeterminate:false});
        }
        $selectToggle.prop('disabled', isAll);
      }
    }

    // Initial state
    $table.find('.evg-group-checkbox').each(function(){
      refreshRowState($(this));
    });
    refreshToggleState();
    setDisabledState();

    // Events
    $table.on('change', '.evg-group-checkbox', function(){
      refreshRowState($(this));
      refreshToggleState();
    });

    $table.on('click', '.evg-group-row', function(event){
      if ($(event.target).is('input, label, a, button, code')) return;
      if ($wrap.hasClass('is-disabled')) return;
      const $checkbox = $(this).find('.evg-group-checkbox');
      $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
    });

    if ($search.length){
      $search.on('input', function(){
        filterRows($(this).val());
      });
    }

    if ($selectToggle.length){
      $selectToggle.on('change', function(){
        if ($wrap.hasClass('is-disabled')) {
          refreshToggleState();
          return;
        }
        const checkAll = $(this).is(':checked');
        const $visibleRows = $table.find('tbody tr:visible');
        const $checkboxes = $visibleRows.find('.evg-group-checkbox');
        $checkboxes.prop('checked', checkAll).each(function(){
          refreshRowState($(this));
        });
        refreshToggleState();
      });
    }

    $wrap.data('evgSetDisabled', setDisabledState);
  });

  if ($allToggle.length){
    $allToggle.on('change', function(){
      $('.evg-group-select').each(function(){
        const fn = $(this).data('evgSetDisabled');
        if (typeof fn === 'function') fn();
      });
    });
  }
});
