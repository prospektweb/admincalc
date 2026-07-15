(function (window, BX) {
  'use strict';
  if (!BX) { return; }

  var cfg = window.ProspektDesiredReceiveDateConfig || {};
  var ajaxUrl = cfg.ajaxUrl || '/local/tools/prospekt_layout/desired_receive_date.php';
  var picker;
  var input;
  var root;
  var status;
  var clearBtn;
  var constraints = {};
  var timeEnabled = false;

  function pad(v) { v = parseInt(v, 10) || 0; return v < 10 ? '0' + v : '' + v; }
  function parseDate(value) {
    if (!value) { return null; }
    var parts = value.replace('T', ' ').split(/[- :.]/);
    if (parts.length >= 5 && parts[0].length === 4) {
      return new Date(parts[0], parts[1] - 1, parts[2], parts[3], parts[4], 0, 0);
    }
    if (parts.length >= 5) {
      return new Date(parts[2], parts[1] - 1, parts[0], parts[3], parts[4], 0, 0);
    }
    return null;
  }
  function formatDisplay(date) { return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear() + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()); }
  function formatPost(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()); }
  function dateKey(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
  function holidayKey(date) { return pad(date.getDate()) + '.' + pad(date.getMonth() + 1); }
  function sameDay(a, b) { return a && b && dateKey(a) === dateKey(b); }
  function minutes(value) { var p = (value || '00:00').split(':'); return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0); }
  function setTime(date, value) { var p = (value || '00:00').split(':'); date.setHours(parseInt(p[0], 10) || 0, parseInt(p[1], 10) || 0, 0, 0); return date; }
  function ceilToStep(date) {
    var step = (parseInt(constraints.stepMinutes, 10) || 1) * 60000;
    return new Date(Math.ceil(date.getTime() / step) * step);
  }
  function minDate() { return parseDate(constraints.minDate); }
  function isWorkingDay(date) {
    var day = date.getDay() === 0 ? 7 : date.getDay();
    var workdays = constraints.workdays || [1,2,3,4,5];
    if (workdays.indexOf(day) === -1) { return false; }
    return (constraints.holidays || []).indexOf(holidayKey(date)) === -1;
  }
  function buildDateWithDefaultTime(date) {
    var result = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
    setTime(result, constraints.defaultTime || '11:00');
    var min = minDate();
    if (min && sameDay(result, min) && result < min) { result = new Date(min); }
    return ceilToStep(result);
  }
  function isDateDisabled(date) {
    if (!isWorkingDay(date)) { return true; }
    var min = minDate();
    if (!min) { return false; }
    var end = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59, 999);
    return end < min;
  }
  function request(action, data, callback) {
    data = data || {};
    data.sessid = BX.bitrix_sessid();
    BX.ajax({
      url: ajaxUrl + '?action=' + encodeURIComponent(action),
      method: 'POST',
      dataType: 'json',
      data: data,
      onsuccess: function (response) { callback(!!(response && response.success), response && response.success ? (response.data || {}) : ((response && response.error) || 'Не удалось выполнить запрос.')); },
      onfailure: function () { callback(false, 'Не удалось выполнить запрос. Попробуйте позже.'); }
    });
  }
  function updateClearButton(value) {
    if (clearBtn) { clearBtn.hidden = !value; }
  }
  function removeBasketItemDateDisplays() {
    var nodes = document.querySelectorAll('[data-prospekt-desired-date-item], .prospekt-desired-date-item');
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].parentNode) { nodes[i].parentNode.removeChild(nodes[i]); }
    }
  }
  function updateBasketItemDates(value) {
    removeBasketItemDateDisplays();
    var nodes = document.querySelectorAll('[data-prospekt-desired-date-item]');
    for (var i = 0; i < nodes.length; i++) {
      var valueNode = nodes[i].querySelector('[data-prospekt-desired-date-item-value]');
      if (valueNode) { valueNode.innerHTML = BX.util.htmlspecialchars(value || ''); }
      nodes[i].hidden = !value;
    }
    updateClearButton(value);
  }
  function setStatus(text, type) {
    if (!status) { return; }
    status.innerHTML = BX.util.htmlspecialchars(text || '');
    status.className = 'prospekt-desired-date__status' + (type ? ' prospekt-desired-date__status--' + type : '');
  }
  function normalizeSelectedDate(date) {
    var min = minDate();
    var from = minutes(constraints.timeFrom || '09:00');
    var to = minutes(constraints.timeTo || '18:00');
    var current = date.getHours() * 60 + date.getMinutes();
    if (current < from) { setTime(date, constraints.timeFrom || '09:00'); }
    if (current > to) { setTime(date, constraints.timeTo || '18:00'); }
    date = ceilToStep(date);
    if (min && sameDay(date, min) && date < min) { date = new Date(min); }
    return date;
  }
  function save(date) {
    if (!date) { return; }
    date = normalizeSelectedDate(date);
    input.value = formatDisplay(date);
    if (picker && picker.selectDate) { picker.selectDate(date); }
    request('set', { value: formatPost(date) }, function (ok, data) {
      if (!ok) { return; }
      constraints = data.constraints || constraints;
      input.value = data.value || input.value;
      updateBasketItemDates(data.value || input.value);
      setStatus('', '');
    });
  }
  function clearValue() {
    input.value = '';
    request('clear', {}, function (ok, data) {
      if (ok) { constraints = data.constraints || constraints; }
      updateBasketItemDates('');
      if (picker && picker.update) { picker.update({ selectedDates: [] }); }
    });
  }
  function refreshCurrent() {
    request('get', {}, function (ok, data) {
      if (!ok) { return; }
      applyData(data);
    });
  }
  function toggleTimeButton(dp) {
    timeEnabled = !timeEnabled;
    dp.update({ timepicker: timeEnabled, buttons: [{ content: timeEnabled ? 'Выключить выбор времени' : 'Указать время', onClick: toggleTimeButton }] });
    var d = dp.selectedDate || buildDateWithDefaultTime(minDate() || new Date());
    if (timeEnabled) { setTime(d, constraints.defaultTime || '11:00'); }
    save(d);
  }
  function initPicker(selected) {
    if (picker || !window.AirDatepicker) { return; }
    var start = selected || minDate() || new Date();
    picker = new AirDatepicker(input, {
      startDate: start,
      selectedDates: selected ? [selected] : [],
      minutesStep: parseInt(constraints.stepMinutes, 10) || 30,
      minHours: Math.floor(minutes(constraints.timeFrom || '09:00') / 60),
      maxHours: Math.floor(minutes(constraints.timeTo || '18:00') / 60),
      timepicker: false,
      buttons: [{ content: 'Указать время', onClick: toggleTimeButton }],
      onRenderCell: function (ctx) {
        if (ctx.cellType !== 'day') { return {}; }
        var date = ctx.date;
        if (!isWorkingDay(date)) { return { disabled: true, classes: ' -disabled-interval-', attrs: { title: 'Нерабочий день' } }; }
        if (isDateDisabled(date)) { return { disabled: true, classes: ' -disabled-interval-', attrs: { title: 'Недоступно с учетом сроков производства' } }; }
        var cls = date.getDay() === 0 || date.getDay() === 6 ? ' -weekend-' : '';
        if ((constraints.holidays || []).indexOf(holidayKey(date)) !== -1) { cls += ' -holiday-'; }
        return { classes: cls };
      },
      onSelect: function (ctx) { save(timeEnabled ? ctx.date : buildDateWithDefaultTime(ctx.date)); }
    });
  }
  function applyData(data) {
    constraints = data.constraints || constraints;
    var selected = parseDate(data.isoValue || '');
    input.placeholder = data.placeholder || 'Желаемая дата получения';
    if (selected) {
      input.value = pad(selected.getDate()) + '.' + pad(selected.getMonth() + 1) + '.' + selected.getFullYear() + ' ' + pad(selected.getHours()) + ':' + pad(selected.getMinutes());
    } else {
      input.value = '';
    }
    updateBasketItemDates(data.value || '');
    if (picker && picker.update) {
      picker.update({
        startDate: selected || minDate() || new Date(),
        selectedDates: selected ? [selected] : [],
        minutesStep: parseInt(constraints.stepMinutes, 10) || 30,
        minHours: Math.floor(minutes(constraints.timeFrom || '09:00') / 60),
        maxHours: Math.floor(minutes(constraints.timeTo || '18:00') / 60)
      });
    }
    initPicker(selected);
  }
  function initBlock(block) {
    root = block;
    var nextInput = root.querySelector('[data-prospekt-desired-date-input]');
    status = root.querySelector('[data-prospekt-desired-date-status]');
    clearBtn = root.querySelector('[data-prospekt-desired-date-clear]');
    if (!nextInput || nextInput.getAttribute('data-prospekt-inited') === 'Y') { return; }
    if (picker && input !== nextInput && picker.destroy) { picker.destroy(); picker = null; }
    input = nextInput;
    input.setAttribute('data-prospekt-inited', 'Y');
    BX.bind(input, 'click', function () { if (picker) { picker.show(); } });
    BX.bind(input, 'keydown', function (e) { e.preventDefault(); });
    if (clearBtn) { BX.bind(clearBtn, 'click', function (e) { e.preventDefault(); e.stopPropagation(); clearValue(); }); }
    request('get', {}, function (ok, data) {
      if (!ok) { return; }
      applyData(data);
      request('sync', {}, function (ok, syncData) { if (ok) { applyData(syncData); } });
      window.clearInterval(window.ProspektDesiredReceiveDateInterval);
      window.ProspektDesiredReceiveDateInterval = window.setInterval(refreshCurrent, 60000);
    });
  }
  function createFallbackBlock() {
    if (document.querySelector('[data-prospekt-desired-date]')) { return null; }
    var empty = document.querySelector('[data-entity="basket-item-list-empty-result"], #basket-item-list-empty-result');
    if (!empty) { return null; }
    var host = document.querySelector('#basket-root') || empty.parentNode;
    if (!host) { return null; }
    var block = document.createElement('div');
    block.className = 'prospekt-desired-date prospekt-desired-date--empty';
    block.setAttribute('data-prospekt-desired-date', '');
    block.setAttribute('data-prospekt-desired-date-fallback', 'Y');
    block.innerHTML = '<span data-toggle="tooltip" title="Дата ориентировочная. Точный график производства утвердим после проверки состава заказа. Любые изменения проводим только по согласованию с Вами."><div class="form"><div class="form-group" style="position: relative;"><input type="text" class="form-control prospekt-desired-date__input" style="cursor: pointer !important;" placeholder="Желаемая дата получения" data-prospekt-desired-date-input readonly><button type="button" class="prospekt-desired-date__clear" data-prospekt-desired-date-clear hidden aria-label="Сбросить желаемую дату"><span aria-hidden="true">&times;</span></button><span class="basket-coupon-block-coupon-btn prospekt-desired-date__btn"><i class="fa fa-calendar"></i></span></div></div></span>';
    host.insertBefore(block, host.firstChild);
    return block;
  }
  function getDateBlocks() {
    return document.querySelectorAll('[data-prospekt-desired-date]');
  }
  function removeObsoleteFallbacks() {
    var blocks = getDateBlocks();
    var hasRealBlock = false;
    for (var i = 0; i < blocks.length; i++) {
      if (blocks[i].getAttribute('data-prospekt-desired-date-fallback') !== 'Y') {
        hasRealBlock = true;
        break;
      }
    }
    if (!hasRealBlock) { return; }
    for (var j = 0; j < blocks.length; j++) {
      if (blocks[j].getAttribute('data-prospekt-desired-date-fallback') === 'Y' && blocks[j].parentNode) {
        blocks[j].parentNode.removeChild(blocks[j]);
      }
    }
  }
  function getPreferredBlock() {
    removeObsoleteFallbacks();
    var blocks = getDateBlocks();
    if (!blocks.length) { return createFallbackBlock(); }
    for (var i = blocks.length - 1; i >= 0; i--) {
      if (blocks[i].getAttribute('data-prospekt-desired-date-fallback') !== 'Y') {
        return blocks[i];
      }
    }
    return blocks[0];
  }
  function init() {
    removeBasketItemDateDisplays();
    var block = getPreferredBlock();
    if (block) { initBlock(block); }
  }
  BX.ready(init);
  BX.addCustomEvent('OnBasketChange', init);
  window.ProspektDesiredReceiveDate = { init: init };
})(window, window.BX);
