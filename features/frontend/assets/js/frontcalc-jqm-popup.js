(function (window, document, $) {
  if (!$ || window.__frontcalcJqmReady) {
    return;
  }
  window.__frontcalcJqmReady = true;

  function escapeHtml(str) {
    return String(str || "").replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[ch] || ch;
    });
  }

  function ensureWrapper() {
    var $wrapper = $("#popup_iframe_wrapper");
    if (!$wrapper.length) {
      $wrapper = $('<div id="popup_iframe_wrapper"></div>').appendTo("body");
    }
    return $wrapper;
  }

  function createFrame() {
    ensureWrapper();

    var $frame = $('<div class="frontcalc_frame jqmWindow jqmWindow--mobile-fill popup"></div>')
      .appendTo("#popup_iframe_wrapper");

    $frame.html(
      '<span class="jqmClose top-close fill-grey-hover" onclick="window.b24form = false;" title="Закрыть">' +
        '<i class="svg inline inline" aria-hidden="true">' +
          '<svg width="14" height="14">' +
            '<use xlink:href="/bitrix/templates/aspro-premier/images/svg/header_icons.svg#close-14-14"></use>' +
          '</svg>' +
        '</i>' +
      '</span>' +
      '<div class="form popup frontcalc-popup-shell">' +
        '<div class="frontcalc-popup-content js-frontcalc-popup-content"></div>' +
      '</div>'
    );

    return $frame;
  }

  var FrontcalcMath = window.FrontcalcMath || {};
  var inflightRequests = {};
  // Page-lifetime UI cache only. This is deliberately separate from calculation
  // hashing/persistence: it lets the same popup reopen without another AJAX round trip.
  var popupInstanceCache = {};

  function setButtonLoading($button, isLoading) {
    if (!$button || !$button.length) {
      return;
    }

    if (isLoading) {
      if (!$button.data("frontcalc-disabled-was")) {
        $button.data("frontcalc-disabled-before", $button.prop("disabled") ? "Y" : "N");
        $button.data("frontcalc-disabled-was", "Y");
      }
      $button.addClass("is-frontcalc-loading").prop("disabled", true);
      if (!$button.children(".frontcalc-button-spinner").length) {
        $button.append('<span class="frontcalc-button-spinner" aria-hidden="true"></span>');
      }
      $button.attr("aria-busy", "true");
      return;
    }

    var wasDisabled = $button.data("frontcalc-disabled-before") === "Y";
    $button.removeClass("is-frontcalc-loading");
    $button.children(".frontcalc-button-spinner").remove();
    $button.removeAttr("aria-busy");
    $button.prop("disabled", wasDisabled);
    $button.removeData("frontcalc-disabled-before");
    $button.removeData("frontcalc-disabled-was");
  }

  function normalizePositiveId(value) {
    var id = String(value == null ? "" : value).trim();
    return parseNumber(id, 0) > 0 ? id : "";
  }

  function getCartButtonOfferId($button) {
    if (!$button || !$button.length) {
      return "";
    }

    var cartSelector = '[data-action="basket"][data-id]';
    var catalogWrapperSelector = ".catalog-table__wrapper, .catalog-list__wrapper, .catalog-block__wrapper";

    function readCartId($scope) {
      if (!$scope || !$scope.length) {
        return "";
      }

      var $cartButton = $scope.is(cartSelector) ? $scope : $scope.find(cartSelector).first();
      return normalizePositiveId($cartButton.attr("data-id"));
    }

    var explicitOfferId = normalizePositiveId($button.attr("data-frontcalc-offer-id"));
    if (explicitOfferId) {
      return explicitOfferId;
    }

    var $parent = $button.parent();
    var parentOfferId = readCartId($parent);
    if (parentOfferId) {
      return parentOfferId;
    }

    var $catalogWrapper = $button.closest(catalogWrapperSelector);
    var catalogOfferId = readCartId($catalogWrapper);
    if (catalogOfferId) {
      return catalogOfferId;
    }

    var buttonNode = $button.get(0);
    var node = buttonNode ? buttonNode.parentElement : null;
    while (node && node !== document.body && node !== document.documentElement) {
      var nestedOfferId = readCartId($(node));
      if (nestedOfferId) {
        return nestedOfferId;
      }
      node = node.parentElement;
    }

    return "";
  }

  function getCurrentOfferId($button) {
    try {
      var currentUrl = new URL(window.location.href);
      var urlOfferId = normalizePositiveId(currentUrl.searchParams.get("oid"));
      if (urlOfferId) {
        return urlOfferId;
      }
    } catch (e) {}

    return getCartButtonOfferId($button);
  }

  function buildRequestInfo($button) {
    var productId = $button.data("frontcalc-product-id") || 0;
    var ajaxUrl = String($button.data("frontcalc-ajax-url") || $button.attr("data-frontcalc-ajax-url") || "");
    var offerId = getCurrentOfferId($button);
    var requestUrl = ajaxUrl;

    return {
      productId: String(productId || ""),
      ajaxUrl: ajaxUrl,
      offerId: String(offerId || ""),
      requestUrl: requestUrl,
      cacheKey: [ajaxUrl, productId, offerId].join("|")
    };
  }

  function renderError($content, message) {
    $content.html('<div class="frontcalc-empty">' + escapeHtml(message || "Не удалось загрузить данные калькулятора.") + "</div>");
  }

  function requestData(url, onSuccess, onError) {
    if (window.fetch) {
      fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("HTTP " + response.status);
          }
          return response.json();
        })
        .then(onSuccess)
        .catch(function (error) {
          onError(error && error.message ? error.message : "fetch_failed");
        });
      return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status < 200 || xhr.status >= 300) {
        onError("HTTP " + xhr.status);
        return;
      }
      try {
        onSuccess(JSON.parse(xhr.responseText));
      } catch (e) {
        onError("bad_json");
      }
    };
    xhr.send();
  }

  function postData(url, data, onSuccess, onError) {
    var body = Object.keys(data || {}).map(function (key) {
      return encodeURIComponent(key) + "=" + encodeURIComponent(data[key] == null ? "" : String(data[key]));
    }).join("&");

    if (window.fetch) {
      fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: body
      })
        .then(function (response) {
          if (!response.ok) throw new Error("HTTP " + response.status);
          return response.json();
        })
        .then(onSuccess)
        .catch(function (error) {
          onError(error && error.message ? error.message : "fetch_failed");
        });
      return;
    }

    $.ajax({
      url: url,
      method: "POST",
      data: data,
      dataType: "json",
      success: onSuccess,
      error: function (_, __, error) { onError(error || "ajax_failed"); }
    });
  }

  function parseNumber(raw, fallback) {
    var normalized = typeof raw === "string" ? raw.replace(/\s+/g, "").replace(",", ".") : raw;
    var num = Number(normalized);
    return Number.isFinite(num) ? num : fallback;
  }

  function formatGroupedNumber(value) {
    var raw = normalizeValueToken(value);
    if (!raw) return "";
    var parts = raw.split(".");
    var sign = parts[0].charAt(0) === "-" ? "-" : "";
    var integer = sign ? parts[0].slice(1) : parts[0];
    if (!/^\d+$/.test(integer)) return String(value || "");
    var grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    return sign + grouped + (parts.length > 1 && parts[1] !== "" ? "." + parts[1] : "");
  }

  function formatQuantityValue(value) {
    return formatGroupedNumber(value) || String(value || "");
  }

  function resolveVolumeUnitLabel(fieldByCode, volumeCode) {
    var field = fieldByCode && fieldByCode[volumeCode] ? fieldByCode[volumeCode] : null;
    if (!field) return "экз";
    var items = getInputItemsForField(field);
    var inputUnit = items.length ? String((items[0] && items[0].unit) || "").trim() : "";
    var fieldUnit = String((field && field.unit) || "").trim();
    var unit = inputUnit || fieldUnit;
    return unit || "экз";
  }

  function clamp(value, min, max) {
    var result = value;
    if (Number.isFinite(min)) result = Math.max(min, result);
    if (Number.isFinite(max)) result = Math.min(max, result);
    return result;
  }

  function normalizeToStep(value, min, step) {
    if (!Number.isFinite(step) || step <= 0) {
      return value;
    }
    var base = Number.isFinite(min) ? min : 0;
    return base + Math.round((value - base) / step) * step;
  }

  function isTruthyFlag(value) {
    return value === true || value === "Y" || value === "1" || value === 1 || value === "true";
  }

  function getFieldCode(field) {
    return String((field && (field.property_code || field.code || field.property || field.prop_code)) || "").trim();
  }

  function getFieldLabel(field, propertyMetaByCode, explicitCode) {
    var code = String(explicitCode || getFieldCode(field)).trim();
    if (code && propertyMetaByCode[code] && propertyMetaByCode[code].name) {
      return propertyMetaByCode[code].name;
    }
    return String((field && (field.label || field.title || field.name)) || "").trim();
  }

  function makeFieldIndexMap(fields) {
    var map = {};
    fields.forEach(function (field) {
      var code = getFieldCode(field);
      if (code) map[code] = field;
    });
    return map;
  }

  function makeCodeOrderMapFromMeta(propertyMetaList) {
    var map = {};
    (Array.isArray(propertyMetaList) ? propertyMetaList : []).forEach(function (meta, index) {
      var code = String((meta && meta.code) || "").trim();
      if (code && map[code] === undefined) {
        map[code] = index;
      }
    });
    return map;
  }

  function makeCodeOrderMapFromFields(fields) {
    var map = {};
    (Array.isArray(fields) ? fields : []).forEach(function (field, index) {
      var code = getFieldCode(field);
      if (code && map[code] === undefined) {
        map[code] = index;
      }
    });
    return map;
  }

  function buildPresetsByProperty(offers) {
    var byCode = {};
    offers.forEach(function (offer) {
      var properties = offer.properties || {};
      Object.keys(properties).forEach(function (code) {
        if (code.indexOf("CALC_PROP_") !== 0) return;
        var prop = properties[code] || {};
        var xmlId = String(prop.xml_id || "").trim();
        if (!xmlId) return;

        if (!byCode[code]) byCode[code] = {};

        byCode[code][xmlId] = {
          value: prop.value || xmlId,
          xml_id: xmlId,
          sort: parseNumber(prop.sort, 500),
        };
      });
    });

    var result = {};
    Object.keys(byCode).forEach(function (code) {
      var arr = Object.keys(byCode[code]).map(function (xmlId) {
        return byCode[code][xmlId];
      });
      arr.sort(function (a, b) {
        return parseNumber(a.sort, 500) - parseNumber(b.sort, 500);
      });
      result[code] = arr;
    });

    return result;
  }

  function gatherAllPropertyCodes(propertyMetaList, offers, fields) {
    var map = {};
    (Array.isArray(propertyMetaList) ? propertyMetaList : []).forEach(function (meta) {
      var code = String((meta && meta.code) || "").trim();
      if (code) map[code] = true;
    });
    (Array.isArray(offers) ? offers : []).forEach(function (offer) {
      var properties = (offer && offer.properties) || {};
      Object.keys(properties).forEach(function (code) {
        if (code.indexOf("CALC_PROP_") === 0) map[code] = true;
      });
    });
    (Array.isArray(fields) ? fields : []).forEach(function (field) {
      var code = getFieldCode(field);
      if (code) map[code] = true;
    });
    return Object.keys(map);
  }

  function buildParticipatingPropertyMap(offers) {
    var map = {};
    (Array.isArray(offers) ? offers : []).forEach(function (offer) {
      var properties = (offer && offer.properties) || {};
      Object.keys(properties).forEach(function (code) {
        if (code.indexOf("CALC_PROP_") !== 0) return;
        var prop = properties[code] || {};
        var xmlId = String(prop.xml_id || "").trim();
        if (!xmlId) return;
        map[code] = true;
      });
    });
    return map;
  }

  function buildPresetsFromConfigFields(fields) {
    var result = {};
    (Array.isArray(fields) ? fields : []).forEach(function (field) {
      var code = getFieldCode(field);
      if (!code) return;
      var source = field.presets || field.values || field.options || [];
      if (!Array.isArray(source) || !source.length) return;
      result[code] = result[code] || [];
      source.forEach(function (row, idx) {
        var xmlId = "";
        var value = "";
        var sort = 500 + idx;

        if (typeof row === "string" || typeof row === "number") {
          xmlId = String(row);
          value = String(row);
        } else if (row && typeof row === "object") {
          xmlId = String(row.xml_id || row.id || row.code || row.value || "").trim();
          value = String(row.value || row.label || row.name || xmlId).trim();
          sort = parseNumber(row.sort, sort);
        }

        if (!xmlId) return;
        result[code].push({ xml_id: xmlId, value: value || xmlId, sort: sort });
      });
    });
    return result;
  }

  function mergePresets(target, incoming) {
    Object.keys(incoming || {}).forEach(function (code) {
      var byXmlId = {};
      (Array.isArray(target[code]) ? target[code] : []).forEach(function (row) {
        byXmlId[String(row.xml_id)] = row;
      });
      (Array.isArray(incoming[code]) ? incoming[code] : []).forEach(function (row) {
        var key = String(row.xml_id || "").trim();
        if (!key) return;
        byXmlId[key] = row;
      });
      var merged = Object.keys(byXmlId).map(function (key) {
        return byXmlId[key];
      });
      merged.sort(function (a, b) {
        return parseNumber(a.sort, 500) - parseNumber(b.sort, 500);
      });
      target[code] = merged;
    });
  }

  function mergeOffersByOfferKey(currentOffers, incomingOffers) {
    if (FrontcalcMath && typeof FrontcalcMath.mergeOffersByOfferKey === "function") {
      return FrontcalcMath.mergeOffersByOfferKey(currentOffers, incomingOffers);
    }
    return (Array.isArray(currentOffers) ? currentOffers : []).concat(Array.isArray(incomingOffers) ? incomingOffers : []);
  }

  function buildCustomSelectionPayload(selectedByProperty, presetsByCode, draftPropertyCode, draftValue, volumeCode, requiredCodes, committedInputValuesByProperty, delimitersByCode) {
    if (FrontcalcMath && typeof FrontcalcMath.buildCustomSelectionPayload === "function") {
      return FrontcalcMath.buildCustomSelectionPayload(selectedByProperty, presetsByCode, draftPropertyCode, draftValue, volumeCode, requiredCodes, committedInputValuesByProperty, delimitersByCode);
    }
    var payload = {};
    Object.keys(selectedByProperty || {}).forEach(function (code) {
      if (code === volumeCode) return;
      payload[code] = { value: String(code === draftPropertyCode ? draftValue : selectedByProperty[code]), xmlId: "" };
    });
    return { payload: payload, error: null };
  }

  function resolvePresetQuantity(preset) {
    if (FrontcalcMath && typeof FrontcalcMath.resolvePresetQuantity === "function") {
      return FrontcalcMath.resolvePresetQuantity(preset);
    }
    var parsed = parseNumber(preset && (preset.value || preset.quantity || preset.xml_id), Number.NaN);
    return Number.isFinite(parsed) && parsed > 0 && Math.round(parsed) === parsed ? parsed : Number.NaN;
  }

  function resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue) {
    if (FrontcalcMath && typeof FrontcalcMath.resolveCurrentTargetQuantity === "function") {
      return FrontcalcMath.resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue);
    }
    var parsed = parseNumber(customVolumeValue, Number.NaN);
    if (Number.isFinite(parsed) && parsed > 0) return Math.round(parsed);
    return Number.NaN;
  }

  function getPresetInputParts(preset, delimiter) {
    if (FrontcalcMath && typeof FrontcalcMath.getPresetInputParts === "function") {
      return FrontcalcMath.getPresetInputParts(preset, delimiter);
    }
    var separator = delimiter || "x";
    var xmlParts = String((preset && preset.xml_id) || "").split(separator);
    var valueParts = String((preset && preset.value) || "").split(separator);
    var hasNumericParts = function (parts) {
      return parts.length > 0 && parts.every(function (part) {
        return Number.isFinite(parseNumber(part, Number.NaN));
      });
    };
    if (hasNumericParts(xmlParts)) return xmlParts;
    if (hasNumericParts(valueParts)) return valueParts;
    return String((preset && (preset.value != null ? preset.value : preset.xml_id)) || "").split(separator);
  }

  function validateInputComponents(values, fields) {
    if (FrontcalcMath && typeof FrontcalcMath.validateInputComponents === "function") {
      return FrontcalcMath.validateInputComponents(values, fields);
    }
    return isCompleteGroupInput(values) ? { valid: true, message: "" } : { valid: false, message: "Заполните корректное значение" };
  }

  function canonicalizeInputComponents(values, fields) {
    if (FrontcalcMath && typeof FrontcalcMath.canonicalizeInputComponents === "function") {
      return FrontcalcMath.canonicalizeInputComponents(values, fields);
    }
    var validation = validateInputComponents(values, fields);
    return validation.valid ? { valid: true, message: "", values: values } : { valid: false, message: validation.message, values: [] };
  }

  function calculateAreaMm2FromValue(rawValue) {
    if (FrontcalcMath && typeof FrontcalcMath.calculateAreaMm2FromValue === "function") {
      return FrontcalcMath.calculateAreaMm2FromValue(rawValue);
    }
    var normalized = String(rawValue || "").replace(/[×*хХ]/g, "x").replace(/,/g, ".");
    var match = normalized.match(/(?:^|[^\d.])(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)(?:[^\d.]|$)/i);
    if (!match) return Number.NaN;
    var width = parseNumber(match[1], Number.NaN);
    var height = parseNumber(match[2], Number.NaN);
    return Number.isFinite(width) && Number.isFinite(height) ? width * height : Number.NaN;
  }

  function canAddToBasketWithDraft(pendingPropertyCode, isLoading) {
    if (FrontcalcMath && typeof FrontcalcMath.canAddToBasketWithDraft === "function") {
      return FrontcalcMath.canAddToBasketWithDraft(pendingPropertyCode, isLoading);
    }
    return isLoading || pendingPropertyCode ? { allowed: false, reason: pendingPropertyCode ? "pending" : "loading", code: pendingPropertyCode } : { allowed: true, reason: "" };
  }

  function restoreDisabledAfterBusy(wasDisabledBeforeBusy) {
    if (FrontcalcMath && typeof FrontcalcMath.restoreDisabledAfterBusy === "function") {
      return FrontcalcMath.restoreDisabledAfterBusy(wasDisabledBeforeBusy);
    }
    return wasDisabledBeforeBusy === true || wasDisabledBeforeBusy === "Y";
  }

  function createInputControl(field, initialValue, callbacks) {
    callbacks = callbacks || {};
    var label = getFieldLabel(field, {}, "");
    var min = parseNumber(field.min, Number.NaN);
    var max = parseNumber(field.max, Number.NaN);
    var step = parseNumber(field.step, 1);
    var value = parseNumber(initialValue, parseNumber(field.default, 0));
    if (!Number.isFinite(value)) value = 0;
    value = clamp(value, min, max);

    var $field = $('<div class="frontcalc-field frontcalc-field--input"></div>');
    $field.append('<div class="frontcalc-field__title sku-props__title">' + escapeHtml(label) + "</div>");

    var $control = $('<div class="frontcalc-input-control"></div>');
    var $minus = $('<button type="button" class="frontcalc-step-btn">−</button>');
    var $input = $('<input type="text" class="frontcalc-num-input" inputmode="decimal">').val(value);
    var $plus = $('<button type="button" class="frontcalc-step-btn">+</button>');

    function setDraft(val) {
      var numeric = parseNumber(val, value);
      numeric = normalizeToStep(clamp(numeric, min, max), min, step);
      value = numeric;
      $input.val(value);
      if (callbacks.onDraft) callbacks.onDraft(value, { field: field });
    }

    $minus.on("click", function () {
      if (callbacks.beforeChange && callbacks.beforeChange() === false) return;
      setDraft(parseNumber($input.val(), value) - step);
    });

    $plus.on("click", function () {
      if (callbacks.beforeChange && callbacks.beforeChange() === false) return;
      setDraft(parseNumber($input.val(), value) + step);
    });

    $input.on("input", function () {
      if (callbacks.beforeChange && callbacks.beforeChange() === false) return;
      if (callbacks.onDraft) callbacks.onDraft($input.val(), { raw: true, field: field });
    });

    $input.on("blur", function () {
      var numeric = parseNumber($input.val(), Number.NaN);
      if (!Number.isFinite(numeric)) return;
      numeric = normalizeToStep(clamp(numeric, min, max), min, step);
      value = numeric;
      $input.val(value);
      if (callbacks.onDraft) callbacks.onDraft(value, { field: field, normalized: true });
    });

    $input.on("wheel", function (event) {
      if (document.activeElement !== $input[0]) return;
      event.preventDefault();
      if (callbacks.beforeChange && callbacks.beforeChange() === false) return;
      var delta = event.originalEvent && event.originalEvent.deltaY < 0 ? step : -step;
      setDraft(parseNumber($input.val(), value) + delta);
    });

    $input.on("keydown", function (event) {
      if (event.key === "Escape" && callbacks.onReset) {
        event.preventDefault();
        if (callbacks.beforeChange && callbacks.beforeChange() === false) return;
        callbacks.onReset();
      }
      if (event.key === "Enter" && callbacks.onConfirm) {
        event.preventDefault();
        callbacks.onConfirm();
      }
    });

    $control.append($minus, $input, $plus);

    var showUnit = Object.prototype.hasOwnProperty.call(field || {}, "show_unit")
      ? isTruthyFlag(field.show_unit)
      : true;
    var unitText = String((field && field.unit) || "").trim();
    var $controlWrap = $('<div class="frontcalc-input-control-wrap"></div>');
    $controlWrap.append($control);
    if (showUnit && unitText) {
      $controlWrap.append('<span class="frontcalc-input-unit">' + escapeHtml(unitText) + "</span>");
    }

    $field.append($controlWrap);
    return $field;
  }

  function createPresetButtons(presets, onSelect) {
    var $wrap = $('<div class="frontcalc-presets"></div>');
    presets.forEach(function (preset) {
      var $btn = $('<button type="button" class="frontcalc-chip"></button>')
        .text(preset.value || preset.xml_id)
        .attr("data-xml-id", preset.xml_id);

      $btn.on("click", function () {
        $wrap.find(".is-active").removeClass("is-active");
        $btn.addClass("is-active");
        onSelect(preset);
      });
      $wrap.append($btn);
    });
    return $wrap;
  }

  function getOfferPropertyToken(property) {
    if (FrontcalcMath && typeof FrontcalcMath.getOfferPropertyToken === "function") {
      return FrontcalcMath.getOfferPropertyToken(property);
    }
    if (!property) return "";
    return normalizeValueToken(property.xmlId != null ? property.xmlId : (property.xml_id != null ? property.xml_id : property.value));
  }

  function resolveOfferQuantity(offer, volumeCode) {
    if (FrontcalcMath && typeof FrontcalcMath.resolveOfferQuantity === "function") {
      return FrontcalcMath.resolveOfferQuantity(offer, volumeCode);
    }
    var props = (offer && offer.properties) || {};
    return parseNumber(getOfferPropertyToken(props[volumeCode]), Number.NaN);
  }

  function areCurrenciesEqual(left, right) {
    if (FrontcalcMath && typeof FrontcalcMath.areCurrenciesEqual === "function") {
      return FrontcalcMath.areCurrenciesEqual(left, right);
    }
    return String(left || "RUB").trim().toUpperCase() === String(right || "RUB").trim().toUpperCase();
  }

  function isRealOffer(offer) {
    return !(offer && (offer.is_virtual || offer.isVirtual || offer.source === "calc-server"));
  }

  function pickBetterPoint(existing, candidate) {
    if (!existing) return candidate;
    var existingDirect = existing.offer && existing.offer.internal && existing.offer.internal.directPurchasePrice;
    var candidateDirect = candidate.offer && candidate.offer.internal && candidate.offer.internal.directPurchasePrice;
    if (candidateDirect != null && existingDirect == null) return candidate;
    if (isRealOffer(candidate.offer) !== isRealOffer(existing.offer)) {
      return isRealOffer(candidate.offer) ? candidate : existing;
    }
    return candidate.index < existing.index ? candidate : existing;
  }

  function offerMatchesSelectionExcept(offer, selectedByProperty, customByProperty, skipCode) {
    if (FrontcalcMath && typeof FrontcalcMath.offerMatchesSelectionExcept === "function") {
      return FrontcalcMath.offerMatchesSelectionExcept(offer, selectedByProperty, customByProperty, skipCode);
    }
    var props = (offer && offer.properties) || {};
    for (var code in selectedByProperty) {
      if (!Object.prototype.hasOwnProperty.call(selectedByProperty, code)) continue;
      if (skipCode && code === skipCode) continue;
      if (customByProperty && customByProperty[code]) return false;
      var selectedToken = normalizeValueToken(selectedByProperty[code]);
      if (!selectedToken) continue;
      if (getOfferPropertyToken(props[code]) !== selectedToken) return false;
    }
    return true;
  }

  function pickMatchedOffer(offers, selectedByProperty, customByProperty) {
    for (var customCode in customByProperty) {
      if (!Object.prototype.hasOwnProperty.call(customByProperty, customCode)) continue;
      if (customByProperty[customCode]) return null;
    }

    for (var i = 0; i < offers.length; i++) {
      var offer = offers[i];
      var props = offer.properties || {};
      var matched = true;

      for (var code in selectedByProperty) {
        if (!Object.prototype.hasOwnProperty.call(selectedByProperty, code)) continue;
        var selectedToken = normalizeValueToken(selectedByProperty[code]);
        if (!selectedToken) continue;
        if (getOfferPropertyToken(props[code]) !== selectedToken) {
          matched = false;
          break;
        }
      }

      if (matched) return offer;
    }
    return null;
  }

  function getFilteredOffers(offers, selectedByProperty, customByProperty, skipCode) {
    var list = Array.isArray(offers) ? offers : [];
    return list.filter(function (offer) {
      var props = (offer && offer.properties) || {};
      for (var code in selectedByProperty) {
        if (!Object.prototype.hasOwnProperty.call(selectedByProperty, code)) continue;
        if (skipCode && code === skipCode) continue;
        if (customByProperty[code]) continue;
        var selectedToken = normalizeValueToken(selectedByProperty[code]);
        if (!selectedToken) continue;
        if (getOfferPropertyToken(props[code]) !== selectedToken) {
          return false;
        }
      }
      return true;
    });
  }

  function pickMatchedOfferIgnoringCustom(offers, selectedByProperty, customByProperty, skipCode) {
    var filtered = getFilteredOffers(offers, selectedByProperty, customByProperty || {}, skipCode || null);
    return filtered.length ? filtered[0] : null;
  }

  function pickCalcServerTitleOffer(offers, selectedByProperty, customByProperty, volumeCode, targetQuantity) {
    var target = parseNumber(targetQuantity, Number.NaN);
    var filtered = getFilteredOffers(offers, selectedByProperty, customByProperty || {}, volumeCode).filter(function (offer) {
      return offer && String(offer.source || "") === "calc-server" && String(offer.name || "").trim() !== "";
    });
    filtered.sort(function (left, right) {
      function titleQuantity(offer) {
        var match = String((offer && offer.name) || "").match(/([\d\s\u00a0]+)\s*экз\.?\s*$/i);
        var parsed = match ? parseNumber(String(match[1]).replace(/[\s\u00a0]+/g, ""), Number.NaN) : Number.NaN;
        return Number.isFinite(parsed) ? parsed : resolveOfferQuantity(offer, volumeCode);
      }
      var leftQuantity = titleQuantity(left);
      var rightQuantity = titleQuantity(right);
      var leftDistance = Number.isFinite(target) && Number.isFinite(leftQuantity) ? Math.abs(leftQuantity - target) : Number.POSITIVE_INFINITY;
      var rightDistance = Number.isFinite(target) && Number.isFinite(rightQuantity) ? Math.abs(rightQuantity - target) : Number.POSITIVE_INFINITY;
      return leftDistance - rightDistance;
    });
    return filtered.length ? filtered[0] : null;
  }

  function normalizeValueToken(value) {
    if (FrontcalcMath && typeof FrontcalcMath.normalizeValueToken === "function") {
      return FrontcalcMath.normalizeValueToken(value);
    }
    return String(value || "")
      .replace(/\s+/g, "")
      .replace(",", ".")
      .trim();
  }

  function findPresetByInputValue(presets, numericValue) {
    var normalizedInput = normalizeValueToken(numericValue);
    var numericInput = parseNumber(normalizedInput, Number.NaN);
    for (var i = 0; i < presets.length; i++) {
      var preset = presets[i] || {};
      var presetXmlId = normalizeValueToken(preset.xml_id);
      var presetValue = normalizeValueToken(preset.value);
      if (normalizedInput && (presetXmlId === normalizedInput || presetValue === normalizedInput)) {
        return preset;
      }

      var numericXml = parseNumber(presetXmlId, Number.NaN);
      var numericValuePreset = parseNumber(presetValue, Number.NaN);
      if (Number.isFinite(numericInput) && (numericXml === numericInput || numericValuePreset === numericInput)) {
        return preset;
      }
    }
    return null;
  }

  function isCompleteGroupInput(values) {
    if (!Array.isArray(values) || !values.length) return false;
    for (var i = 0; i < values.length; i++) {
      var normalized = normalizeValueToken(values[i]);
      if (!normalized) return false;
      if (!Number.isFinite(parseNumber(normalized, Number.NaN))) return false;
    }
    return true;
  }

  function formatMoneyRow(priceObj) {
    if (!priceObj) return "—";
    return String(priceObj.formatted || ((priceObj.price || 0) + " " + (priceObj.currency || "₽")));
  }

  function getOfferPriceRanges(offer) {
    var catalog = offer && offer.catalog ? offer.catalog : {};
    if (Array.isArray(catalog.price_ranges)) return catalog.price_ranges;
    if (Array.isArray(catalog.prices_view_all)) return catalog.prices_view_all;
    if (Array.isArray(catalog.prices)) return catalog.prices;
    if (Array.isArray(catalog.prices_view)) return catalog.prices_view;
    return [];
  }

  function normalizeRangeBound(value, fallback) {
    if (value === null || typeof value === "undefined" || value === "") return fallback;
    var num = parseNumber(value, Number.NaN);
    return Number.isFinite(num) ? num : fallback;
  }

  function sortPriceRanges(ranges) {
    return (Array.isArray(ranges) ? ranges.slice() : []).sort(function (a, b) {
      var fromA = normalizeRangeBound(a && a.quantity_from, 0);
      var fromB = normalizeRangeBound(b && b.quantity_from, 0);
      if (fromA !== fromB) return fromA - fromB;

      var toA = normalizeRangeBound(a && a.quantity_to, Number.POSITIVE_INFINITY);
      var toB = normalizeRangeBound(b && b.quantity_to, Number.POSITIVE_INFINITY);
      if (toA !== toB) return toA - toB;

      return parseNumber(a && a.price, 0) - parseNumber(b && b.price, 0);
    });
  }

  function getRangesByCatalogGroup(offer, catalogGroupId) {
    var groupId = parseNumber(catalogGroupId, Number.NaN);
    if (!Number.isFinite(groupId)) return [];
    return sortPriceRanges(getOfferPriceRanges(offer).filter(function (row) {
      return parseNumber(row && row.catalog_group_id, Number.NaN) === groupId;
    }));
  }

  function pickRangePriceForQuantity(ranges, quantity) {
    var targetQuantity = parseNumber(quantity, Number.NaN);
    if (!Number.isFinite(targetQuantity)) return null;

    var sorted = sortPriceRanges(ranges);
    for (var i = 0; i < sorted.length; i++) {
      var row = sorted[i];
      var from = normalizeRangeBound(row && row.quantity_from, 0);
      var to = normalizeRangeBound(row && row.quantity_to, Number.POSITIVE_INFINITY);
      if (targetQuantity >= from && targetQuantity <= to) {
        return row;
      }
    }

    return null;
  }


  function collectAvailablePriceGroups(priceGroups, offers) {
    var byId = {};
    (Array.isArray(priceGroups) ? priceGroups : []).forEach(function (group) {
      var id = parseNumber(group && group.id, Number.NaN);
      if (Number.isFinite(id)) {
        byId[id] = { id: id, name: String((group && group.name) || ("PRICE_" + id)) };
      }
    });

    (Array.isArray(offers) ? offers : []).forEach(function (offer) {
      getOfferPriceRanges(offer).forEach(function (row) {
        var id = parseNumber(row && row.catalog_group_id, Number.NaN);
        if (!Number.isFinite(id) || byId[id]) return;
        byId[id] = { id: id, name: String((row && row.catalog_group_name) || ("PRICE_" + id)) };
      });
    });

    return Object.keys(byId)
      .map(function (key) { return byId[key]; })
      .filter(function (group) {
        return (Array.isArray(offers) ? offers : []).some(function (offer) {
          return getRangesByCatalogGroup(offer, group.id).length > 0;
        });
      })
      .sort(function (a, b) { return a.id - b.id; });
  }

  function deriveStepFromPresets(presets) {
    if (FrontcalcMath && typeof FrontcalcMath.deriveStepFromPresets === "function") {
      return FrontcalcMath.deriveStepFromPresets(presets);
    }
    var unique = {};
    var nums = [];
    (Array.isArray(presets) ? presets : []).forEach(function (preset) {
      var quantity = resolvePresetQuantity(preset);
      if (!Number.isFinite(quantity) || Object.prototype.hasOwnProperty.call(unique, quantity)) return;
      unique[quantity] = true;
      nums.push(quantity);
    });
    nums.sort(function (a, b) { return a - b; });
    if (nums.length < 2) return 1;
    var minDiff = Number.POSITIVE_INFINITY;
    for (var i = 1; i < nums.length; i++) {
      var diff = nums[i] - nums[i - 1];
      if (diff > 0 && diff < minDiff) minDiff = diff;
    }
    return Number.isFinite(minDiff) ? minDiff : 1;
  }

  function resolveConfiguredFieldNumber(fieldConfig, key, isAllowed) {
    if (!fieldConfig || typeof fieldConfig !== "object") return Number.NaN;

    function parseConfiguredNumber(raw) {
      if (typeof raw === "string" && raw.trim() === "") return Number.NaN;
      return parseNumber(raw, Number.NaN);
    }

    function isUsable(value) {
      return Number.isFinite(value) && (!isAllowed || isAllowed(value));
    }

    var direct = parseConfiguredNumber(fieldConfig[key]);
    if (isUsable(direct)) return direct;

    var nestedKeys = ["group_inputs", "inputs", "values"];
    for (var k = 0; k < nestedKeys.length; k++) {
      var arr = fieldConfig[nestedKeys[k]];
      if (!Array.isArray(arr)) continue;
      for (var i = 0; i < arr.length; i++) {
        var row = arr[i] || {};
        var nested = parseConfiguredNumber(row[key]);
        if (isUsable(nested)) return nested;
      }
    }

    return Number.NaN;
  }

  function resolveConfiguredBound(fieldConfig, key) {
    return resolveConfiguredFieldNumber(fieldConfig, key);
  }

  function resolveConfiguredStep(fieldConfig) {
    return resolveConfiguredFieldNumber(fieldConfig, "step", function (value) {
      return value > 0;
    });
  }


  function getRangePriceForColumn(offer, catalogGroupId, targetQuantity) {
    return pickRangePriceForQuantity(getRangesByCatalogGroup(offer, catalogGroupId), targetQuantity);
  }

  function formatMoneyValue(value, currency) {
    if (!Number.isFinite(value)) return "—";
    var rounded = Math.round(value * 100) / 100;
    var formatted = String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    return formatted + " " + (currency === "RUB" || currency === "" ? "₽" : currency);
  }

  function buildAllowedVolumeNumbers(baseValues, minValue, maxValue, currentValue, stepValue, tailStepValue) {
    if (FrontcalcMath && typeof FrontcalcMath.buildAllowedVolumeNumbers === "function") {
      return FrontcalcMath.buildAllowedVolumeNumbers(baseValues, minValue, maxValue, currentValue, stepValue, tailStepValue, 1000000);
    }
    var starts = (Array.isArray(baseValues) ? baseValues : []).slice();
    var minV = Number.isFinite(minValue) ? minValue : 1;
    var maxV = Number.isFinite(maxValue) ? maxValue : Number.POSITIVE_INFINITY;
    var step = Number.isFinite(stepValue) && stepValue > 0 ? stepValue : Number.NaN;
    var tailStep = Number.isFinite(tailStepValue) && tailStepValue > 0 ? tailStepValue : Number.NaN;
    var seen = {};
    var list = starts.slice();
    var lastBase = starts.length ? Math.max.apply(Math, starts) : Number.NaN;
    if (Number.isFinite(lastBase) && Number.isFinite(tailStep)) {
      var tailMax = Number.isFinite(maxV) ? maxV : 1000000;
      for (var n = lastBase + tailStep; n <= tailMax; n += tailStep) list.push(n);
    }
    if (Number.isFinite(currentValue)) list.push(currentValue);
    return list.filter(function (n) {
      if (!Number.isFinite(n) || n < minV || n > maxV || seen[n]) return false;
      if (Number.isFinite(step) && Math.abs((n - minV) / step - Math.round((n - minV) / step)) > 0.000001) return false;
      seen[n] = true;
      return true;
    }).sort(function (a, b) { return a - b; });
  }

  function pickFiveCentered(list, currentValue) {
    if (FrontcalcMath && typeof FrontcalcMath.pickFiveCentered === "function") {
      return FrontcalcMath.pickFiveCentered(list, currentValue);
    }
    if (list.length <= 5) return list.slice();
    var idx = list.indexOf(currentValue);
    if (idx < 0) {
      idx = 0;
      for (var i = 0; i < list.length; i++) { if (list[i] <= currentValue) idx = i; }
    }
    var start = idx - 2;
    if (idx >= list.length - 2) start = list.length - 5;
    start = Math.max(0, Math.min(start, list.length - 5));
    return list.slice(start, start + 5);
  }

  function getDeadlineAdjustment(config, type, quantity) {
    if (FrontcalcMath && typeof FrontcalcMath.getDeadlineAdjustment === "function") {
      return FrontcalcMath.getDeadlineAdjustment(config, type, quantity);
    }
    return 0;
  }

  function roundCatalogPriceValue(value, config, catalogGroupId) {
    var byGroup = config && config.rounding_rules ? config.rounding_rules : {};
    var rules = byGroup[String(catalogGroupId)] || byGroup[catalogGroupId] || [];
    if (FrontcalcMath && typeof FrontcalcMath.roundCatalogPrice === "function") {
      return FrontcalcMath.roundCatalogPrice(value, rules);
    }
    return value;
  }

  function applyDeadlineAdjustment(priceObj, config, deadlineType, quantity, catalogGroupId) {
    if (!priceObj) return priceObj;
    var percent = getDeadlineAdjustment(config, deadlineType, quantity);
    var base = parseNumber(priceObj.price, Number.NaN);
    if (!Number.isFinite(base)) return priceObj;
    var price = base;
    if (deadlineType !== "strict" && Number.isFinite(percent) && percent !== 0) {
      var multiplier = deadlineType === "urgent" ? (1 + percent / 100) : (1 - percent / 100);
      price = Math.max(0, base * multiplier);
    }
    price = roundCatalogPriceValue(price, config, catalogGroupId);
    var copy = Object.assign({}, priceObj);
    copy.price = price;
    copy.formatted = formatMoneyValue(price, copy.currency || "RUB");
    return copy;
  }

  function getOfferPriceForCatalogGroup(offer, catalogGroupId, targetQuantity) {
    return offer ? getRangePriceForColumn(offer, catalogGroupId, targetQuantity) : null;
  }

  function hasCustomSelection(customByProperty, exceptCode) {
    for (var code in (customByProperty || {})) {
      if (!Object.prototype.hasOwnProperty.call(customByProperty, code)) continue;
      if (exceptCode && code === exceptCode) continue;
      if (customByProperty[code]) return true;
    }
    return false;
  }

  function selectLinearPoints(points, targetQuantity) {
    if (FrontcalcMath && typeof FrontcalcMath.selectLinearPoints === "function") {
      return FrontcalcMath.selectLinearPoints(points, targetQuantity);
    }
    return points.length ? [points[0]] : [];
  }

  function interpolateLinear(left, right, targetQuantity) {
    if (FrontcalcMath && typeof FrontcalcMath.interpolateLinear === "function") {
      return FrontcalcMath.interpolateLinear(left, right, targetQuantity);
    }
    return left ? Math.max(0, parseNumber(left.value, Number.NaN)) : Number.NaN;
  }

  function collectLinearPoints(offers, selectedByProperty, customByProperty, volumeCode, valueReader) {
    var byQuantity = {};
    (Array.isArray(offers) ? offers : []).forEach(function (candidate, index) {
      if (!offerMatchesSelectionExcept(candidate, selectedByProperty, customByProperty || {}, volumeCode)) return;
      var quantity = resolveOfferQuantity(candidate, volumeCode);
      if (!Number.isFinite(quantity) || quantity <= 0) return;
      var value = valueReader(candidate, quantity);
      var num = parseNumber(value && value.value, Number.NaN);
      if (!Number.isFinite(num)) return;
      var point = Object.assign({}, value, { quantity: quantity, value: num, offer: candidate, index: index });
      byQuantity[String(quantity)] = pickBetterPoint(byQuantity[String(quantity)], point);
    });
    return Object.keys(byQuantity).map(function (key) { return byQuantity[key]; }).sort(function (a, b) { return a.quantity - b.quantity; });
  }

  function getInterpolatedPriceForVolume(offers, selectedByProperty, customByProperty, volumeCode, quantity, catalogGroupId, config, deadlineType) {
    if (hasCustomSelection(customByProperty, volumeCode)) {
      return null;
    }

    var points = collectLinearPoints(offers, selectedByProperty, customByProperty, volumeCode, function (candidate) {
      var price = getOfferPriceForCatalogGroup(candidate, catalogGroupId, 1);
      var priceNum = parseNumber(price && price.price, Number.NaN);
      if (!Number.isFinite(priceNum)) return null;
      return { value: priceNum, currency: price.currency || "RUB" };
    });

    var selectedPoints = selectLinearPoints(points, quantity);
    if (!selectedPoints.length) return null;
    if (selectedPoints.length > 1 && !areCurrenciesEqual(selectedPoints[0].currency, selectedPoints[1].currency)) {
      return null;
    }

    var result = selectedPoints.length === 1
      ? parseNumber(selectedPoints[0].value, Number.NaN)
      : interpolateLinear(selectedPoints[0], selectedPoints[1], quantity);
    if (!Number.isFinite(result)) return null;
    var currency = (selectedPoints[0] && selectedPoints[0].currency) || "RUB";
    return applyDeadlineAdjustment({price: result, currency: currency, formatted: formatMoneyValue(result, currency)}, config, deadlineType || "strict", quantity, catalogGroupId);
  }

  function getPriceSourcePointsForQuantity(offers, selectedByProperty, customByProperty, volumeCode, targetQuantity, catalogGroupId) {
    if (hasCustomSelection(customByProperty, volumeCode)) return null;
    var points = collectLinearPoints(offers, selectedByProperty, customByProperty, volumeCode, function (candidate) {
      var price = getOfferPriceForCatalogGroup(candidate, catalogGroupId, 1);
      var priceNum = parseNumber(price && price.price, Number.NaN);
      if (!Number.isFinite(priceNum)) return null;
      return { value: priceNum, currency: price.currency || "RUB" };
    });
    var resolved = FrontcalcMath && typeof FrontcalcMath.resolveLinearMode === "function"
      ? FrontcalcMath.resolveLinearMode(points, targetQuantity)
      : { mode: "", points: selectLinearPoints(points, targetQuantity) };
    if (resolved.points.length > 1 && !areCurrenciesEqual(resolved.points[0].currency, resolved.points[1].currency)) return null;
    return resolved;
  }

  function formatInternalMoney(value, currency) {
    var num = parseNumber(value, Number.NaN);
    return Number.isFinite(num) ? formatMoneyValue(num, currency || "RUB") : "—";
  }

  function renderInternalPanel(viewModel, isOpen, status) {
    var labels = { exact: "Точный расчёт", single: "Одна опорная точка", interpolated: "Интерполяция", extrapolated: "Экстраполяция" };
    var html = '<details class="frontcalc-internal-details"' + (isOpen ? ' open' : '') + '><summary class="frontcalc-internal-summary">Внутренние данные расчёта</summary>';
    html += '<div class="frontcalc-internal-content">';
    if (!viewModel || !Array.isArray(viewModel.sources) || !viewModel.sources.length) {
      var state = status || {};
      var emptyText = state.loading
        ? "Получаем внутренние данные с сервера расчёта…"
        : (state.error || "Данные будут рассчитаны после раскрытия блока.");
      html += '<div class="frontcalc-internal-meta frontcalc-internal-meta--empty">' + escapeHtml(emptyText) + '</div>';
      html += '</div></details>';
      return html;
    }
    html += '<div class="frontcalc-internal-meta">' + escapeHtml(labels[viewModel.mode] || "Расчёт") + ', целевой тираж: ' + escapeHtml(formatQuantityValue(viewModel.targetQuantity)) + ' шт.</div>';
    html += '<div class="frontcalc-internal-cards">';
    viewModel.sources.forEach(function (source) {
      html += '<div class="frontcalc-internal-card">';
      html += '<div><span>Тираж:</span><b>' + escapeHtml(formatQuantityValue(source.quantity)) + ' шт.</b></div>';
      html += '<div><span>Источник:</span><b>' + escapeHtml(source.source === "calc-server" ? "calc-server" : "Bitrix") + '</b></div>';
      html += '<div><span>Прямые затраты:</span><b>' + escapeHtml(formatInternalMoney(source.directPurchasePrice, source.currency)) + '</b></div>';
      html += '<div><span>Себестоимость с накладными расходами:</span><b>' + escapeHtml(formatInternalMoney(source.purchasePrice, source.currency)) + '</b></div>';
      html += '</div>';
    });
    html += '</div>';
    var rows = FrontcalcMath && typeof FrontcalcMath.buildParametrValueRows === "function" ? FrontcalcMath.buildParametrValueRows(viewModel.sources) : [];
    if (rows.length) {
      html += '<div class="frontcalc-internal-param-scroll"><table class="frontcalc-internal-param-table"><thead><tr><th>Параметр</th>';
      if (viewModel.sources.length === 1) {
        html += '<th>Значение</th>';
      } else {
        viewModel.sources.forEach(function (source) { html += '<th>' + escapeHtml(formatQuantityValue(source.quantity)) + ' шт.</th>'; });
      }
      html += '</tr></thead><tbody>';
      rows.forEach(function (row) {
        html += '<tr><td>' + escapeHtml(row.key) + '</td>';
        row.values.forEach(function (value) { html += '<td>' + escapeHtml(value) + '</td>'; });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '</div></details>';
    return html;
  }

  function renderInternalPanelAspro(viewModel, isOpen, status) {
    var labels = {exact: "Точный расчёт", single: "Одна опорная точка", interpolated: "Интерполяция", extrapolated: "Экстраполяция"};
    var state = status || {};
    var collapseClass = isOpen ? "panel-collapse collapse in" : "panel-collapse collapse";
    var headClass = isOpen ? "" : " collapsed";
    var html = '<div class="row"><div class="col-md-12"><div class="accordion-type-2 frontcalc-internal-accordion" id="frontcalc-internal-accordion">';
    html += '<div class="item-accordion-wrapper shadow-hovered shadow-no-border-hovered">';
    html += '<a class="accordion-head width-100 stroke-theme-hover accordion-close' + headClass + '" data-toggle="collapse" data-parent="#frontcalc-internal-accordion" href="#frontcalc-internal-accordion-panel" aria-expanded="' + (isOpen ? 'true' : 'false') + '" aria-controls="frontcalc-internal-accordion-panel">';
    html += '<div class="accordion-head-inner accordion-head-inner--no-margin line-block line-block--justify-between line-block--gap line-block--gap-24 line-block--flex-wrap"><span class="switcher-title color_222 font_18">Внутренние данные расчёта</span></div>';
    html += '<span class="svg inline svg-inline-right-arrow" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2V18" stroke="#B8B8B9" stroke-width="2" stroke-linecap="round"/><path d="M18 10L2 10" stroke="#B8B8B9" stroke-width="2" stroke-linecap="round"/></svg></span></a>';
    html += '<div id="frontcalc-internal-accordion-panel" class="' + collapseClass + '"><div class="accordion-body">';
    if (state.loading) {
      html += '<div class="frontcalc-internal-meta frontcalc-internal-meta--empty">Идёт получение данных...</div>';
    } else if (!viewModel || !Array.isArray(viewModel.sources) || !viewModel.sources.length) {
      html += '<div class="frontcalc-internal-meta frontcalc-internal-meta--empty">' + escapeHtml(state.error || "Внутренние данные отсутствуют.") + '</div>';
    } else {
      html += '<div class="frontcalc-internal-meta">' + escapeHtml(labels[viewModel.mode] || "Расчёт") + ', целевой тираж: ' + escapeHtml(formatQuantityValue(viewModel.targetQuantity)) + ' шт.</div>';
      html += '<div class="tables-responsive"><table class="colored_table"><thead><tr><td>Параметр</td>';
      viewModel.sources.forEach(function (source) { html += '<td>' + escapeHtml(formatQuantityValue(source.quantity)) + ' шт.</td>'; });
      html += '</tr></thead><tbody>';
      var rows = [
        {title: "Источник", render: function (source) { return escapeHtml(source.source === "calc-server" ? "Сервер" : "Внутренний"); }},
        {title: "Прямые затраты", render: function (source) {
          return source.directPurchasePrice == null
            ? '<button type="button" class="btn btn-default btn-xs frontcalc-internal-action" data-action="clarify" data-quantity="' + escapeHtml(String(source.quantity)) + '">Уточнить</button>'
            : escapeHtml(formatInternalMoney(source.directPurchasePrice, source.currency));
        }},
        {title: "Себестоимость с накладными расходами", render: function (source) { return escapeHtml(formatInternalMoney(source.purchasePrice, source.currency)); }}
      ];
      rows.forEach(function (row) {
        html += '<tr><td>' + row.title + '</td>';
        viewModel.sources.forEach(function (source) { html += '<td>' + row.render(source) + '</td>'; });
        html += '</tr>';
      });
      var parameterRows = FrontcalcMath && typeof FrontcalcMath.buildParametrValueRows === "function" ? FrontcalcMath.buildParametrValueRows(viewModel.sources) : [];
      parameterRows.forEach(function (row) {
        html += '<tr><td>' + escapeHtml(row.key) + '</td>';
        row.values.forEach(function (value) { html += '<td>' + escapeHtml(value) + '</td>'; });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      if (viewModel.mode === "interpolated") {
        html += '<button type="button" class="btn btn-default frontcalc-internal-action" data-action="exact" data-quantity="' + escapeHtml(String(viewModel.targetQuantity)) + '">Выполнить точный расчет</button>';
      }
    }
    html += '</div></div></div></div></div></div>';
    return html;
  }

  function getOfferMetrics(offer) {
    return (offer && offer.metrics && typeof offer.metrics === "object") ? offer.metrics : {};
  }

  function roundMetricValue(value, precision) {
    var num = parseNumber(value, Number.NaN);
    if (!Number.isFinite(num)) return null;
    var multiplier = Math.pow(10, precision);
    return Math.round(Math.max(0, num) * multiplier) / multiplier;
  }

  function getMetricForQuantity(offers, selectedByProperty, customByProperty, volumeCode, targetQuantity, metricCode, precision) {
    var points = collectLinearPoints(offers, selectedByProperty, customByProperty, volumeCode, function (candidate) {
      var metrics = getOfferMetrics(candidate);
      var value = parseNumber(metrics[metricCode], Number.NaN);
      return Number.isFinite(value) ? { value: value } : null;
    });
    var selectedPoints = selectLinearPoints(points, targetQuantity);
    if (!selectedPoints.length) return null;
    var result = selectedPoints.length === 1
      ? parseNumber(selectedPoints[0].value, Number.NaN)
      : interpolateLinear(selectedPoints[0], selectedPoints[1], targetQuantity);
    return roundMetricValue(result, precision);
  }

  function getMetricsForQuantity(offers, selectedByProperty, customByProperty, volumeCode, targetQuantity) {
    if (hasCustomSelection(customByProperty, volumeCode)) {
      return { weightKg: null, volumeM3: null };
    }
    return {
      weightKg: getMetricForQuantity(offers, selectedByProperty, customByProperty || {}, volumeCode, targetQuantity, "weightKg", 2),
      volumeM3: getMetricForQuantity(offers, selectedByProperty, customByProperty || {}, volumeCode, targetQuantity, "volumeM3", 5)
    };
  }

  function formatMetricNumber(value, maxDecimals) {
    var num = parseNumber(value, Number.NaN);
    if (!Number.isFinite(num)) return "—";
    return num.toFixed(maxDecimals).replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1").replace(".", ",");
  }

  function formatWeightMetric(value) {
    return value === null || typeof value === "undefined" ? "—" : formatMetricNumber(value, 2) + " кг";
  }

  function formatVolumeMetric(value) {
    return value === null || typeof value === "undefined" ? "—" : formatMetricNumber(value, 5) + " м³";
  }



  function snapPriceTableScroll($body) {
    if (!$body || !$body.length) return;
    var bodyNode = $body.get(0);
    if (!bodyNode) return;
    var bodyRect = bodyNode.getBoundingClientRect();
    var rows = $body.find(".frontcalc-table-row").toArray();
    if (!rows.length) return;

    var firstVisibleRow = null;
    for (var i = 0; i < rows.length; i++) {
      var rowRect = rows[i].getBoundingClientRect();
      if (rowRect.bottom > bodyRect.top && rowRect.top < bodyRect.bottom) {
        firstVisibleRow = rows[i];
        break;
      }
    }
    if (!firstVisibleRow) return;

    var firstRect = firstVisibleRow.getBoundingClientRect();
    var tolerance = 1;
    var delta = 0;
    if (firstRect.top < bodyRect.top - tolerance) {
      delta = firstRect.bottom - bodyRect.top;
    } else if (firstRect.top > bodyRect.top + tolerance) {
      delta = firstRect.top - bodyRect.top;
    }

    if (Math.abs(delta) >= 1) {
      var maxScroll = Math.max(0, $body.prop("scrollHeight") - $body.innerHeight());
      $body.scrollTop(clamp($body.scrollTop() + delta, 0, maxScroll));
    }
  }

  function bindPriceTableScrollSnap($body) {
    if (!$body || !$body.length) return;
    var timer = null;
    $body.off("scroll.frontcalcSnap").on("scroll.frontcalcSnap", function () {
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        snapPriceTableScroll($body);
      }, 120);
    });
  }

  function renderPriceTable($block, offers, presetsByCode, selectedByProperty, customByProperty, fieldByCode, volumeCode, customVolumeValue, priceGroups, selectedCatalogGroupId, buyCatalogGroupId, config, deadlineType, matchedOffer, internalViewModel, internalPanelOpen, internalStatus, $internalBlock, onInternalPanelToggle) {
    var volumePresets = (presetsByCode[volumeCode] || []).slice();
    if (!volumePresets.length) {
      $block.html('<div class="frontcalc-price-empty">Нет значений тиража для таблицы.</div>');
      if ($internalBlock && $internalBlock.length) $internalBlock.html(renderInternalPanelAspro(null, internalPanelOpen, internalStatus));
      return;
    }

    var numericPresets = volumePresets
      .map(function (preset) {
        var quantity = resolvePresetQuantity(preset);
        return {
          xml_id: String(preset.xml_id || ""),
          value: String(preset.value || preset.xml_id || ""),
          num: quantity,
          isCustom: false,
        };
      })
      .filter(function (row) {
        return Number.isFinite(row.num);
      })
      .sort(function (a, b) {
        return a.num - b.num;
      });
    var selectedXml = String(selectedByProperty[volumeCode] || (numericPresets[0] && numericPresets[0].xml_id) || "");
    var selectedPreset = findPresetByInputValue(volumePresets, selectedXml);
    var selectedNum = resolvePresetQuantity(selectedPreset);
    if (!Number.isFinite(selectedNum)) selectedNum = parseNumber(selectedXml, Number.NaN);
    if (Number.isFinite(customVolumeValue)) selectedNum = customVolumeValue;
    var fieldConfig = config || fieldByCode[volumeCode] || {};
    var minBound = fieldConfig.area_bounds ? parseNumber(fieldConfig.area_bounds.min, Number.NaN) : resolveConfiguredBound(fieldConfig, "min");
    var maxBound = fieldConfig.area_bounds ? parseNumber(fieldConfig.area_bounds.max, Number.NaN) : resolveConfiguredBound(fieldConfig, "max");
    var stepBound = fieldConfig.area_bounds ? parseNumber(fieldConfig.area_bounds.step, Number.NaN) : resolveConfiguredStep(fieldConfig);
    var fallbackMin = numericPresets.length ? numericPresets[0].num : 1;
    var fallbackMax = numericPresets.length ? numericPresets[numericPresets.length - 1].num : Number.POSITIVE_INFINITY;
    var effectiveMin = Number.isFinite(minBound) ? minBound : fallbackMin;
    var effectiveMax = Number.isFinite(maxBound) ? maxBound : fallbackMax;
    var normalizedSelectedNum = normalizeToStep(clamp(selectedNum, effectiveMin, effectiveMax), effectiveMin, stepBound);
    if (Number.isFinite(normalizedSelectedNum)) {
      selectedNum = clamp(normalizedSelectedNum, effectiveMin, effectiveMax);
    }
    var volumeGrid = config && config.volume_grid ? config.volume_grid : {};
    var baseGridValues = Array.isArray(volumeGrid.values) ? volumeGrid.values : [1,2,3,4,5,10,15,20,30,40,50,100,150,200,300,400,500,1000,1500,2000,3000,4000,5000,10000,15000,20000,30000,40000,50000,100000,150000,200000];
    var tailGridStep = parseNumber(volumeGrid.tail_step, 50000);
    var tableNumbers = pickFiveCentered(buildAllowedVolumeNumbers(baseGridValues, effectiveMin, effectiveMax, selectedNum, stepBound, tailGridStep), selectedNum);
    var merged = tableNumbers.map(function (num) {
      var preset = numericPresets.filter(function (row) { return row.num === num; })[0] || null;
      return preset || { xml_id: String(num), value: String(num), num: num, isCustom: true };
    });
    selectedXml = String(selectedByProperty[volumeCode] || (merged[0] && merged[0].xml_id) || "");
    var activeGroupId = parseNumber(selectedCatalogGroupId, Number.NaN);
    var buyGroupId = parseNumber(buyCatalogGroupId, Number.NaN);
    var isBuyPriceType = Number.isFinite(activeGroupId) && Number.isFinite(buyGroupId) && activeGroupId === buyGroupId;

    var html = "";
    var deadlineTexts = (config && config.deadline_texts) || {};
    var activeDeadline = deadlineType || "strict";
    var urgentText = String(deadlineTexts.urgent || "Компенсируем 100% стоимости при нарушении договоренностей.");
    var strictText = String(deadlineTexts.strict || "Гарантируем выполнение в согласованный срок.");
    var flexibleText = String(deadlineTexts.flexible || "Сроки могут быть скорректированы, но не более чем на 10 рабочих дней.");
    var deadlineHtml =
      '<div class="tabs frontcalc-deadline-tabs" style="margin:36px 0;">' +
        '<ul class="nav nav-tabs">' +
          '<li class="bordered rounded-4' + (activeDeadline === "urgent" ? ' active' : '') + '"><a href="#frontcalcDeadlineUrgent" data-toggle="tab" data-deadline="urgent">Срочность</a></li>' +
          '<li class="bordered rounded-4' + (activeDeadline === "strict" ? ' active' : '') + '"><a href="#frontcalcDeadlineStrict" data-toggle="tab" data-deadline="strict">Строгий срок</a></li>' +
          '<li class="bordered rounded-4' + (activeDeadline === "flexible" ? ' active' : '') + '"><a href="#frontcalcDeadlineFlexible" data-toggle="tab" data-deadline="flexible">Гибкий срок</a></li>' +
        '</ul>' +
        '<div class="tab-content frontcalc-deadline-tab-content" style="background:none;padding:12px 4px 0;">' +
          '<div class="tab-pane' + (activeDeadline === "urgent" ? ' active' : '') + '" id="frontcalcDeadlineUrgent">' + escapeHtml(urgentText) + '</div>' +
          '<div class="tab-pane' + (activeDeadline === "strict" ? ' active' : '') + '" id="frontcalcDeadlineStrict">' + escapeHtml(strictText) + '</div>' +
          '<div class="tab-pane' + (activeDeadline === "flexible" ? ' active' : '') + '" id="frontcalcDeadlineFlexible">' + escapeHtml(flexibleText) + '</div>' +
        '</div>' +
      '</div>';
    html += '<div class="frontcalc-table-head"><div>Тираж</div><div>Стоимость</div></div>';
    html += '<div class="frontcalc-table-body">';

    var volumeUnitLabel = resolveVolumeUnitLabel(fieldByCode, volumeCode);

    merged.forEach(function (preset, index) {
      var xml = String(preset.xml_id || "");
      var qty = Math.max(1, parseNumber(preset.num, 1));
      var draftSel = Object.assign({}, selectedByProperty);
      var draftCustom = Object.assign({}, customByProperty || {});
      draftSel[volumeCode] = xml;
      draftCustom[volumeCode] = false;
      var offer = pickMatchedOffer(offers, draftSel, draftCustom);
      var strictPrice = applyDeadlineAdjustment(getOfferPriceForCatalogGroup(offer, selectedCatalogGroupId, 1), config, deadlineType || "strict", qty, activeGroupId);
      if (!strictPrice) {
        strictPrice = getInterpolatedPriceForVolume(offers, selectedByProperty, customByProperty, volumeCode, qty, selectedCatalogGroupId, config, deadlineType || "strict");
      }
      var strictNum = parseNumber(strictPrice && strictPrice.price, Number.NaN);
      var metrics = getMetricsForQuantity(offers, draftSel, draftCustom, volumeCode, qty);

      html +=
        '<div class="frontcalc-table-row" data-row-index="' +
        index +
        '" data-xml-id="' +
        escapeHtml(xml) +
        '" data-quantity="' +
        escapeHtml(String(qty)) +
        '">';
      html +=
        '<button type="button" class="frontcalc-cell frontcalc-cell--volume"><span class="frontcalc-volume-quantity" title="' + escapeHtml(formatQuantityValue(preset.value || xml)) + '">' +
        escapeHtml(formatQuantityValue(preset.value || xml)) +
        '</span><span class="frontcalc-volume-metrics"><span class="frontcalc-volume-metric">' +
        escapeHtml(formatWeightMetric(metrics.weightKg)) +
        '</span><span class="frontcalc-volume-metric">' +
        escapeHtml(formatVolumeMetric(metrics.volumeM3)) +
        '</span></span></button>';
      html +=
        '<button type="button" class="frontcalc-cell' + (xml === selectedXml ? ' is-picked' : '') + '" data-col="strict" data-price="' + escapeHtml(String(Number.isFinite(strictNum) ? strictNum : "")) + '" data-currency="' + escapeHtml(String((strictPrice && strictPrice.currency) || "")) + '"><span class="frontcalc-cell-main">' +
        escapeHtml(formatMoneyRow(strictPrice)) +
        '</span><span class="frontcalc-cell-sub">' +
        escapeHtml(Number.isFinite(strictNum) ? (strictNum / qty).toFixed(2) + " ₽/" + volumeUnitLabel : "—") +
        "</span></button>";
      html += "</div>";
    });
    html += "</div>";
    html += deadlineHtml;
    html += '<div class="frontcalc-volume-input">';
    html +=
      '<div class="frontcalc-volume-stepper" data-frontcalc-step="' + escapeHtml(String(stepBound)) + '" data-frontcalc-min="' + escapeHtml(String(minBound)) + '" data-frontcalc-max="' + escapeHtml(String(maxBound)) + '"><button type="button" class="frontcalc-volume-btn" data-step="-1">−</button><input type="text" class="frontcalc-table-input" value="' +
      escapeHtml(formatQuantityValue(Number.isFinite(selectedNum) ? selectedNum : selectedXml)) +
      '" inputmode="numeric"><button type="button" class="frontcalc-volume-btn" data-step="1">+</button></div>';
    html +=
      '<span class="frontcalc-cart-wrap"><button type="button" class="btn btn-default animate-load btn-elg btn-wide frontcalc-cart-btn' +
      (isBuyPriceType ? '' : ' is-info-only') +
      '" data-buy-enabled="' + (isBuyPriceType ? '1' : '0') + '">' +
      '<span>В корзину</span></button></span>';
    html += "</div>";
    if (Array.isArray(priceGroups) && priceGroups.length) {
      html += '<div class="frontcalc-price-groups">';
      priceGroups.forEach(function (group) {
        var id = parseNumber(group && group.id, Number.NaN);
        if (!Number.isFinite(id)) return;
        html += '<button type="button" class="frontcalc-price-group' +
          (id === selectedCatalogGroupId ? ' is-active' : '') +
          '" data-catalog-group-id="' + escapeHtml(String(id)) + '">' +
          escapeHtml(group.name || ("PRICE_" + id)) +
          '</button>';
      });
      html += '</div>';
    }
    $block.html(html);
    if ($internalBlock && $internalBlock.length) {
      $internalBlock.html(renderInternalPanelAspro(internalViewModel, internalPanelOpen, internalStatus));
    }
    var $internalDetails = $internalBlock && $internalBlock.length
      ? $internalBlock.find("#frontcalc-internal-accordion-panel")
      : $();
    if ($internalDetails.length && typeof onInternalPanelToggle === "function") {
      $internalDetails.on("shown.bs.collapse.frontcalc", function () { onInternalPanelToggle(true); });
      $internalDetails.on("hidden.bs.collapse.frontcalc", function () { onInternalPanelToggle(false); });
    }

    var $body = $block.find(".frontcalc-table-body");
    var $selectedRow = $body.find('.frontcalc-table-row[data-xml-id="' + selectedXml + '"]');
    if ($selectedRow.length) {
      var rowH = $selectedRow.outerHeight(true) || 1;
      var selectedIndex = parseNumber($selectedRow.attr("data-row-index"), 0);
      var targetIndex = selectedIndex - 2;
      var targetScroll = Math.max(0, targetIndex) * rowH;
      var maxScroll = Math.max(0, $body.prop("scrollHeight") - $body.innerHeight());
      $body.scrollTop(clamp(targetScroll, 0, maxScroll));
    }
    bindPriceTableScrollSnap($body);
    snapPriceTableScroll($body);
  }

  function renderPriceBlock($block, matchedOffer) {
    if (!matchedOffer) {
      $block.html('<div class="frontcalc-price-empty">Для выбранных значений не найдено опорное ТП.</div>');
      return;
    }

    var primaryBuyPrice = (matchedOffer.catalog && matchedOffer.catalog.primary_buy_price) || null;
    var html = "<div class=\"frontcalc-price-main\">";
    html += primaryBuyPrice ? "<div class=\"frontcalc-price-value\">" + escapeHtml(primaryBuyPrice.formatted || (primaryBuyPrice.price + " " + primaryBuyPrice.currency)) + "</div>" : "<div class=\"frontcalc-price-value\">Цена не найдена</div>";
    html += "</div>";
    $block.html(html);
  }

  function getInputItemsForField(field) {
    if (Array.isArray(field && field.group_inputs)) return field.group_inputs;
    if (Array.isArray(field && field.inputs)) return field.inputs;
    return field ? [field] : [];
  }

  function buildOfferTitleForUi(offer, productName) {
    return String((offer && offer.name) || productName || "").trim();
  }

  function serializeSelectedProperties(selectedByProperty, presetsByCode) {
    var result = {};
    Object.keys(selectedByProperty || {}).forEach(function (code) {
      if (code.indexOf("CALC_PROP_") !== 0) return;
      var xmlId = String(selectedByProperty[code] || "");
      var preset = findPresetByInputValue((presetsByCode && presetsByCode[code]) || [], xmlId);
      result[code] = {
        value: String((preset && preset.value) || xmlId),
        xml_id: String((preset && preset.xml_id) || xmlId)
      };
    });
    return result;
  }

  function getDeadlineLabel(type) {
    if (type === "urgent") return "Срочность";
    if (type === "flexible") return "Гибкий срок";
    return "Строгий срок";
  }

  function renderCalculator($content, payload, initialState) {
    var data = payload && payload.data ? payload.data : {};
    var state = initialState || {};
    var config = data.config || {};
    var access = data.access || {};
    var permissions = access.permissions || {};
    var canViewInternal = permissions.can_view_internal_calculation_data === true;
    var internalPanelOpen = false;
    var internalRequestLoading = false;
    var internalRequestError = "";
    var calculationSessionId = String(data.calculation_session_id || "");
    var backgroundCalculationLoading = data.calculation_pending === true;
    var limitToAvailableOptions = backgroundCalculationLoading;
    var backgroundCalculationTimer = null;
    var offers = Array.isArray(data.offers) ? data.offers : [];
    var priceGroups = collectAvailablePriceGroups(data.price_groups_view, offers);
    var selectedCatalogGroupId = priceGroups.length ? parseNumber(priceGroups[0].id, Number.NaN) : Number.NaN;
    var buyCatalogGroupIds = data.price_access && Array.isArray(data.price_access.buy) ? data.price_access.buy.map(function (id) { return parseNumber(id, Number.NaN); }).filter(Number.isFinite) : [];
    var buyCatalogGroupId = buyCatalogGroupIds.length ? buyCatalogGroupIds[0] : Number.NaN;
    var propertyMeta = Array.isArray(data.property_meta) ? data.property_meta : [];
    var priceGroupsView = Array.isArray(data.price_groups_view) ? data.price_groups_view : [];
    var propertyMetaByCode = {};
    propertyMeta.forEach(function (meta) {
      var code = String((meta && meta.code) || "").trim();
      if (code) propertyMetaByCode[code] = meta;
    });
    if (!offers.length) {
      renderError($content, "Нет доступных торговых предложений для калькулятора.");
      return;
    }

    var fields = Array.isArray(config.fields) ? config.fields : [];
    var fieldByCode = makeFieldIndexMap(fields);
    var metaOrderByCode = makeCodeOrderMapFromMeta(propertyMeta);
    var fieldOrderByCode = makeCodeOrderMapFromFields(fields);
    var presetsByCode = buildPresetsByProperty(offers);
    var fieldPresetsByCode = buildPresetsFromConfigFields(fields);
    mergePresets(presetsByCode, fieldPresetsByCode);
    Object.keys(fieldPresetsByCode).forEach(function (code) {
      // Если в админке явно выбраны чипсы для свойства, в попапе показываем
      // строго этот список, а не полный набор значений из существующих ТП.
      presetsByCode[code] = fieldPresetsByCode[code].slice();
    });
    Object.keys(propertyMetaByCode).forEach(function (code) {
      if (Array.isArray(propertyMetaByCode[code].presets) && propertyMetaByCode[code].presets.length) {
        mergePresets(presetsByCode, (function () {
          var map = {};
          map[code] = propertyMetaByCode[code].presets.filter(function (row) {
            var xmlId = String((row && row.xml_id) || "").trim();
            return !!xmlId;
          });
          return map;
        })());
      }
    });
    var participatingByCode = buildParticipatingPropertyMap(offers);
    var allCodes = gatherAllPropertyCodes(propertyMeta, offers, fields)
      .filter(function (code) {
        return !!participatingByCode[code] || (Array.isArray(fieldPresetsByCode[code]) && fieldPresetsByCode[code].length > 0);
      })
      .sort(function (a, b) {
        var sortA = parseNumber(
          propertyMetaByCode[a] && (propertyMetaByCode[a].sort || propertyMetaByCode[a].SORT),
          parseNumber(fieldByCode[a] && (fieldByCode[a].sort || fieldByCode[a].SORT), 500)
        );
        var sortB = parseNumber(
          propertyMetaByCode[b] && (propertyMetaByCode[b].sort || propertyMetaByCode[b].SORT),
          parseNumber(fieldByCode[b] && (fieldByCode[b].sort || fieldByCode[b].SORT), 500)
        );
        if (sortA === sortB) {
          var metaOrderA = parseNumber(metaOrderByCode[a], Number.POSITIVE_INFINITY);
          var metaOrderB = parseNumber(metaOrderByCode[b], Number.POSITIVE_INFINITY);
          if (metaOrderA !== metaOrderB) return metaOrderA - metaOrderB;

          var fieldOrderA = parseNumber(fieldOrderByCode[a], Number.POSITIVE_INFINITY);
          var fieldOrderB = parseNumber(fieldOrderByCode[b], Number.POSITIVE_INFINITY);
          if (fieldOrderA !== fieldOrderB) return fieldOrderA - fieldOrderB;

          return a.localeCompare(b);
        }
        return sortA - sortB;
      });

    var selectedByProperty = {};
    var customByProperty = {};
    var pendingPropertyCode = "";
    var draftValuesByProperty = {};
    var committedInputValuesByProperty = {};
    var inputControlsByProperty = {};
    var propertyActionState = {};
    var customRequestToken = 0;
    var customRequestLoading = false;

    var controlsByCode = {};
    var volumeCode = "CALC_PROP_VOLUME";
    var customVolumeValue = Number.NaN;
    var deadlineType = "strict";
    var explicitVolumeStep = resolveConfiguredStep(fieldByCode[volumeCode]);
    var derivedVolumeStep = deriveStepFromPresets(presetsByCode[volumeCode]);
    var volumeStep = Number.isFinite(explicitVolumeStep) && explicitVolumeStep > 0
      ? explicitVolumeStep
      : (Number.isFinite(derivedVolumeStep) && derivedVolumeStep > 0 ? derivedVolumeStep : 1);
    var volumeMin = resolveConfiguredBound(fieldByCode[volumeCode], "min");
    var volumeMax = resolveConfiguredBound(fieldByCode[volumeCode], "max");

    function getAreaDependencyCode() {
      for (var code in fieldByCode) {
        if (!Object.prototype.hasOwnProperty.call(fieldByCode, code) || code === volumeCode) continue;
        if (isTruthyFlag(fieldByCode[code] && fieldByCode[code].use_for_area_dependency)) {
          return code;
        }
      }
      return "";
    }

    function getAreaMm2ForSelection(selection) {
      var areaCode = getAreaDependencyCode();
      if (!areaCode) return Number.NaN;
      var selected = selection && Object.prototype.hasOwnProperty.call(selection, areaCode)
        ? selection[areaCode]
        : selectedByProperty[areaCode];
      var rawXmlId = selected && typeof selected === "object"
        ? String(selected.xmlId || selected.xml_id || "")
        : String(selected || "");
      var rawValue = selected && typeof selected === "object"
        ? String(selected.value || "")
        : String(selected || "");
      var preset = findPresetByInputValue(presetsByCode[areaCode] || [], rawXmlId || rawValue);
      // A configured value is identified by XML_ID. Its visible label is
      // presentation-only and must never affect calculator constraints. A
      // custom value has no XML_ID, so its confirmed input is used instead.
      return calculateAreaMm2FromValue(preset ? preset.xml_id : (rawXmlId || rawValue));
    }

    function getAreaVolumeRangeForSelection(selection) {
      var area = getAreaMm2ForSelection(selection);
      var rows = Array.isArray(fieldByCode[volumeCode] && fieldByCode[volumeCode].area_ranges) ? fieldByCode[volumeCode].area_ranges : [];
      if (!Number.isFinite(area) || !rows.length) return null;
      for (var i = 0; i < rows.length; i++) {
        var row = rows[i] || {};
        var rawFrom = row.area_from_mm2;
        var rawTo = row.area_to_mm2;
        var from = rawFrom === null || rawFrom === undefined || String(rawFrom).trim() === "" ? Number.NaN : parseNumber(rawFrom, Number.NaN);
        var to = rawTo === null || rawTo === undefined || String(rawTo).trim() === "" ? Number.NaN : parseNumber(rawTo, Number.NaN);
        if (!Number.isFinite(from) && !Number.isFinite(to)) continue;
        var fromOk = !Number.isFinite(from) || area >= from;
        var toOk = !Number.isFinite(to) || area <= to;
        if (fromOk && toOk) return row;
      }
      return null;
    }

    function getCurrentAreaMm2() {
      return getAreaMm2ForSelection(selectedByProperty);
    }

    function getCurrentAreaVolumeRange() {
      return getAreaVolumeRangeForSelection(selectedByProperty);
    }

    function getCurrentVolumeFieldConfig() {
      var base = Object.assign({}, fieldByCode[volumeCode] || {});
      var areaRange = getCurrentAreaVolumeRange();
      if (areaRange) {
        base.area_bounds = areaRange;
      }
      return base;
    }

    function getCurrentVolumeStepInfo() {
      var areaRange = getCurrentAreaVolumeRange();
      var areaStep = areaRange ? parseNumber(areaRange.step, Number.NaN) : Number.NaN;
      var configuredStep = Number.isFinite(areaStep) && areaStep > 0
        ? areaStep
        : (Number.isFinite(explicitVolumeStep) && explicitVolumeStep > 0 ? explicitVolumeStep : volumeStep);
      return { step: configuredStep };
    }

    function getCurrentVolumeBounds(defaultMinValue, defaultMaxValue, stepInfo) {
      var info = stepInfo || getCurrentVolumeStepInfo();
      var areaRange = getCurrentAreaVolumeRange();
      var areaMin = areaRange ? parseNumber(areaRange.min, Number.NaN) : Number.NaN;
      var areaMax = areaRange ? parseNumber(areaRange.max, Number.NaN) : Number.NaN;
      var minValue = Number.isFinite(areaMin) ? areaMin : (Number.isFinite(volumeMin) ? volumeMin : defaultMinValue);
      var maxValue = Number.isFinite(areaMax) ? areaMax : (Number.isFinite(volumeMax) ? volumeMax : defaultMaxValue);

      return { min: minValue, max: maxValue };
    }

    function getFallbackVolumeBounds() {
      var presetNums = getCurrentVolumePresetNumbers();
      return {
        min: presetNums.length ? presetNums[0] : 1,
        max: presetNums.length ? presetNums[presetNums.length - 1] : Number.POSITIVE_INFINITY
      };
    }

    function normalizeVolumeByStep(value, minValue, maxValue, stepInfo) {
      var info = stepInfo || getCurrentVolumeStepInfo();
      return normalizeToStep(clamp(value, minValue, maxValue), minValue, info.step);
    }

    function moveVolumeByStep(current, direction, minValue, maxValue, stepInfo) {
      var info = stepInfo || getCurrentVolumeStepInfo();
      return normalizeToStep(clamp(current + direction * info.step, minValue, maxValue), minValue, info.step);
    }

    function normalizeVolumeForSelection(value, selection) {
      var areaRange = getAreaVolumeRangeForSelection(selection);
      var areaMin = areaRange ? parseNumber(areaRange.min, Number.NaN) : Number.NaN;
      var areaMax = areaRange ? parseNumber(areaRange.max, Number.NaN) : Number.NaN;
      var areaStep = areaRange ? parseNumber(areaRange.step, Number.NaN) : Number.NaN;
      var fallback = getFallbackVolumeBounds();
      var minValue = Number.isFinite(areaMin) ? areaMin : (Number.isFinite(volumeMin) ? volumeMin : fallback.min);
      var maxValue = Number.isFinite(areaMax) ? areaMax : (Number.isFinite(volumeMax) ? volumeMax : fallback.max);
      var step = Number.isFinite(areaStep) && areaStep > 0 ? areaStep : getCurrentVolumeStepInfo().step;
      var normalized = normalizeToStep(clamp(value, minValue, maxValue), minValue, step);
      if (!Number.isFinite(normalized)) normalized = minValue;
      if (Number.isFinite(maxValue) && normalized > maxValue) {
        normalized = normalizeToStep(maxValue, minValue, step);
        if (normalized > maxValue) normalized -= step;
      }
      return clamp(normalized, minValue, maxValue);
    }

    function getCurrentVolumePresetNumbers() {
      return (presetsByCode[volumeCode] || [])
        .map(function (preset) { return resolvePresetQuantity(preset); })
        .filter(Number.isFinite)
        .sort(function (a, b) { return a - b; });
    }

    function getCurrentVolumeQuantity() {
      return resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue);
    }

    function ensureVolumeWithinCurrentConstraints() {
      var fallback = getFallbackVolumeBounds();
      var stepInfo = getCurrentVolumeStepInfo();
      var bounds = getCurrentVolumeBounds(fallback.min, fallback.max, stepInfo);
      var current = getCurrentVolumeQuantity();
      if (!Number.isFinite(current)) {
        current = bounds.min;
      }
      var normalized = normalizeVolumeByStep(current, bounds.min, bounds.max, stepInfo);
      if (Number.isFinite(bounds.min) && normalized < bounds.min) {
        normalized = bounds.min;
      }
      if (Number.isFinite(bounds.max) && normalized > bounds.max) {
        normalized = normalizeToStep(bounds.max, bounds.min, stepInfo.step);
        if (normalized > bounds.max) {
          normalized -= stepInfo.step;
        }
      }
      if (!Number.isFinite(normalized)) {
        return;
      }
      normalized = clamp(normalized, bounds.min, bounds.max);
      if (String(normalized) === String(current || "")) {
        return;
      }
      var nextPreset = findPresetByInputValue(presetsByCode[volumeCode] || [], String(normalized));
      customVolumeValue = nextPreset ? Number.NaN : normalized;
      selectedByProperty[volumeCode] = nextPreset ? String(nextPreset.xml_id || normalized) : String(normalized);
      customByProperty[volumeCode] = !nextPreset;
    }

    function pickDefaultOfferBySort(offersList, codes) {
      if (!Array.isArray(offersList) || !offersList.length) return null;
      var best = null;
      offersList.forEach(function (offer) {
        var rank = codes.map(function (code) {
          var prop = offer && offer.properties ? offer.properties[code] : null;
          return parseNumber(prop && prop.sort, 500);
        });
        if (!best) {
          best = { offer: offer, rank: rank };
          return;
        }
        for (var i = 0; i < rank.length; i++) {
          if (rank[i] < best.rank[i]) {
            best = { offer: offer, rank: rank };
            return;
          }
          if (rank[i] > best.rank[i]) return;
        }
      });
      return best ? best.offer : null;
    }

    var stateOfferId = String(state.offerId || "").trim();
    var requestedOfferId = stateOfferId !== "" ? parseNumber(stateOfferId, 0) : parseNumber(data.requested_offer_id, 0);
    var defaultOffer = null;
    if (requestedOfferId > 0) {
      defaultOffer = offers.find(function (offer) {
        return parseNumber(offer && offer.id, 0) === requestedOfferId;
      }) || null;
    }
    if (!defaultOffer) {
      defaultOffer = pickDefaultOfferBySort(offers, allCodes);
    }
    if (defaultOffer && defaultOffer.properties && defaultOffer.properties[volumeCode]) {
      selectedByProperty[volumeCode] = String(defaultOffer.properties[volumeCode].xml_id || "").trim();
      customByProperty[volumeCode] = false;
    }
    var anchorOffer = defaultOffer;
    if (defaultOffer && defaultOffer.catalog && defaultOffer.catalog.primary_buy_price) {
      var primaryGroupId = parseNumber(defaultOffer.catalog.primary_buy_price.catalog_group_id, Number.NaN);
      if (Number.isFinite(primaryGroupId) && priceGroups.some(function (group) { return parseNumber(group && group.id, Number.NaN) === primaryGroupId; })) {
        selectedCatalogGroupId = primaryGroupId;
        if (buyCatalogGroupIds.some(function (id) { return id === primaryGroupId; })) {
          buyCatalogGroupId = primaryGroupId;
        }
      }
    }
    var selectorCodes = allCodes.filter(function (code) {
      return code !== volumeCode;
    });

    selectorCodes.forEach(function (code) {
      if (code === volumeCode) {
        return;
      }
      var presets = Array.isArray(presetsByCode[code]) ? presetsByCode[code] : [];
      var initFieldConfig = fieldByCode[code] || {};
      var initDisplayMode = String(initFieldConfig.display_mode || initFieldConfig.selector_type || '').toLowerCase();
      if (!participatingByCode[code] && (initDisplayMode === 'chips_only' || initDisplayMode === 'chips')) {
        return;
      }
      var defaultXmlId = "";
      if (defaultOffer && defaultOffer.properties && defaultOffer.properties[code]) {
        defaultXmlId = String(defaultOffer.properties[code].xml_id || "").trim();
      }
      var existsInPresets = presets.some(function (preset) {
        return String((preset && preset.xml_id) || "") === defaultXmlId;
      });
      if (existsInPresets) {
        selectedByProperty[code] = defaultXmlId;
      } else if (presets.length) {
        selectedByProperty[code] = presets[0].xml_id;
      }
      customByProperty[code] = false;
    });

    if (!selectedByProperty[volumeCode] && Array.isArray(presetsByCode[volumeCode]) && presetsByCode[volumeCode].length) {
      selectedByProperty[volumeCode] = String(presetsByCode[volumeCode][0].xml_id || "");
      customByProperty[volumeCode] = false;
    }

    var $layout = $('<div class="frontcalc-layout"></div>');
    var $selectors = $('<div class="frontcalc-selectors"></div>');
    var $title = $('<h2 class="frontcalc-offer-title"><svg class="frontcalc-offer-title__icon" aria-hidden="true" focusable="false" viewBox="0 0 1250 1250"><use href="#frontcalc-icon-calc" xlink:href="#frontcalc-icon-calc"></use></svg><span class="frontcalc-offer-title__text"></span></h2>');
    $selectors.append($title);
    var $price = $('<aside class="frontcalc-price-panel"><div class="frontcalc-price-panel__inner"></div></aside>');
    var $priceInner = $price.find(".frontcalc-price-panel__inner");
    var $internalPanelHost = canViewInternal ? $('<div class="frontcalc-internal-panel-host"></div>') : null;

    function normalizeDraftArray(values) {
      return (Array.isArray(values) ? values : []).map(function (value) {
        return normalizeValueToken(value);
      });
    }

    function draftEqualsCommitted(code) {
      return normalizeDraftArray(draftValuesByProperty[code]).join("|") === normalizeDraftArray(committedInputValuesByProperty[code]).join("|");
    }

    function setInputsForProperty(code, values) {
      var controls = inputControlsByProperty[code] || [];
      controls.forEach(function ($input, idx) {
        $input.val(Array.isArray(values) && idx < values.length ? values[idx] : "");
      });
    }

    function setActionState(code, stateName, message) {
      var controls = controlsByCode[code] || {};
      var $actions = controls.actions;
      if (!$actions || !$actions.length) return;
      $actions.removeClass("is-dirty is-loading is-error");
      if (stateName && stateName !== "clean") $actions.addClass("is-" + stateName);
      $actions.find(".frontcalc-input-error").text(message || "");
      $actions.find(".frontcalc-input-confirm").prop("disabled", stateName === "loading");
      propertyActionState[code] = stateName || "clean";
    }

    function setCustomRequestBusy(isBusy) {
      customRequestLoading = !!isBusy;
      var $items = $selectors.find("input,button")
        .add($price.find(".frontcalc-volume-btn,.frontcalc-table-input,.frontcalc-price-group,.frontcalc-deadline-tabs a,.frontcalc-cell,.frontcalc-cart-btn"));
      $items.each(function () {
        var $item = $(this);
        if (isBusy) {
          if (!$item.data("frontcalc-busy-was")) {
            $item.data("frontcalc-disabled-before-busy", $item.prop("disabled") ? "Y" : "N");
            $item.data("frontcalc-busy-was", "Y");
          }
          $item.prop("disabled", true);
          return;
        }
        if ($item.data("frontcalc-busy-was")) {
          $item.prop("disabled", restoreDisabledAfterBusy($item.data("frontcalc-disabled-before-busy")));
          $item.removeData("frontcalc-disabled-before-busy");
          $item.removeData("frontcalc-busy-was");
        }
      });
    }

    function resetPendingDraft(exceptCode) {
      if (!pendingPropertyCode || pendingPropertyCode === exceptCode) return;
      setInputsForProperty(pendingPropertyCode, committedInputValuesByProperty[pendingPropertyCode]);
      draftValuesByProperty[pendingPropertyCode] = (committedInputValuesByProperty[pendingPropertyCode] || []).slice();
      setActionState(pendingPropertyCode, "clean", "");
      pendingPropertyCode = "";
    }

    function markDraftChanged(code) {
      if (customRequestLoading) return false;
      resetPendingDraft(code);
      pendingPropertyCode = draftEqualsCommitted(code) ? "" : code;
      setActionState(code, pendingPropertyCode ? "dirty" : "clean", "");
      return true;
    }

    function showPendingDraftRequired(code) {
      if (!code) return;
      setActionState(code, "error", "Подтвердите или сбросьте введённое значение");
      var controls = controlsByCode[code] || {};
      var $confirm = controls.actions && controls.actions.find(".frontcalc-input-confirm").first();
      if ($confirm && $confirm.length) $confirm.trigger("focus");
    }

    function buildSelectedValuesWithDraft(code, compositeValue) {
      var delimitersByCode = {};
      Object.keys(controlsByCode || {}).forEach(function (controlCode) {
        if (controlsByCode[controlCode] && controlsByCode[controlCode].delimiter) {
          delimitersByCode[controlCode] = controlsByCode[controlCode].delimiter;
        }
      });
      return buildCustomSelectionPayload(selectedByProperty, presetsByCode, code, compositeValue, volumeCode, selectorCodes, committedInputValuesByProperty, delimitersByCode);
    }

    function commitPresetValue(code, preset, parts) {
      selectedByProperty[code] = preset.xml_id;
      customByProperty[code] = false;
      committedInputValuesByProperty[code] = getPresetInputParts(preset, controlsByCode[code] && controlsByCode[code].delimiter || "x");
      draftValuesByProperty[code] = parts.slice();
      setInputsForProperty(code, committedInputValuesByProperty[code]);
      var controls = controlsByCode[code] || {};
      if (controls.chips) {
        controls.chips.find(".is-active").removeClass("is-active");
        controls.chips.find('.frontcalc-chip[data-xml-id="' + preset.xml_id + '"]').addClass("is-active");
      }
      pendingPropertyCode = "";
      setActionState(code, "clean", "");
      ensureVolumeWithinCurrentConstraints();
      updatePrice();
    }

    function confirmDraft(code, delimiter, presets) {
      if (customRequestLoading && pendingPropertyCode === code) return;
      var values = (draftValuesByProperty[code] || []).slice();
      var controls = controlsByCode[code] || {};
      var canonical = canonicalizeInputComponents(values, controls.inputFields || []);
      if (!canonical.valid) {
        setActionState(code, "error", canonical.message || "Заполните корректное значение");
        return;
      }
      values = canonical.values;
      setInputsForProperty(code, values);
      draftValuesByProperty[code] = values.slice();
      var compositeValue = values.map(function (value) { return normalizeValueToken(value); }).join(delimiter);
      var matchedPreset = findPresetByInputValue(presets, compositeValue);
      if (matchedPreset) {
        commitPresetValue(code, matchedPreset, getPresetInputParts(matchedPreset, delimiter));
        return;
      }

      var targetQuantity = resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue);
      if (!Number.isFinite(targetQuantity) || targetQuantity <= 0) {
        setActionState(code, "error", "Не удалось определить тираж");
        return;
      }
      var selectedValuesResult = buildSelectedValuesWithDraft(code, compositeValue);
      if (selectedValuesResult.error) {
        setActionState(selectedValuesResult.error.code || code, "error", selectedValuesResult.error.message || "Заполните обязательное свойство");
        return;
      }
      var adjustedTargetQuantity = normalizeVolumeForSelection(targetQuantity, selectedValuesResult.payload);
      if (Number.isFinite(adjustedTargetQuantity) && adjustedTargetQuantity !== targetQuantity) {
        targetQuantity = adjustedTargetQuantity;
        var adjustedPreset = findPresetByInputValue(presetsByCode[volumeCode] || [], String(adjustedTargetQuantity));
        customVolumeValue = adjustedPreset ? Number.NaN : adjustedTargetQuantity;
        selectedByProperty[volumeCode] = adjustedPreset
          ? String(adjustedPreset.xml_id || adjustedTargetQuantity)
          : String(adjustedTargetQuantity);
        customByProperty[volumeCode] = !adjustedPreset;
      }
      var committedSnapshot = JSON.stringify(selectedByProperty || {});
      setCustomRequestBusy(true);
      var token = ++customRequestToken;
      setActionState(code, "loading", "");
      postData(payload.frontcalcAjaxUrl || "", {
        action: "calculate_custom",
        product_id: data.product_id || 0,
        selected_values: JSON.stringify(selectedValuesResult.payload),
        target_quantity: targetQuantity,
        calculation_session_id: calculationSessionId,
        sessid: window.BX && typeof window.BX.bitrix_sessid === "function" ? window.BX.bitrix_sessid() : ""
      }, function (response) {
        if (token !== customRequestToken) return;
        setCustomRequestBusy(false);
        if (!$content.closest("body").length || pendingPropertyCode !== code || JSON.stringify(selectedByProperty || {}) !== committedSnapshot) return;
        if (!response || response.success !== true) {
          if (response && response.error && response.error.code === "FRONTCALC_CALCULATION_SESSION_INVALID") {
            setActionState(code, "error", "Сессия расчёта устарела. Закройте и заново откройте калькулятор.");
            return;
          }
          setActionState(code, "error", response && response.error && response.error.message ? response.error.message : "Не удалось рассчитать значение");
          return;
        }
        if (response.data && response.data.calculation_session_id) calculationSessionId = String(response.data.calculation_session_id);
        offers = mergeOffersByOfferKey(offers, response.data && response.data.offers);
        mergePresets(presetsByCode, buildPresetsByProperty(response.data && response.data.offers ? response.data.offers : []));
        selectedByProperty[code] = compositeValue;
        customByProperty[code] = false;
        if (controlsByCode[code] && controlsByCode[code].chips) {
          controlsByCode[code].chips.find(".is-active").removeClass("is-active");
        }
        committedInputValuesByProperty[code] = values.slice();
        draftValuesByProperty[code] = values.slice();
        pendingPropertyCode = "";
        setActionState(code, "clean", "");
        ensureVolumeWithinCurrentConstraints();
        updatePrice();
      }, function (errorMessage) {
        if (token !== customRequestToken) return;
        setCustomRequestBusy(false);
        if (!$content.closest("body").length || pendingPropertyCode !== code || JSON.stringify(selectedByProperty || {}) !== committedSnapshot) return;
        setActionState(code, "error", "Ошибка расчёта: " + errorMessage);
      });
    }

    selectorCodes.forEach(function (code) {
      var fieldConfig = fieldByCode[code] || {};
      var label = getFieldLabel(fieldConfig, propertyMetaByCode, code);
      var $section = $('<section class="frontcalc-field"></section>');
      if (label) {
        $section.append('<div class="frontcalc-field__title sku-props__title">' + escapeHtml(label) + "</div>");
      }

      var presets = Array.isArray(presetsByCode[code]) ? presetsByCode[code] : [];
      var displayMode = String(fieldConfig.display_mode || fieldConfig.selector_type || '').toLowerCase();
      var isChipsOnlyMode = displayMode === 'chips_only' || displayMode === 'chips';
      var isVirtualChipsOnly = isChipsOnlyMode && !participatingByCode[code];
      var $chips = createPresetButtons(presets, function (preset) {
        if (customRequestLoading) return;
        resetPendingDraft(code);
        if (!isVirtualChipsOnly) {
          selectedByProperty[code] = preset.xml_id;
          customByProperty[code] = false;
          committedInputValuesByProperty[code] = getPresetInputParts(preset, fieldConfig.group_delimiter || fieldConfig.split_delimiter || "x");
          draftValuesByProperty[code] = committedInputValuesByProperty[code].slice();
          setInputsForProperty(code, committedInputValuesByProperty[code]);
          setActionState(code, "clean", "");
        }
        ensureVolumeWithinCurrentConstraints();
        updatePrice();
      });
      if (selectedByProperty[code]) {
        $chips.find('.frontcalc-chip[data-xml-id="' + selectedByProperty[code] + '"]').addClass("is-active");
      } else if (isVirtualChipsOnly && presets.length) {
        $chips.find('.frontcalc-chip[data-xml-id="' + presets[0].xml_id + '"]').addClass("is-active");
      }
      controlsByCode[code] = { chips: $chips, presets: presets };

      var hasShowPresetsSetting = Object.prototype.hasOwnProperty.call(fieldConfig, "show_presets");
      var showPresetsBySetting = hasShowPresetsSetting ? isTruthyFlag(fieldConfig.show_presets) : true;
      if (!presets.length || !showPresetsBySetting) $chips.hide();

      var groupItems = Array.isArray(fieldConfig.group_inputs)
        ? fieldConfig.group_inputs
        : Array.isArray(fieldConfig.inputs)
        ? fieldConfig.inputs
        : [];
      var hasInputFlag =
        isTruthyFlag(fieldConfig.enable_input) ||
        isTruthyFlag(fieldConfig.input_enabled) ||
        isTruthyFlag(fieldConfig.allow_input) ||
        isTruthyFlag(fieldConfig.show_input) ||
        isTruthyFlag(fieldConfig.show_inputs) ||
        isTruthyFlag(fieldConfig.custom_input_enabled) ||
        isTruthyFlag(fieldConfig.enable_custom_input) ||
        String(fieldConfig.type || "").toLowerCase() === "input" ||
        Number.isFinite(parseNumber(fieldConfig.min, Number.NaN)) ||
        Number.isFinite(parseNumber(fieldConfig.max, Number.NaN));

      if (!isChipsOnlyMode && (hasInputFlag || groupItems.length > 0)) {
        var delimiter = fieldConfig.group_delimiter || fieldConfig.split_delimiter || "x";
        var uiGroupDivider = "×";
        if (!groupItems.length) groupItems = [fieldConfig];
        var selectedXmlForCode = String(selectedByProperty[code] || "");
        var selectedPresetForInput = findPresetByInputValue(presets, selectedXmlForCode);
        var selectedParts = selectedPresetForInput
          ? getPresetInputParts(selectedPresetForInput, delimiter)
          : (selectedXmlForCode ? selectedXmlForCode.split(delimiter) : []);
        committedInputValuesByProperty[code] = selectedParts.slice();
        draftValuesByProperty[code] = selectedParts.slice();
        inputControlsByProperty[code] = [];
        controlsByCode[code].inputFields = groupItems;
        controlsByCode[code].delimiter = delimiter;

        var $group = $('<div class="frontcalc-input-group"></div>');
        groupItems.forEach(function (item, idx) {
          if (idx > 0) {
            $group.append('<span class="frontcalc-input-group-divider">' + uiGroupDivider + "</span>");
          }
          var selectedPart = idx < selectedParts.length ? selectedParts[idx] : "";
          var initial = parseNumber(
            selectedPart !== "" ? selectedPart : item.default,
            parseNumber(item.default, 0)
          );
          selectedParts[idx] = String(initial);
          var $inputField = createInputControl(
            item,
            initial,
            {
            beforeChange: function () {
              return !customRequestLoading;
            },
            onDraft: function () {
              var groupValues = [];
              $section.find(".frontcalc-input-group .frontcalc-num-input").each(function () {
                groupValues.push(String($(this).val() || "").trim());
              });
              draftValuesByProperty[code] = groupValues;
              if (controlsByCode[code] && controlsByCode[code].chips) {
                controlsByCode[code].chips.find(".is-active").removeClass("is-active");
              }
              markDraftChanged(code);
            },
            onReset: function () {
              if (customRequestLoading) return;
              setInputsForProperty(code, committedInputValuesByProperty[code]);
              draftValuesByProperty[code] = (committedInputValuesByProperty[code] || []).slice();
              if (controlsByCode[code] && controlsByCode[code].chips) {
                var committedXmlId = String(selectedByProperty[code] || "");
                controlsByCode[code].chips.find(".is-active").removeClass("is-active");
                controlsByCode[code].chips.find(".frontcalc-chip").filter(function () {
                  return String($(this).attr("data-xml-id") || "") === committedXmlId;
                }).addClass("is-active");
              }
              if (pendingPropertyCode === code) pendingPropertyCode = "";
              setActionState(code, "clean", "");
            },
            onConfirm: function () {
              confirmDraft(code, delimiter, presets);
            }
            }
          );
          inputControlsByProperty[code].push($inputField.find(".frontcalc-num-input"));
          $group.append($inputField);

          $chips.on("click", ".frontcalc-chip", function () {
            var xmlId = $(this).attr("data-xml-id") || "";
            if (!xmlId) return;
            var clickedPreset = findPresetByInputValue(presets, xmlId);
            var parts = getPresetInputParts(clickedPreset || { xml_id: xmlId }, delimiter);
            if (parts.length > idx) {
              var $input = $inputField.find(".frontcalc-num-input");
              $input.val(parts[idx]);
            }
          });
        });
        committedInputValuesByProperty[code] = selectedParts.slice();
        draftValuesByProperty[code] = selectedParts.slice();
        var $actions = $(
          '<div class="frontcalc-input-actions" aria-live="polite">' +
            '<button type="button" class="frontcalc-input-confirm" title="Подтвердить значение" aria-label="Подтвердить значение">✓</button>' +
            '<button type="button" class="frontcalc-input-reset" title="Сбросить значение" aria-label="Сбросить значение">×</button>' +
            '<span class="frontcalc-input-error"></span>' +
          '</div>'
        );
        $actions.on("click", ".frontcalc-input-confirm", function () {
          confirmDraft(code, delimiter, presets);
        });
        $actions.on("click", ".frontcalc-input-reset", function () {
          if (customRequestLoading) return;
          setInputsForProperty(code, committedInputValuesByProperty[code]);
          draftValuesByProperty[code] = (committedInputValuesByProperty[code] || []).slice();
          if (pendingPropertyCode === code) pendingPropertyCode = "";
          setActionState(code, "clean", "");
        });
        controlsByCode[code].actions = $actions;
        $group.append($actions);
        $section.append($group);
        $section.append($chips);

        if (showPresetsBySetting && presets.length) {
          var presetInteractionInProgress = false;
          $chips.hide();
          $chips.on("mousedown touchstart", ".frontcalc-chip", function () {
            presetInteractionInProgress = true;
          });
          $section.on("focusin", ".frontcalc-num-input", function () {
            $chips.show();
          });
          $section.on("focusout", ".frontcalc-num-input", function () {
            setTimeout(function () {
              if (presetInteractionInProgress) return;
              if ($(document.activeElement).hasClass("frontcalc-num-input")) return;
              $chips.hide();
            }, 0);
          });

          $chips.on("click", ".frontcalc-chip", function () {
            presetInteractionInProgress = false;
            $chips.hide();
            this.blur();
          });
        }
      } else {
        $section.append($chips);
      }

      $selectors.append($section);
    });

    function updatePrice() {
      ensureVolumeWithinCurrentConstraints();
      selectorCodes.forEach(function (code) {
        var controls = controlsByCode[code] || {};
        if (!controls.chips) return;
        controls.chips.find(".frontcalc-chip").each(function () {
          var $chip = $(this);
          if (!limitToAvailableOptions) {
            if ($chip.attr("data-frontcalc-background-disabled") === "1") {
              $chip.prop("disabled", false).removeAttr("data-frontcalc-background-disabled");
            }
            return;
          }
          var draftSelection = Object.assign({}, selectedByProperty);
          draftSelection[code] = String($chip.attr("data-xml-id") || "");
          var available = !!pickMatchedOfferIgnoringCustom(offers, draftSelection, customByProperty, volumeCode);
          $chip.prop("disabled", !available);
          if (!available) $chip.attr("data-frontcalc-background-disabled", "1");
        });
      });
      $selectors.find(".frontcalc-num-input,.frontcalc-input-confirm").prop("disabled", limitToAvailableOptions);
      var matched = pickMatchedOffer(offers, selectedByProperty, customByProperty);
      var hasCustomNonVolumeSelection = hasCustomSelection(customByProperty, volumeCode);
      var targetQuantity = FrontcalcMath && typeof FrontcalcMath.resolveCurrentTargetQuantity === "function"
        ? FrontcalcMath.resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue)
        : parseNumber(customVolumeValue || selectedByProperty[volumeCode], Number.NaN);
      var selectedVolumePreset = findPresetByInputValue(presetsByCode[volumeCode] || [], selectedByProperty[volumeCode]);
      var titleTargetQuantity = Number.isFinite(customVolumeValue) ? customVolumeValue : resolvePresetQuantity(selectedVolumePreset);
      if (!Number.isFinite(titleTargetQuantity)) titleTargetQuantity = targetQuantity;
      var calculatedTitleOffer = hasCustomNonVolumeSelection ? null : pickCalcServerTitleOffer(offers, selectedByProperty, customByProperty, volumeCode, titleTargetQuantity);
      var displayOffer = calculatedTitleOffer || matched || (!hasCustomNonVolumeSelection ? (pickMatchedOfferIgnoringCustom(offers, selectedByProperty, customByProperty, null) || anchorOffer) : null);
      if (displayOffer) {
        anchorOffer = displayOffer;
      }
      var titleText = buildOfferTitleForUi(displayOffer, data.product_name)
        || String((displayOffer && displayOffer.name) || "")
        || String(data.product_name || "");
      $title.find(".frontcalc-offer-title__text").text(titleText);
      $title.attr("data-current-title", titleText);
      if (presetsByCode[volumeCode] && presetsByCode[volumeCode].length) {
        var priceTableConfig = Object.assign({}, getCurrentVolumeFieldConfig(), {
          deadline_adjustments: config.deadline_adjustments || {},
          deadline_texts: data.deadline_texts || {},
          rounding_rules: data.price_rounding_rules || {},
          volume_grid: data.volume_grid || {}
        });
        var internalViewModel = null;
        if (canViewInternal && Number.isFinite(targetQuantity)) {
          var sourcePoints = getPriceSourcePointsForQuantity(offers, selectedByProperty, customByProperty, volumeCode, targetQuantity, selectedCatalogGroupId);
          if (sourcePoints && FrontcalcMath && typeof FrontcalcMath.buildInternalViewModel === "function") {
            internalViewModel = FrontcalcMath.buildInternalViewModel(sourcePoints.points, targetQuantity);
            if (!(FrontcalcMath.shouldShowInternalPanel && FrontcalcMath.shouldShowInternalPanel(access, internalViewModel.sources))) {
              internalViewModel = null;
            }
          }
        }
        renderPriceTable($priceInner, offers, presetsByCode, selectedByProperty, customByProperty, fieldByCode, volumeCode, customVolumeValue, priceGroups, selectedCatalogGroupId, buyCatalogGroupId, priceTableConfig, deadlineType, matched || displayOffer, internalViewModel, internalPanelOpen, {loading: internalRequestLoading, error: internalRequestError}, $internalPanelHost, function (isOpen) {
          internalPanelOpen = isOpen === true;
          return;
          if (!internalPanelOpen || internalViewModel || internalRequestLoading || !Number.isFinite(targetQuantity)) return;
          internalRequestLoading = true;
          internalRequestError = "";
          updatePrice();
          postData(payload.frontcalcAjaxUrl || "", {
            action: "calculate_custom",
            product_id: data.product_id || 0,
            selected_values: JSON.stringify(serializeSelectedProperties(selectedByProperty, presetsByCode)),
            target_quantity: targetQuantity,
            calculation_session_id: calculationSessionId,
            sessid: window.BX && typeof window.BX.bitrix_sessid === "function" ? window.BX.bitrix_sessid() : ""
          }, function (response) {
            internalRequestLoading = false;
            if (!response || response.success !== true) {
              internalRequestError = response && response.error && response.error.message ? response.error.message : "Не удалось получить внутренние данные.";
              updatePrice();
              return;
            }
            if (response.data && response.data.calculation_session_id) calculationSessionId = String(response.data.calculation_session_id);
            offers = mergeOffersByOfferKey(offers, response.data && response.data.offers);
            mergePresets(presetsByCode, buildPresetsByProperty(response.data && response.data.offers ? response.data.offers : []));
            updatePrice();
          }, function () {
            internalRequestLoading = false;
            internalRequestError = "Не удалось получить внутренние данные.";
            updatePrice();
          });
        });
        if (backgroundCalculationLoading) {
          $priceInner.find(".frontcalc-cart-wrap").addClass("loadings");
          $priceInner.find(".frontcalc-cart-btn").prop("disabled", true);
        }
        $priceInner.find(".frontcalc-volume-btn,.frontcalc-table-input").prop("disabled", limitToAvailableOptions);
        var renderedSelectedQuantity = parseNumber($priceInner.find(".frontcalc-cell.is-picked[data-col]").first().closest(".frontcalc-table-row").attr("data-quantity"), Number.NaN);
        if (!hasCustomNonVolumeSelection && Number.isFinite(renderedSelectedQuantity)) {
          var renderedTitleOffer = pickCalcServerTitleOffer(offers, selectedByProperty, customByProperty, volumeCode, renderedSelectedQuantity);
          if (renderedTitleOffer) {
            displayOffer = renderedTitleOffer;
            anchorOffer = renderedTitleOffer;
            titleText = buildOfferTitleForUi(renderedTitleOffer, data.product_name) || titleText;
            titleText = titleText.replace(/([\d\s\u00a0]+)\s*экз\.?\s*$/i, " " + formatQuantityValue(renderedSelectedQuantity) + " экз");
            $title.find(".frontcalc-offer-title__text").text(titleText);
            $title.attr("data-current-title", titleText);
          }
        }
      } else {
        renderPriceBlock($priceInner, matched || displayOffer);
        if ($internalPanelHost) $internalPanelHost.empty();
      }
    }


    $price.on("click", ".frontcalc-deadline-tabs a[data-deadline]", function (event) {
      event.preventDefault();
      if (customRequestLoading) return;
      deadlineType = String($(this).attr("data-deadline") || "strict");
      updatePrice();
    });

    $price.on("click", ".frontcalc-price-group", function () {
      if (customRequestLoading) return;
      var groupId = parseNumber($(this).attr("data-catalog-group-id"), Number.NaN);
      if (!Number.isFinite(groupId)) return;
      selectedCatalogGroupId = groupId;
      updatePrice();
    });

    $price.on("click", ".frontcalc-table-row .frontcalc-cell", function () {
      if (customRequestLoading) return;
      resetPendingDraft(volumeCode);
      var $cell = $(this);
      var $row = $cell.closest('.frontcalc-table-row');
      var xmlId = String($row.attr('data-xml-id') || '');
      if (!xmlId) return;
      var rowQuantity = parseNumber($row.attr("data-quantity"), Number.NaN);
      var currentVolumeStep = getCurrentVolumeStepInfo();
      var fallback = getFallbackVolumeBounds();
      var currentVolumeBounds = getCurrentVolumeBounds(fallback.min, fallback.max, currentVolumeStep);
      var normalizedVolume = normalizeVolumeByStep(rowQuantity, currentVolumeBounds.min, currentVolumeBounds.max, currentVolumeStep);
      var knownPreset = findPresetByInputValue(presetsByCode[volumeCode] || [], xmlId) || findPresetByInputValue(presetsByCode[volumeCode] || [], String(normalizedVolume));
      selectedByProperty[volumeCode] = knownPreset ? String(knownPreset.xml_id || normalizedVolume) : String(normalizedVolume);
      customVolumeValue = !knownPreset && Number.isFinite(normalizedVolume) ? normalizedVolume : Number.NaN;
      customByProperty[volumeCode] = !knownPreset;
      updatePrice();
    });

    $price.on("mouseenter", ".frontcalc-table-row .frontcalc-cell", function () {
      var $cell = $(this);
      var colIndex = $cell.index();
      var $row = $cell.closest(".frontcalc-table-row");
      $price.find(".is-hover-row").removeClass("is-hover-row");
      $row.children(".frontcalc-cell").addClass("is-hover-row");
    });
    $price.on("mouseleave", ".frontcalc-table-row .frontcalc-cell", function () {
      $price.find(".is-hover-row").removeClass("is-hover-row");
    });

    $price.on("click", ".frontcalc-cart-btn", function () {
      var $button = $(this);
      var $wrap = $button.closest(".frontcalc-cart-wrap");
      var basketAllowed = canAddToBasketWithDraft(pendingPropertyCode, customRequestLoading);
      if (!basketAllowed.allowed) {
        if (basketAllowed.reason === "pending") showPendingDraftRequired(basketAllowed.code);
        return;
      }
      if ($button.attr("data-buy-enabled") !== "1") {
        if (Number.isFinite(buyCatalogGroupId)) {
          selectedCatalogGroupId = buyCatalogGroupId;
          updatePrice();
        }
        return;
      }
      if ($wrap.hasClass("loadings")) return;

      var $picked = $price.find(".frontcalc-cell.is-picked[data-col]").first();
      var priceValue = parseNumber($picked.attr("data-price"), Number.NaN);
      if (!Number.isFinite(priceValue) || priceValue <= 0) {
        if (window.alert) window.alert("Не удалось определить цену для добавления в корзину.");
        return;
      }

      var selectedValuesForBasket = serializeSelectedProperties(selectedByProperty, presetsByCode);
      var targetQuantityForBasket = getCurrentVolumeQuantity();
      $wrap.addClass("loadings");
      $button.prop("disabled", true);
      postData(payload.frontcalcAjaxUrl || "", {
        action: "add_to_basket",
        product_id: data.product_id || 0,
        calculation_session_id: calculationSessionId,
        selected_values: JSON.stringify(selectedValuesForBasket),
        target_quantity: Number.isFinite(targetQuantityForBasket) ? targetQuantityForBasket : 0,
        catalog_group_id: selectedCatalogGroupId,
        deadline_type: deadlineType,
        displayed_price: priceValue,
        sessid: window.BX && typeof window.BX.bitrix_sessid === "function" ? window.BX.bitrix_sessid() : ""
      }, function (response) {
        $wrap.removeClass("loadings");
        $button.prop("disabled", false);
        if (!response || response.success !== true) {
          if (response && response.error && response.error.code === "FRONTCALC_CALCULATION_SESSION_INVALID") {
            if (window.alert) window.alert("Сессия расчёта устарела. Закройте и заново откройте калькулятор.");
            return;
          }
          if (window.alert) window.alert(response && response.message ? response.message : (response && response.error && response.error.message ? response.error.message : "Не удалось добавить позицию в корзину."));
          return;
        }
        $(document).trigger("frontcalc:basketAdded", [response]);
        if (window.BX && typeof window.BX.onCustomEvent === "function") {
          window.BX.onCustomEvent("OnBasketChange", [response]);
        }
      }, function (errorMessage) {
        $wrap.removeClass("loadings");
        $button.prop("disabled", false);
        if (window.alert) window.alert("Ошибка добавления в корзину: " + errorMessage);
      });
    });

    function setVolumeValue(nextValue) {
      if (customRequestLoading) return;
      resetPendingDraft(volumeCode);
      var list = presetsByCode[volumeCode] || [];
      if (!list.length) return;
      var currentVolumeStep = getCurrentVolumeStepInfo();
      var fallback = getFallbackVolumeBounds();
      var currentVolumeBounds = getCurrentVolumeBounds(fallback.min, fallback.max, currentVolumeStep);
      var val = normalizeVolumeByStep(parseNumber(nextValue, currentVolumeBounds.min), currentVolumeBounds.min, currentVolumeBounds.max, currentVolumeStep);
      var nextPreset = findPresetByInputValue(list, String(val));
      customVolumeValue = nextPreset ? Number.NaN : val;
      selectedByProperty[volumeCode] = nextPreset ? String(nextPreset.xml_id || val) : String(val);
      customByProperty[volumeCode] = !nextPreset;
      updatePrice();
    }

    $price.on("click", ".frontcalc-volume-btn", function () {
      var direction = parseNumber($(this).attr('data-step'), 0);
      var stepInfo = getCurrentVolumeStepInfo();
      var current = getCurrentVolumeQuantity();
      setVolumeValue((Number.isFinite(current) ? current : 0) + direction * stepInfo.step);
    });

    $price.on("wheel", ".frontcalc-table-input", function (event) {
      event.preventDefault();
      event.stopPropagation();
      var direction = event.originalEvent && event.originalEvent.deltaY < 0 ? 1 : -1;
      var stepInfo = getCurrentVolumeStepInfo();
      var current = getCurrentVolumeQuantity();
      setVolumeValue((Number.isFinite(current) ? current : 0) + direction * stepInfo.step);
    });

    $price.on("input", ".frontcalc-table-input", function () {
      if (customRequestLoading) return;
      resetPendingDraft(volumeCode);
    });

    $price.on("change blur", ".frontcalc-table-input", function () {
      setVolumeValue(normalizeValueToken($(this).val()));
    });

    if ($internalPanelHost) {
      $internalPanelHost.on("click", ".frontcalc-internal-action", function (event) {
        event.preventDefault();
        event.stopPropagation();
        if (internalRequestLoading) return;
        var requestedQuantity = parseNumber($(this).attr("data-quantity"), Number.NaN);
        if (!Number.isFinite(requestedQuantity) || requestedQuantity <= 0) return;
        internalRequestLoading = true;
        internalRequestError = "";
        updatePrice();
        postData(payload.frontcalcAjaxUrl || "", {
          action: "calculate_custom",
          product_id: data.product_id || 0,
          selected_values: JSON.stringify(serializeSelectedProperties(selectedByProperty, presetsByCode)),
          target_quantity: requestedQuantity,
          calculation_session_id: calculationSessionId,
          sessid: window.BX && typeof window.BX.bitrix_sessid === "function" ? window.BX.bitrix_sessid() : ""
        }, function (response) {
          internalRequestLoading = false;
          if (!response || response.success !== true) {
            internalRequestError = response && response.error && response.error.message ? response.error.message : "Не удалось получить внутренние данные.";
            updatePrice();
            return;
          }
          if (response.data && response.data.calculation_session_id) calculationSessionId = String(response.data.calculation_session_id);
          offers = mergeOffersByOfferKey(offers, response.data && response.data.offers);
          mergePresets(presetsByCode, buildPresetsByProperty(response.data && response.data.offers ? response.data.offers : []));
          updatePrice();
        }, function () {
          internalRequestLoading = false;
          internalRequestError = "Не удалось получить внутренние данные.";
          updatePrice();
        });
      });
    }

    $layout.append($selectors, $price);
    if ($internalPanelHost) $layout.append($internalPanelHost);
    $content.html($layout);

    $(document).off("frontcalc:enriched.frontcalcPopup").on("frontcalc:enriched.frontcalcPopup", function (_event, enrichedPayload) {
      if (!enrichedPayload || String(enrichedPayload.productId || "") !== String(data.product_id || "")) return;
      if (backgroundCalculationTimer) window.clearTimeout(backgroundCalculationTimer);
      backgroundCalculationLoading = false;
      if (!enrichedPayload.payload || enrichedPayload.payload.success !== true) {
        if (window.console && typeof window.console.warn === "function") window.console.warn("[frontcalc] Сервер калькуляций недоступен.");
        internalRequestError = enrichedPayload.error || "Не удалось завершить фоновый расчёт.";
        updatePrice();
        return;
      }
      limitToAvailableOptions = false;
      var enrichedData = enrichedPayload.payload.data || {};
      if (enrichedData.calculation_session_id) calculationSessionId = String(enrichedData.calculation_session_id);
      offers = mergeOffersByOfferKey(offers, enrichedData.offers);
      mergePresets(presetsByCode, buildPresetsByProperty(enrichedData.offers || []));
      updatePrice();
    });
    if (backgroundCalculationLoading) {
      backgroundCalculationTimer = window.setTimeout(function () {
        if (!backgroundCalculationLoading) return;
        backgroundCalculationLoading = false;
        internalRequestError = "Сервер калькуляций недоступен.";
        if (window.console && typeof window.console.warn === "function") window.console.warn("[frontcalc] Сервер калькуляций недоступен.");
        updatePrice();
      }, 10000);
    }
    updatePrice();
  }

  function openFrame(renderCallback) {
    var $frame = createFrame();

    $frame.jqm({
      onHide: function (hash) {
        hash.w.remove();
        hash.o.remove();
        $("body").css({ overflow: "", height: "" }).removeClass("jqm-initied swipeignore");
        $("#popup_iframe_wrapper").css({ "z-index": "", display: "" });
      }
    });

    $("body").addClass("jqm-initied swipeignore").css({ overflow: "hidden", height: "100vh" });
    $("#popup_iframe_wrapper").css({ "z-index": 3000, display: "flex" });

    $frame.jqmShow();
    renderCallback($frame.find(".js-frontcalc-popup-content"));
  }

  function openCalculatorPopup(payload, offerId) {
    openFrame(function ($content) {
      renderCalculator($content, payload, { offerId: offerId });
    });
  }

  function openErrorPopup(message) {
    openFrame(function ($content) {
      renderError($content, message);
    });
  }

  function logCalcServerRawPayload(payload) {
    if (!payload || payload.success !== true || !payload.data || payload.data.calc_server_raw == null) {
      return;
    }

    if (!window.console || typeof window.console.log !== "function") {
      return;
    }

    window.console.log("[frontcalc] raw calc-server response", payload.data.calc_server_raw);
  }

  function finishRequest(info, payload, errorMessage) {
    var callbacks = inflightRequests[info.cacheKey] || [];
    delete inflightRequests[info.cacheKey];

    if (payload && payload.success === true) {
      payload.frontcalcAjaxUrl = info.ajaxUrl;
      logCalcServerRawPayload(payload);
    }

    callbacks.forEach(function (callback) {
      callback(payload, errorMessage);
    });
  }


  function getAccessPayload($button) {
    var raw = $button.attr("data-frontcalc-access") || "";
    if (!raw) return {};
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function showRestrictedTooltip(button, access) {
    var isMobileTemplate = (window.matchMedia && window.matchMedia("(max-width: 991px)").matches)
      || document.documentElement.classList.contains("mobile")
      || (document.body && document.body.classList.contains("mobile"));
    var message = String((isMobileTemplate && access && access.restricted_mobile_message)
      || (access && access.restricted_message)
      || (isMobileTemplate
        ? "Используйте декстоп-версию сайта для возможности расширенной калькуляции."
        : "Расширенные функции калькуляции доступны верифицированным пользователям"));
    var moreUrl = String((access && access.restricted_more_url) || "").trim();
    var html = '<div class="frontcalc-restricted-tooltip__description">' + escapeHtml(message) + '</div>';
    if (moreUrl) {
      html += '<a class="frontcalc-restricted-tooltip__link" href="' + escapeHtml(moreUrl) + '">Подробнее</a>';
    }
    var $button = $(button);
    var $tooltip = $(".frontcalc-restricted-tooltip");
    if (!$tooltip.length) {
      $tooltip = $('<div class="frontcalc-restricted-tooltip" role="tooltip" aria-hidden="true"></div>').appendTo("body");
    }
    window.clearTimeout($tooltip.data("frontcalcHideTimer"));
    $tooltip.html(html).attr("aria-hidden", "false").addClass("is-open");
    var rect = button.getBoundingClientRect();
    var tooltipRect = $tooltip[0].getBoundingClientRect();
    var left = Math.max(8, Math.min(rect.left, window.innerWidth - tooltipRect.width - 8));
    var top = rect.bottom + 4;
    if (top + tooltipRect.height + 8 > window.innerHeight && rect.top - tooltipRect.height - 4 > 8) {
      top = rect.top - tooltipRect.height - 4;
    }
    $tooltip.css({left: Math.round(left) + "px", top: Math.round(top) + "px"});
    $tooltip.data("frontcalcTrigger", $button[0]);
    $tooltip.data("frontcalcHideTimer", window.setTimeout(function () {
      $tooltip.removeClass("is-open").attr("aria-hidden", "true");
    }, 5000));
  }

  $(document).off("click.frontcalcRestrictedTooltip").on("click.frontcalcRestrictedTooltip", function (event) {
    var $tooltip = $(".frontcalc-restricted-tooltip");
    if (!$tooltip.hasClass("is-open")) return;
    if ($(event.target).closest(".frontcalc-restricted-tooltip__link").length) return;
    var trigger = $tooltip.data("frontcalcTrigger");
    if (trigger && (event.target === trigger || $.contains(trigger, event.target))) return;
    window.clearTimeout($tooltip.data("frontcalcHideTimer"));
    $tooltip.removeClass("is-open").attr("aria-hidden", "true");
  });

  function shouldBlockByAccess(button) {
    var $button = $(button);
    var access = getAccessPayload($button);
    var permissions = access.permissions || {};
    if (access.scenario === "restricted" || permissions.can_open_calculator === false) {
      showRestrictedTooltip(button, access);
      return true;
    }
    return false;
  }

  function openPopup(button) {
    if (shouldBlockByAccess(button)) {
      return;
    }
    var $button = $(button);
    var info = buildRequestInfo($button);

    if (!info.ajaxUrl) {
      if (window.alert) window.alert("Не задан URL для запроса калькулятора.");
      return;
    }

    if (popupInstanceCache[info.cacheKey]) {
      openCalculatorPopup(popupInstanceCache[info.cacheKey], info.offerId);
      return;
    }

    if (inflightRequests[info.cacheKey]) return;
    inflightRequests[info.cacheKey] = true;
    setButtonLoading($button, true);

    var rendered = false;
    var completed = 0;
    var lastError = "";
    var openWithPayload = function (responsePayload) {
      if (rendered || !responsePayload || responsePayload.success !== true) return;
      responsePayload.frontcalcAjaxUrl = info.ajaxUrl;
      popupInstanceCache[info.cacheKey] = responsePayload;
      openCalculatorPopup(responsePayload, info.offerId);
      rendered = true;
      setButtonLoading($button, false);
    };
    var finishPhase = function (kind, responsePayload, errorMessage) {
      completed += 1;
      if (responsePayload && responsePayload.success === true) {
        responsePayload.frontcalcAjaxUrl = info.ajaxUrl;
        popupInstanceCache[info.cacheKey] = responsePayload;
        if (kind === "full") {
          logCalcServerRawPayload(responsePayload);
          if (rendered) {
            $(document).trigger("frontcalc:enriched", [{productId: info.productId, payload: responsePayload}]);
          } else {
            openWithPayload(responsePayload);
          }
          delete inflightRequests[info.cacheKey];
          return;
        }
        openWithPayload(responsePayload);
      } else {
        lastError = errorMessage || (responsePayload && responsePayload.message) || lastError;
        if (kind === "full" && rendered) {
          $(document).trigger("frontcalc:enriched", [{productId: info.productId, error: lastError || "Ошибка фонового расчёта"}]);
          delete inflightRequests[info.cacheKey];
        }
      }
      if (completed >= 2 && !rendered) {
        delete inflightRequests[info.cacheKey];
        setButtonLoading($button, false);
        openErrorPopup("Ошибка запроса: " + (lastError || "Сервер вернул ошибку."));
      }
    };
    var requestBase = {
      action: "load",
      product_id: info.productId,
      offer_id: info.offerId,
      sessid: window.BX && typeof BX.bitrix_sessid === "function" ? BX.bitrix_sessid() : ""
    };
    postData(info.requestUrl, Object.assign({}, requestBase, {defer_calculation: "Y"}), function (responsePayload) {
      finishPhase("initial", responsePayload, "");
    }, function (errorMessage) {
      finishPhase("initial", null, errorMessage);
    });
    postData(info.requestUrl, requestBase, function (responsePayload) {
      finishPhase("full", responsePayload, "");
    }, function (errorMessage) {
      finishPhase("full", null, errorMessage);
    });
  }


  function parseOpenPopupChips($button) {
    var raw = $button.attr("data-frontcalc-open-popup-chips") || "";
    if (!raw) {
      return [];
    }
    try {
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function normalizeChipText(value) {
    return String(value || "").replace(/\s+/g, " ").replace(/[:：]+$/, "").trim().toLowerCase();
  }

  var openPopupChipRegistry = [];

  function rememberOpenPopupChipRegistry(chips, attrs) {
    var productId = String((attrs && attrs["data-frontcalc-product-id"]) || "");
    var ajaxUrl = String((attrs && attrs["data-frontcalc-ajax-url"]) || "");
    var key = productId + "|" + ajaxUrl;
    var item = { key: key, chips: chips, attrs: attrs || {} };

    for (var i = 0; i < openPopupChipRegistry.length; i += 1) {
      if (openPopupChipRegistry[i].key === key) {
        openPopupChipRegistry[i] = item;
        return;
      }
    }

    openPopupChipRegistry.push(item);
  }

  function copyFrontcalcDataAttrs($source, $target) {
    ["data-frontcalc-product-id", "data-frontcalc-offer-id", "data-frontcalc-ajax-url", "data-frontcalc-open-popup-chips", "data-frontcalc-access"].forEach(function (attr) {
      var value = $source.attr(attr);
      if (value !== undefined && value !== null && value !== "") {
        $target.attr(attr, value);
      }
    });
  }

  function getOpenPopupChipScope($sourceButton) {
    var $scope = $sourceButton.closest(".catalog-detail, .catalog-list__item, .grid-list__item, .catalog-block__item, .catalog-adaptive__item, .js-popup-block-adaptive");
    if (!$scope.length) {
      $scope = $sourceButton.closest(".catalog-detail__main-part, .catalog-list__info, .catalog-detail__buy-block").parent();
    }
    return $scope.length ? $scope : $();
  }

  function rememberOpenPopupChipSource($scope, $sourceButton, chips) {
    var attrs = {};
    ["data-frontcalc-product-id", "data-frontcalc-offer-id", "data-frontcalc-ajax-url", "data-frontcalc-open-popup-chips", "data-frontcalc-access"].forEach(function (attr) {
      var value = $sourceButton.attr(attr);
      if (value !== undefined && value !== null && value !== "") {
        attrs[attr] = value;
      }
    });

    $scope
      .attr("data-frontcalc-open-popup-chip-scope", "Y")
      .data("frontcalcOpenPopupChips", chips)
      .data("frontcalcOpenPopupAttrs", attrs);
    rememberOpenPopupChipRegistry(chips, attrs);
  }

  function applyStoredFrontcalcDataAttrs($target, attrs) {
    Object.keys(attrs || {}).forEach(function (attr) {
      if (attrs[attr] !== undefined && attrs[attr] !== null && attrs[attr] !== "") {
        $target.attr(attr, attrs[attr]);
      }
    });
  }

  function copySiblingSkuFontClass($values, $chipButton) {
    var sibling = $values.find(".sku-props__value").not(".frontcalc-open-popup-chip").get(0);
    if (!sibling || !sibling.className) {
      return;
    }
    String(sibling.className).split(/\s+/).forEach(function (className) {
      if (/^font_\d+$/.test(className)) {
        $chipButton.addClass(className);
      }
    });
  }

  function injectOpenPopupChipsIntoScope($scope, chips, $sourceButton, attrs) {
    if (!$scope || !$scope.length || !Array.isArray(chips) || !chips.length) {
      return;
    }

    chips.forEach(function (chip) {
      var propertyCode = String((chip && chip.property_code) || "").trim();
      var propertyName = normalizeChipText((chip && chip.property_name) || "");
      var label = String((chip && chip.label) || "").trim();
      if (!propertyCode || !propertyName || !label) {
        return;
      }

      $scope.find(".sku-props__inner, .sku-props__item").each(function () {
        var $prop = $(this);
        var $values = $prop.find(".sku-props__values").first();
        if (!$values.length) {
          return;
        }
        $values.find(".frontcalc-open-popup-chip-wrap").filter(function () {
          return $(this).css("display") === "none";
        }).remove();
        if ($values.find('.frontcalc-open-popup-chip[data-frontcalc-chip-property="' + propertyCode + '"]').length) {
          return;
        }

        var titleText = normalizeChipText($prop.find(".sku-props__title").first().text());
        if (!titleText || titleText.indexOf(propertyName) !== 0) {
          return;
        }

        var $item = $('<div class="line-block__item frontcalc-open-popup-chip-wrap"></div>');
        var $chipButton = $('<button type="button" class="sku-props__value frontcalc-open-popup-chip js-frontcalc-calculate"></button>')
          .attr("data-frontcalc-chip-property", propertyCode)
          .attr("title", label)
          .append($('<span class="lineclamp-2"></span>').text(label));
        copySiblingSkuFontClass($values, $chipButton);
        if ($sourceButton && $sourceButton.length) {
          copyFrontcalcDataAttrs($sourceButton, $chipButton);
        } else {
          applyStoredFrontcalcDataAttrs($chipButton, attrs || $scope.data("frontcalcOpenPopupAttrs") || {});
        }
        $item.append($chipButton);
        $values.append($item);
      });
    });
  }

  function injectOpenPopupChips() {
    restoreCatalogListProductPropertyOrder();
    $(".frontcalc-open-popup-source[data-frontcalc-product-id], .frontcalc_but__openpopup[data-frontcalc-product-id], .js-frontcalc-calculate[data-frontcalc-product-id]").not(".frontcalc-open-popup-chip").each(function () {
      var $sourceButton = $(this);
      var chips = parseOpenPopupChips($sourceButton);
      if (!chips.length) {
        return;
      }

      var $scope = getOpenPopupChipScope($sourceButton);
      if (!$scope.length) {
        return;
      }

      rememberOpenPopupChipSource($scope, $sourceButton, chips);
      injectOpenPopupChipsIntoScope($scope, chips, $sourceButton, null);
    });

    $('[data-frontcalc-open-popup-chip-scope="Y"]').each(function () {
      var $scope = $(this);
      injectOpenPopupChipsIntoScope($scope, $scope.data("frontcalcOpenPopupChips") || [], null, $scope.data("frontcalcOpenPopupAttrs") || {});
    });

    var $detailScope = $(".catalog-detail, .catalog-detail__main-part").first();
    if ($detailScope.length) {
      var $detailButton = $(".frontcalc_but__openpopup--detail[data-frontcalc-product-id]").first();
      if ($detailButton.length) {
        injectOpenPopupChipsIntoScope($detailScope, parseOpenPopupChips($detailButton), $detailButton, null);
      } else if (openPopupChipRegistry.length) {
        injectOpenPopupChipsIntoScope($detailScope, openPopupChipRegistry[0].chips, null, openPopupChipRegistry[0].attrs);
      }
    }
  }

  function restoreCatalogListProductPropertyOrder() {
    $(".catalog-list__info").each(function () {
      var info = this;
      var $productProperties = $(info).find(".catalog-list__info-text-props").first();
      var $skuProperties = $(info).find(".sku-props").first();
      if (!$productProperties.length || !$skuProperties.length || $.contains($productProperties[0], $skuProperties[0])) return;
      var commonParent = $productProperties[0].parentElement;
      while (commonParent && commonParent !== info && !$.contains(commonParent, $skuProperties[0])) {
        commonParent = commonParent.parentElement;
      }
      if (!commonParent || !$.contains(commonParent, $skuProperties[0])) return;
      var anchor = $skuProperties[0];
      while (anchor.parentElement && anchor.parentElement !== commonParent) anchor = anchor.parentElement;
      if ($productProperties[0] !== anchor && $productProperties[0].nextElementSibling !== anchor) {
        $(anchor).before($productProperties);
      }
    });
  }

  window.frontcalcDebugOpenPopupChips = function () {
    return {
      registry: openPopupChipRegistry,
      scopes: $('[data-frontcalc-open-popup-chip-scope="Y"]').length,
      chips: $(".frontcalc-open-popup-chip").length,
      skuGroups: $(".sku-props__inner, .sku-props__item").map(function () {
        return normalizeChipText($(this).find(".sku-props__title").first().text());
      }).get()
    };
  };

  var injectTimer = null;
  function scheduleOpenPopupChipInjection() {
    if (injectTimer) {
      window.clearTimeout(injectTimer);
    }
    injectTimer = window.setTimeout(function () {
      injectTimer = null;
      injectOpenPopupChips();
    }, 80);
  }

  scheduleOpenPopupChipInjection();
  window.setInterval(injectOpenPopupChips, 700);
  $(document).on("click change", ".sku-props__value, .sku-props input, .sku-props select", scheduleOpenPopupChipInjection);

  document.addEventListener("click", function (event) {
    var chip = event.target && event.target.closest ? event.target.closest(".frontcalc-open-popup-chip") : null;
    if (!chip) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) {
      event.stopImmediatePropagation();
    }
    if (!shouldBlockByAccess(chip)) {
      openPopup(chip);
    }
  }, true);

  $(document).on("click", ".js-frontcalc-calculate, .frontcalc_but__openpopup[data-frontcalc-product-id]", function (event) {
    event.preventDefault();
    openPopup(this);
  });
})(window, document, window.jQuery);
