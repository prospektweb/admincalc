(function () {
    'use strict';

    var isGenerating = false;
    var observer = null;
    var retryTimer = null;
    var retryCount = 0;
    var maxRetries = 20;
    var retryDelay = 300;

    function getTopWindow() {
        return window.top || window;
    }

    function getWindowsForReload() {
        var windows = [];

        function addWindow(targetWindow) {
            if (targetWindow && windows.indexOf(targetWindow) === -1) {
                windows.push(targetWindow);
            }
        }

        addWindow(window);
        addWindow(window.parent);
        addWindow(getTopWindow());
        addWindow(window.opener);

        return windows;
    }

    function reloadOffersList() {
        var windows = getWindowsForReload();

        for (var i = 0; i < windows.length; i++) {
            if (windows[i] && typeof windows[i].ReloadSubList === 'function') {
                windows[i].ReloadSubList();
                return;
            }
        }

        var topWindow = getTopWindow();
        if (
            topWindow.BX &&
            topWindow.BX.SidePanel &&
            topWindow.BX.SidePanel.Instance &&
            topWindow.BX.SidePanel.Instance.getTopSlider()
        ) {
            var currentWindow = topWindow.BX.SidePanel.Instance.getTopSlider().getWindow();
            if (currentWindow && typeof currentWindow.ReloadSubList === 'function') {
                currentWindow.ReloadSubList();
            }
        }
    }

    function findForm() {
        return (
            document.getElementById('iblock_generator_form') ||
            document.querySelector('form[name="iblock_generator_form"]')
        );
    }

    function findSaveButton(form) {
        if (!form) {
            return null;
        }

        var btn =
            form.querySelector('input[name="save"]') ||
            form.querySelector('button[name="save"]');

        if (!btn) {
            btn = form.querySelector('input[type="submit"][value*="\u0413\u0435\u043d\u0435\u0440"]') ||
                form.querySelector('button[type="submit"]');
        }

        if (!btn) {
            var submits = form.querySelectorAll('input[type="submit"], button[type="submit"]');
            if (submits.length) {
                btn = submits[submits.length - 1];
            }
        }

        return btn;
    }

    function isVisible(element) {
        return !!(element && (element.offsetWidth || element.offsetHeight || element.getClientRects().length));
    }

    function getButtonText(button) {
        if (!button) {
            return '';
        }

        return (button.value || button.innerText || button.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function findVisibleSaveButton(form) {
        var btn = document.getElementById('savebtn') ||
            document.querySelector('input[name="savebtn"], button[name="savebtn"]');

        if (btn && isVisible(btn) && getButtonText(btn).indexOf('\u0413\u0435\u043d\u0435\u0440') !== -1) {
            return btn;
        }

        var buttons = document.querySelectorAll('input[type="button"], button');
        for (var i = 0; i < buttons.length; i++) {
            if (
                isVisible(buttons[i]) &&
                String(buttons[i].className).indexOf('adm-btn-save') !== -1 &&
                getButtonText(buttons[i]).indexOf('\u0413\u0435\u043d\u0435\u0440') !== -1
            ) {
                return buttons[i];
            }
        }

        return null;
    }

    function setButtonText(button, text) {
        if (!button) {
            return;
        }

        if (button.tagName.toLowerCase() === 'input') {
            button.value = text;
        } else {
            button.textContent = text;
        }
    }

    function init() {
        var form = findForm();
        if (!form) {
            return false;
        }

        if (form.getAttribute('data-offer-generator-ajax-ready') === 'Y') {
            return true;
        }

        var saveBtn = findSaveButton(form);
        if (!saveBtn) {
            return false;
        }

        var visibleSaveBtn = findVisibleSaveButton(form) || saveBtn;
        var insertBeforeBtn = isVisible(visibleSaveBtn) ? visibleSaveBtn : saveBtn;

        form.setAttribute('data-offer-generator-ajax-ready', 'Y');

        // Rename the standard button
        setButtonText(saveBtn, '\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0438 \u0437\u0430\u043a\u0440\u044b\u0442\u044c');
        if (visibleSaveBtn !== saveBtn) {
            setButtonText(visibleSaveBtn, '\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0438 \u0437\u0430\u043a\u0440\u044b\u0442\u044c');
        }

        // Create new "Generate" button
        var newBtn = document.createElement('input');
        newBtn.type = 'button';
        newBtn.value = '\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c';
        newBtn.className = visibleSaveBtn.className || saveBtn.className;
        newBtn.setAttribute('data-offer-generator-ajax-button', 'Y');
        newBtn.style.marginRight = '10px';

        // Insert new button before the standard one
        insertBeforeBtn.parentNode.insertBefore(newBtn, insertBeforeBtn);

        // Click handler for the new button
        newBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (isGenerating) {
                return;
            }

            isGenerating = true;
            newBtn.disabled = true;
            newBtn.value = '\u0413\u0435\u043d\u0435\u0440\u0430\u0446\u0438\u044f...';

            var formData = new FormData(form);
            // Ensure 'save' field is present for server-side check
            if (!formData.has('save')) {
                formData.append('save', 'Y');
            }

            var actionUrl = form.action || form.getAttribute('action') || window.location.href;

            fetch(actionUrl, {
                method: 'POST',
                body: formData,
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    // Do NOT insert response into DOM or execute as JS
                    return response.text();
                })
                .then(function () {
                    // Reload the offers list in the parent window
                    reloadOffersList();
                })
                .catch(function (err) {
                    alert('\u041e\u0448\u0438\u0431\u043a\u0430 \u0433\u0435\u043d\u0435\u0440\u0430\u0446\u0438\u0438: ' + err.message);
                })
                .finally(function () {
                    newBtn.disabled = false;
                    newBtn.value = '\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c';
                    isGenerating = false;
                });
        });

        return true;
    }

    function scheduleInit() {
        if (init()) {
            if (retryTimer) {
                clearTimeout(retryTimer);
                retryTimer = null;
            }
            return;
        }

        if (retryCount >= maxRetries || retryTimer) {
            return;
        }

        retryCount++;
        retryTimer = setTimeout(function () {
            retryTimer = null;
            scheduleInit();
        }, retryDelay);
    }

    function watchGeneratorForm() {
        scheduleInit();

        if (!window.MutationObserver || !document.body) {
            return;
        }

        observer = new MutationObserver(function () {
            scheduleInit();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    if (window.BX && typeof BX.ready === 'function') {
        BX.ready(watchGeneratorForm);
    } else {
        document.addEventListener('DOMContentLoaded', watchGeneratorForm);
    }
})();
