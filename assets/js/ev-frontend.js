(function($){
    const GLOBAL = window.EVG || {};
    const LABELS = GLOBAL.columnLabels || {
        full_name:'Name', first_name:'Vorname', family_name:'Nachname',
        email_private:'E-Mail', date_of_birth:'Geburtsdatum', age:'Alter',
        birth_year:'Jahrgang', gender:'Geschlecht', phone:'Telefon',
        zip:'PLZ', city:'Ort', street:'Straße', address_suffix:'Adresszusatz',
        group_name:'Gruppe', groups:'Gruppen', member_number:'Mitgliedsnummer',
        contact_details:'Kontakt-Details'
    };
    const DEFAULT_COLUMNS = Array.isArray(GLOBAL.columnsDefault) && GLOBAL.columnsDefault.length
        ? GLOBAL.columnsDefault.slice()
        : Object.keys(LABELS);

    const NUMERIC_COLUMNS = new Set(['age','birth_year']);
    const DATE_COLUMNS = new Set(['date_of_birth']);

    function headerLabel(key){
        return LABELS[key] || key;
    }
    function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>\"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

    function normalizeGroups(value){
        if (Array.isArray(value)) {
            return value.map(v => String(v).trim()).filter(Boolean);
        }
        if (value == null) {
            return [];
        }
        return String(value).split(/[,|]{1,2}/).map(v => v.trim()).filter(Boolean);
    }

    function normalizeCustom(value){
        if (Array.isArray(value)) {
            return value.map(v => String(v).trim()).filter(Boolean);
        }
        if (value == null) {
            return [];
        }
        return String(value).split(/[,|]{1,2}/).map(v => v.trim()).filter(Boolean);
    }
    
    function formatCellValue(key, value) {
        if (key === 'groups') {
            const groups = normalizeGroups(value);
            if (groups.length === 0) return '<span class="evg-no-groups">Keine Gruppen</span>';
            return groups.map(g => `<span class="evg-group-badge">${escapeHtml(g)}</span>`).join(' ');
        }
        if (key === 'group_name' && value) {
            const groups = normalizeGroups(value);
            if (groups.length === 0) return '<span class="evg-no-groups">Keine Gruppen</span>';
            return groups.map(g => `<span class="evg-group-badge">${escapeHtml(g)}</span>`).join(' ');
        }
        if (key === 'custom_fields' && value) {
            const entries = normalizeCustom(value);
            if (entries.length === 0) return '<span class="evg-no-groups">Keine Merkmale</span>';
            return entries.map(v => `<span class="evg-custom-badge">${escapeHtml(v)}</span>`).join(' ');
        }
        if (key === 'date_of_birth' && value) {
            return escapeHtml(value);
        }
        if (key === 'email_private' && value) {
            return `<a href="mailto:${escapeHtml(value)}">${escapeHtml(value)}</a>`;
        }
        if (key === 'phone' && value) {
            const numbers = String(value).split(/[,;]+/).map(v=>v.trim()).filter(Boolean);
            if (!numbers.length) return escapeHtml(value);
            return numbers.map(num=>{
                const tel = num.replace(/[^\d+]/g,'');
                const href = tel || num;
                return `<a href="tel:${escapeHtml(href)}">${escapeHtml(num)}</a>`;
            }).join('<br>');
        }
        return escapeHtml(value || '');
    }

    function valueForSort(row, key){
        const value = row && Object.prototype.hasOwnProperty.call(row, key) ? row[key] : null;
        if (NUMERIC_COLUMNS.has(key)){
            const num = Number(value);
            return Number.isFinite(num) ? num : Number.POSITIVE_INFINITY;
        }
        if (DATE_COLUMNS.has(key)){
            const ts = Date.parse(value);
            return Number.isFinite(ts) ? ts : Number.POSITIVE_INFINITY;
        }
        if (Array.isArray(value)){
            return value.join(' ').toLowerCase();
        }
        return String(value == null ? '' : value).toLowerCase();
    }

    function isEmptyForSort(row, key){
        if (!row || !Object.prototype.hasOwnProperty.call(row, key)) {
            return true;
        }
        const value = row[key];
        if (value === null || value === undefined) {
            return true;
        }
        if (Array.isArray(value)) {
            return value.length === 0;
        }
        if (NUMERIC_COLUMNS.has(key)) {
            const num = Number(value);
            return !Number.isFinite(num);
        }
        if (DATE_COLUMNS.has(key)) {
            const ts = Date.parse(value);
            return !Number.isFinite(ts);
        }
        return String(value).trim() === '';
    }

    function sortRows(rows, key, dir){
        if (!key) return rows.slice();
        const factor = dir === 'desc' ? -1 : 1;
        return rows.slice().sort((a,b)=>{
            const emptyA = isEmptyForSort(a, key);
            const emptyB = isEmptyForSort(b, key);
            if (emptyA && !emptyB) return 1;
            if (!emptyA && emptyB) return -1;
            const va = valueForSort(a, key);
            const vb = valueForSort(b, key);
            if (va < vb) return -1 * factor;
            if (va > vb) return 1 * factor;
            return 0;
        });
    }
    
    function render($wrap, rows, columns, options){
        const opts = options || {};
        const sortKey = opts.sortKey || null;
        const sortDir = opts.sortDir === 'desc' ? 'desc' : 'asc';
        const $thead=$wrap.find('thead'); 
        const $tbody=$wrap.find('tbody');
        const $loading=$wrap.find('.evg-loading');
        const $error=$wrap.find('.evg-error');
        
        $thead.empty(); 
        $tbody.empty();
        $loading.hide();
        $error.hide();
        
        const headerCells = columns.map(c=>{
            const isSorted = sortKey === c;
            const classes = ['evg-sortable'];
            if (isSorted){
                classes.push(sortDir === 'desc' ? 'is-desc' : 'is-asc');
            }
            const ariaSort = isSorted ? (sortDir === 'desc' ? 'descending' : 'ascending') : 'none';
            return `<th scope="col" class="${classes.join(' ')}" data-key="${escapeHtml(c)}" aria-sort="${ariaSort}">${escapeHtml(headerLabel(c))}</th>`;
        }).join('');
        $thead.append('<tr>'+headerCells+'</tr>');

        const labelByColumn = columns.reduce((acc, columnKey) => {
            acc[columnKey] = headerLabel(columnKey);
            return acc;
        }, {});

        if (rows.length === 0) {
            $tbody.append('<tr><td colspan="' + columns.length + '" class="evg-empty">Keine Daten gefunden</td></tr>');
            return;
        }
        
        rows.forEach(r=>{
            const cells = columns.map(c=>{
                const label = labelByColumn[c] || c;
                return `<td data-label="${escapeHtml(label)}">${formatCellValue(c, r[c])}</td>`;
            }).join('');
            $tbody.append('<tr>'+cells+'</tr>');
        });
    }
    
    function apply(allRows, query, group, customToken){
        query=(query||'').toLowerCase();
        const customKey = (customToken || '').toLowerCase();
        return allRows.filter(r=>{
            // Group filtering - check if member belongs to selected group
        if (group) {
            const normalizedSelected = group.toLowerCase();
            const memberGroups = normalizeGroups(r.groups && r.groups.length ? r.groups : r.group_name);
            const inGroup = memberGroups.some(g => g.toLowerCase() === normalizedSelected);
            if(!inGroup) return false;
        }
            if (customKey) {
                const tokens = Array.isArray(r.custom_pairs) ? r.custom_pairs : [];
                const hasMatch = tokens.some(token => String(token).toLowerCase() === customKey);
                if (!hasMatch) return false;
            }
            
            // Search filtering
            if(!query) return true;
            
            // Search in all fields
            return Object.values(r).some(v=>{
                const raw = Array.isArray(v) ? v.join(' ') : String(v||'');
                if (!raw) return false;
                return raw.toLowerCase().includes(query);
            });
        });
    }
    
    function downloadCSV(filename, rows, columns){
        const header = columns.map(c=>'"'+headerLabel(c).replace(/"/g,'""')+'"').join(',');
        const lines  = rows.map(r=> columns.map(c=>{
            let value = r[c] || '';
            // Clean up group names for CSV
            if (c === 'group_name' && value) {
                value = normalizeGroups(value).join('; ');
            }
            if (c === 'groups' && value) {
                value = normalizeGroups(value).join('; ');
            }
            if (c === 'custom_fields' && value) {
                value = normalizeCustom(value).join('; ');
            }
            return '"'+String(value).replace(/"/g,'""')+'"';
        }).join(',') );
        const csv    = [header].concat(lines).join('\\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob); 
        const a=document.createElement('a'); 
        a.href=url; 
        a.download=filename; 
        a.click(); 
        URL.revokeObjectURL(url);
    }

    $(document).on('ready', function(){
        $('.evg-wrap').each(function(){
            const $wrap=$(this);
            let columns = [];
            try {
                const raw = $wrap.attr('data-columns') || '';
                columns = raw ? JSON.parse(raw) : [];
            } catch (err) {
                columns = [];
            }
            if (!Array.isArray(columns) || columns.length === 0) {
                columns = DEFAULT_COLUMNS.slice();
            }
            const $search = $wrap.find('.evg-search');
            const $filter = $wrap.find('.evg-group-filter');
            const $customFilter = $wrap.find('.evg-custom-filter');
            const $loading= $wrap.find('.evg-loading');
            const $error  = $wrap.find('.evg-error');
            const $export = $wrap.find('.evg-export');
            const $countCurrent = $wrap.find('.evg-count-current');
            const $countTotal   = $wrap.find('.evg-count-total');
            const $pagination   = $wrap.find('.evg-pagination');
            const $pagePrev     = $wrap.find('.evg-page-prev');
            const $pageNext     = $wrap.find('.evg-page-next');
            const $pageCurrent  = $wrap.find('.evg-page-current');
            const $pageTotal    = $wrap.find('.evg-page-total');
            const PAGE_SIZE     = 100;
            let ALL = [];
            let filtered = [];
            let pageIndex = 0;
            let sortKey = columns.length ? columns[0] : null;
            let sortDir = 'asc';

            function setCounts(total, filteredCount){
                const formatNumber = (value) => {
                    if (typeof value === 'number'){
                        try {
                            return value.toLocaleString();
                        } catch (e){
                            return String(value);
                        }
                    }
                    return value;
                };
                if ($countTotal.length){
                    $countTotal.text(formatNumber(total));
                }
                if ($countCurrent.length){
                    $countCurrent.text(formatNumber(filteredCount));
                }
            }

            function refresh(){
                const q=$search.val();
                const g=$filter.val();
                const c=$customFilter.length ? $customFilter.val() : '';
                filtered = apply(ALL, q, g, c);
                filtered = sortRows(filtered, sortKey, sortDir);
                pageIndex = 0;
                updatePagination();
                renderPage();
            }

            function renderPage(){
                const total = Array.isArray(filtered) ? filtered.length : 0;
                setCounts(ALL.length, total);
                if (!Array.isArray(filtered) || total === 0){
                    render($wrap, [], columns, {sortKey, sortDir});
                    return;
                }
                const start = pageIndex * PAGE_SIZE;
                const end   = start + PAGE_SIZE;
                const slice = filtered.slice(start, end);
                render($wrap, slice, columns, {sortKey, sortDir});
            }

            function updatePagination(){
                if (!$pagination.length){
                    return;
                }
                const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
                const hasPagination = filtered.length > PAGE_SIZE;
                $pagination.prop('hidden', !hasPagination);
                if (hasPagination){
                    const currentPage = Math.min(totalPages, Math.max(1, pageIndex + 1));
                    $pageCurrent.text(currentPage);
                    $pageTotal.text(totalPages);
                    $pagePrev.prop('disabled', currentPage <= 1);
                    $pageNext.prop('disabled', currentPage >= totalPages);
                } else {
                    $pageCurrent.text(totalPages);
                    $pageTotal.text(totalPages);
                    $pagePrev.prop('disabled', true);
                    $pageNext.prop('disabled', true);
                }
            }

            function goToPage(newIndex){
                const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
                const normalized = Math.min(totalPages - 1, Math.max(0, newIndex));
                if (normalized === pageIndex) return;
                pageIndex = normalized;
                updatePagination();
                renderPage();
            }

            $wrap.on('click', 'th.evg-sortable', function(){
                const keyAttr = $(this).data('key');
                const key = keyAttr ? String(keyAttr) : '';
                if (!key || columns.indexOf(key) === -1){
                    return;
                }
                if (sortKey === key){
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDir = 'asc';
                }
                filtered = sortRows(filtered, sortKey, sortDir);
                pageIndex = 0;
                updatePagination();
                renderPage();
            });

            $search.on('input', refresh);
            $filter.on('change', refresh);
            if ($customFilter.length){
                $customFilter.on('change', refresh);
            }
            $export.on('click', function(){
                const rows = apply(ALL, $search.val(), $filter.val(), $customFilter.length ? $customFilter.val() : '');
                downloadCSV('easyverein_export.csv', rows, columns);
            });
            if ($pagePrev.length){
                $pagePrev.on('click', function(){
                    goToPage(pageIndex - 1);
                });
            }
            if ($pageNext.length){
                $pageNext.on('click', function(){
                    goToPage(pageIndex + 1);
                });
            }

            $loading.show();
            $.post(EVG.ajax, {action:'evg_fetch_local', nonce: EVG.nonce}, function(resp){
                $loading.hide();
                if(!resp || !resp.success){ $error.text((resp&&resp.data&&resp.data.message)||'Fehler').show(); return; }
                ALL = resp.data.rows||[];
                const groups = resp.data.groups||{};
                Object.keys(groups).forEach(gid=>{
                    $filter.append('<option value="'+escapeHtml(groups[gid])+'">'+escapeHtml(groups[gid])+'</option>');
                });
                const customFilters = resp.data.custom_filters || {};
                if ($customFilter.length){
                    $customFilter.find('option:not(:first)').remove();
                    $customFilter.val('');
                    const tokens = Object.keys(customFilters);
                    if (tokens.length){
                        tokens.forEach(token=>{
                            $customFilter.append('<option value="'+escapeHtml(token)+'">'+escapeHtml(customFilters[token])+'</option>');
                        });
                        $customFilter.show();
                    } else {
                        $customFilter.val('');
                        $customFilter.hide();
                    }
                }
                refresh();
            }).fail(function(){ $loading.hide(); $error.text('Netzwerkfehler').show(); });
        });
    });
})(jQuery);
