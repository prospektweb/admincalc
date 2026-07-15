(function (window, document) {
    'use strict';

    var scheduled = false;

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = value || '';
        return node.innerHTML;
    }

    function getProductId(item, basketId) {
        var component = window.BX && BX.Sale && BX.Sale.BasketComponent;
        var data = component && component.items && component.items[basketId];
        return data && data.PRODUCT_ID ? data.PRODUCT_ID : (item.getAttribute('data-product-id') || '0');
    }

    function layoutMarkup(basketId, productId) {
        return '<div class="prospekt-layout-file" data-prospekt-layout data-basket-id="' + escapeHtml(basketId) + '" data-product-id="' + escapeHtml(productId) + '">' +
            '<input class="prospekt-layout-file__input" type="file" data-prospekt-layout-input>' +
            '<div class="prospekt-layout-file__progress-wrap" data-prospekt-layout-progress hidden><div>Загрузка...</div><div class="prospekt-layout-file__progress"><span></span></div></div>' +
            '<div class="prospekt-layout-file__result" data-prospekt-layout-result hidden><strong>Макет:</strong> <span class="prospekt-layout-file__name" data-prospekt-layout-name></span><span data-prospekt-layout-size></span><button class="prospekt-layout-file__delete" type="button" data-prospekt-layout-delete aria-label="Удалить файл">&times;</button></div>' +
            '<button type="button" class="btn btn-default btn-transparent-bg" data-prospekt-layout-attach><span aria-hidden="true">↥</span> Прикрепить макет</button>' +
            '<div class="prospekt-layout-file__error" data-prospekt-layout-error hidden></div></div>';
    }

    function decorateItems() {
        var items = document.querySelectorAll('[data-entity="basket-item"][data-id]');
        for (var index = 0; index < items.length; index += 1) {
            var item = items[index];
            if (item.querySelector('[data-prospekt-layout]')) { continue; }
            var basketId = item.getAttribute('data-id');
            var host = item.querySelector('.basket-item-block-info') || item.querySelector('td') || item;
            host.insertAdjacentHTML('beforeend', layoutMarkup(basketId, getProductId(item, basketId)));
        }
        if (window.ProspektLayoutFiles) { window.ProspektLayoutFiles.init(document); }
    }

    function decorateDate() {
        if (document.querySelector('[data-prospekt-desired-date]')) { return; }
        var checkout = document.querySelector('.basket-checkout-container');
        if (!checkout) { return; }
        var config = window.ProspektLayoutFilesConfig || {};
        var block = document.createElement('div');
        block.className = 'prospekt-desired-date prospekt-desired-date--runtime';
        block.setAttribute('data-prospekt-desired-date', '');
        block.innerHTML = '<label class="prospekt-desired-date__label">Желаемая дата получения</label><div class="prospekt-desired-date__field" title="' + escapeHtml(config.desiredReceiveTooltipText || '') + '"><input type="text" class="form-control prospekt-desired-date__input" placeholder="Выберите дату и время" data-prospekt-desired-date-input readonly><button type="button" class="prospekt-desired-date__clear" data-prospekt-desired-date-clear hidden aria-label="Сбросить дату">&times;</button><span class="prospekt-desired-date__calendar" aria-hidden="true">▣</span></div><div class="prospekt-desired-date__status" data-prospekt-desired-date-status></div>';
        checkout.insertBefore(block, checkout.firstChild);
        if (window.ProspektDesiredReceiveDate) { window.ProspektDesiredReceiveDate.init(); }
    }

    function hideTechnicalProperties() {
        var config = window.ProspektLayoutFilesConfig || {};
        var codes = ['LAYOUT_FILE_ID', 'LAYOUT_FILE_LINK', 'LAYOUT_FILE_NAME', 'PROSPEKT_DESIRED_RECEIVE_DATE'].concat(config.hiddenPropertyCodes || []);
        for (var index = 0; index < codes.length; index += 1) {
            var safeCode = String(codes[index]).replace(/["\\]/g, '\\$&');
            var nodes = document.querySelectorAll('[data-property-code="' + safeCode + '"]');
            for (var nodeIndex = 0; nodeIndex < nodes.length; nodeIndex += 1) { nodes[nodeIndex].hidden = true; }
        }
    }

    function init() {
        scheduled = false;
        decorateItems();
        decorateDate();
        hideTechnicalProperties();
    }

    function schedule() {
        if (scheduled) { return; }
        scheduled = true;
        window.setTimeout(init, 0);
    }

    if (window.BX) {
        BX.ready(init);
        BX.addCustomEvent('OnBasketChange', schedule);
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
    if ('MutationObserver' in window) {
        new MutationObserver(schedule).observe(document.documentElement, {childList: true, subtree: true});
    }
})(window, document);
