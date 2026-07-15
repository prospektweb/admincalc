(function (window, document) {
  'use strict';
  var state = {};
  function getConfig() { return window.ProspektLayoutFilesConfig || {}; }
  function qs(root, sel) { return root.querySelector(sel); }
  function show(node, yes) { if (node) { node.hidden = !yes; } }
  function error(block, text) { var node = qs(block, '[data-prospekt-layout-error]'); if (node) { node.textContent = text || ''; show(node, !!text); } }
  function allowed(name) { var config = getConfig(); var ext = (name.split('.').pop() || '').toLowerCase(); return (config.extensions || []).indexOf(ext) !== -1; }
  function request(action, data, callback) {
    var config = getConfig();
    data = data || {}; data.sessid = config.sessid; data.action = action;
    BX.ajax({ url: config.ajaxUrl, method: 'POST', dataType: 'json', data: data, onsuccess: function (res) { callback(res && res.success, res && (res.data || res.error)); }, onfailure: function () { callback(false, 'Ошибка соединения.'); } });
  }
  function render(block, file) {
    var has = file && file.id;
    var name = qs(block, '[data-prospekt-layout-name]');
    var size = qs(block, '[data-prospekt-layout-size]');
    show(qs(block, '[data-prospekt-layout-result]'), has);
    show(qs(block, '[data-prospekt-layout-progress]'), false);
    if (has) {
      if (name) { name.textContent = file.name; name.removeAttribute('href'); }
      if (size) { size.textContent = file.sizeFormatted ? ' (' + file.sizeFormatted + ')' : ''; }
      state[block.getAttribute('data-basket-id')] = file;
    } else {
      if (name) { name.textContent = ''; name.removeAttribute('href'); }
      if (size) { size.textContent = ''; }
      delete state[block.getAttribute('data-basket-id')];
    }
  }
  function upload(block, file) {
    var config = getConfig();
    error(block, '');
    if (!allowed(file.name)) { error(block, 'Недопустимое расширение файла.'); return; }
    if (file.size <= 0 || file.size > config.maxSize) { error(block, 'Размер файла превышает 100 МБ.'); return; }
    show(qs(block, '[data-prospekt-layout-result]'), false); show(qs(block, '[data-prospekt-layout-progress]'), true);
    var bar = qs(block, '[data-prospekt-layout-progress] span'); if (bar) { bar.style.width = '0%'; }
    request('init', { basketId: block.getAttribute('data-basket-id'), productId: block.getAttribute('data-product-id'), name: file.name, size: file.size }, function (ok, data) {
      if (!ok) { show(qs(block, '[data-prospekt-layout-progress]'), false); error(block, data); return; }
      var xhr = new XMLHttpRequest(); xhr.open('PUT', data.uploadHref, true);
      xhr.upload.onprogress = function (e) { if (e.lengthComputable && bar) { bar.style.width = Math.round(e.loaded / e.total * 100) + '%'; } };
      xhr.onload = function () { if (xhr.status >= 200 && xhr.status < 300) { request('complete', { fileId: data.fileId, hash: data.hash }, function (done, info) { if (done) render(block, info); else { show(qs(block, '[data-prospekt-layout-progress]'), false); error(block, info); } }); } else { show(qs(block, '[data-prospekt-layout-progress]'), false); error(block, 'Ошибка загрузки на Яндекс.Диск.'); } };
      xhr.onerror = function () { show(qs(block, '[data-prospekt-layout-progress]'), false); error(block, 'Ошибка загрузки.'); };
      xhr.send(file);
    });
  }
  function initBlock(block) {
    if (block.getAttribute('data-prospekt-layout-inited') === 'Y') return;
    block.setAttribute('data-prospekt-layout-inited', 'Y');
    var input = qs(block, '[data-prospekt-layout-input]');
    var attach = qs(block, '[data-prospekt-layout-attach]');
    var config = getConfig();
    if (attach && config.tooltipText) { attach.setAttribute('title', config.tooltipText); }
    BX.bind(attach, 'click', function () { input.click(); });
    BX.bind(input, 'change', function () { if (input.files && input.files[0]) upload(block, input.files[0]); input.value = ''; });
    BX.bind(qs(block, '[data-prospekt-layout-delete]'), 'click', function () { var file = state[block.getAttribute('data-basket-id')]; if (!file) return; request('delete', { fileId: file.id, hash: file.hash }, function (ok, msg) { if (ok) { delete state[block.getAttribute('data-basket-id')]; render(block, null); } else error(block, msg); }); });
    request('list', { basketId: block.getAttribute('data-basket-id') }, function (ok, file) { if (ok && file && file.id) render(block, file); });
  }
  window.ProspektLayoutFiles = { init: function (root) { var blocks = (root || document).querySelectorAll('[data-prospekt-layout]'); for (var i = 0; i < blocks.length; i++) initBlock(blocks[i]); } };
  BX.ready(function () { window.ProspektLayoutFiles.init(document); });
})(window, document);
