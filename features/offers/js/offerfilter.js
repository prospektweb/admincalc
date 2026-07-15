(function() {
    function toArray(collection) {
        return Array.prototype.slice.call(collection || []);
    }

    function getText(cell) {
        return cell ? cell.textContent.trim().toLowerCase() : '';
    }

    function getRowSelectionCheckbox(row) {
        return row ? row.querySelector('input[type="checkbox"]') : null;
    }

    function getBodyRows(table) {
        return table && table.tBodies.length ? toArray(table.tBodies[0].rows) : [];
    }

    function isOfferFilterHidden(row) {
        return row && row.dataset.offerfilterHidden === 'Y';
    }

    function syncHiddenSelection(table) {
        getBodyRows(table).forEach(function(row) {
            var checkbox = getRowSelectionCheckbox(row);
            if (checkbox && isOfferFilterHidden(row)) {
                checkbox.checked = false;
            }
        });
    }

    function syncHeaderSelection(table) {
        var headerCheckbox = table.querySelector('thead input[type="checkbox"]');
        if (!headerCheckbox) {
            return;
        }

        var visibleRows = getBodyRows(table).filter(function(row) {
            return !isOfferFilterHidden(row);
        });
        var visibleCheckboxes = visibleRows
            .map(getRowSelectionCheckbox)
            .filter(function(checkbox) {
                return checkbox && !checkbox.disabled;
            });

        if (!visibleCheckboxes.length) {
            headerCheckbox.checked = false;
            headerCheckbox.indeterminate = false;
            return;
        }

        var checkedCount = visibleCheckboxes.filter(function(checkbox) {
            return checkbox.checked;
        }).length;

        headerCheckbox.checked = checkedCount === visibleCheckboxes.length;
        headerCheckbox.indeterminate = checkedCount > 0 && checkedCount < visibleCheckboxes.length;
    }

    function syncSelection(table) {
        syncHiddenSelection(table);
        syncHeaderSelection(table);
    }

    function bindSelectionHandlers(table) {
        if (!table || table.dataset.offerfilterSelectionReady === 'Y') {
            return;
        }

        table.dataset.offerfilterSelectionReady = 'Y';

        table.addEventListener('click', function(event) {
            if (!event.target || !event.target.matches('thead input[type="checkbox"]')) {
                return;
            }

            window.setTimeout(function() {
                syncSelection(table);
            }, 0);
        }, true);

        table.addEventListener('change', function(event) {
            if (!event.target || !event.target.matches('input[type="checkbox"]')) {
                return;
            }

            window.setTimeout(function() {
                syncSelection(table);
            }, 0);
        }, true);
    }

    function isServiceColumn(cell, index) {
        if (!cell) {
            return true;
        }

        // Обычно первые колонки в админке Bitrix: чекбокс и меню/drag-handle.
        if (index <= 1) {
            return true;
        }

        if (cell.querySelector('input[type="checkbox"], .adm-list-table-icon, .adm-list-table-cell-checkbox')) {
            return true;
        }

        var title = (cell.textContent || '').replace(/\s+/g, ' ').trim();
        return title.length === 0;
    }

    function applyFilters(table) {
        var filterInputs = toArray(table.querySelectorAll('tr.offerfilter-row input[data-col-index]'));
        if (!filterInputs.length || !table.tBodies.length) {
            return;
        }

        var filters = filterInputs
            .map(function(input) {
                var rawValue = input.value.trim().toLowerCase();
                var variants = rawValue
                    .split(',')
                    .map(function(part) { return part.trim(); })
                    .filter(function(part) { return part.length > 0; });

                return {
                    index: parseInt(input.dataset.colIndex, 10),
                    value: rawValue,
                    variants: variants,
                };
            })
            .filter(function(filter) {
                return filter.value.length > 0 && !Number.isNaN(filter.index);
            });

        var rows = toArray(table.tBodies[0].rows);

        if (!filters.length) {
            rows.forEach(function(row) {
                row.style.display = '';
                row.dataset.offerfilterHidden = 'N';
            });
            syncSelection(table);
            return;
        }

        rows.forEach(function(row) {
            var visible = filters.every(function(filter) {
                var cell = row.cells[filter.index];
                var cellText = getText(cell);

                if (!filter.variants.length) {
                    return cellText.indexOf(filter.value) !== -1;
                }

                return filter.variants.some(function(variant) {
                    return cellText.indexOf(variant) !== -1;
                });
            });
            row.style.display = visible ? '' : 'none';
            row.dataset.offerfilterHidden = visible ? 'N' : 'Y';
        });
        syncSelection(table);
    }

    function createFilterRow(table) {
        if (!table || table.dataset.offerfilterReady === 'Y' || table.querySelector('tr.offerfilter-row')) {
            return;
        }

        var thead = table.querySelector('thead');
        if (!thead) {
            return;
        }

        var headerRows = toArray(thead.querySelectorAll('tr'));
        if (!headerRows.length) {
            return;
        }

        var header = headerRows[headerRows.length - 1];
        var headerCells = toArray(header.querySelectorAll('th'));
        if (!headerCells.length) {
            headerCells = toArray(header.querySelectorAll('td'));
        }
        if (!headerCells.length) {
            return;
        }

        var filterRow = document.createElement('tr');
        filterRow.className = 'offerfilter-row';

        headerCells.forEach(function(headerCell, index) {
            var tagName = headerCell.tagName && headerCell.tagName.toLowerCase() === 'td' ? 'td' : 'th';
            var filterCell = document.createElement(tagName);
            filterCell.className = 'offerfilter-cell';

            if (headerCell.colSpan && headerCell.colSpan > 1) {
                filterCell.colSpan = headerCell.colSpan;
            }

            if (!isServiceColumn(headerCell, index)) {
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'offerfilter-input';
                input.setAttribute('placeholder', 'Фильтр (через запятую)');
                input.dataset.colIndex = index;
                var onFilterChange = function() {
                    applyFilters(table);
                };
                input.addEventListener('input', onFilterChange);
                input.addEventListener('change', onFilterChange);
                input.addEventListener('keyup', onFilterChange);
                filterCell.appendChild(input);
            }

            filterRow.appendChild(filterCell);
        });

        if (header.nextSibling) {
            header.parentNode.insertBefore(filterRow, header.nextSibling);
        } else {
            header.parentNode.appendChild(filterRow);
        }

        table.dataset.offerfilterReady = 'Y';
        applyFilters(table);
    }

    function initOfferFilter() {
        var tables = toArray(document.querySelectorAll('table.adm-list-table'));
        tables.forEach(function(table) {
            bindSelectionHandlers(table);
            createFilterRow(table);
            applyFilters(table);
        });
    }

    if (window.BX && typeof BX.ready === 'function') {
        BX.ready(initOfferFilter);
    } else {
        document.addEventListener('DOMContentLoaded', initOfferFilter);
    }

    window.setInterval(initOfferFilter, 1000);
})();
