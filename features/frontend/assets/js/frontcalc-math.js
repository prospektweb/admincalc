(function (root, factory) {
  var api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.FrontcalcMath = api;
  }
})(typeof window !== "undefined" ? window : (typeof globalThis !== "undefined" ? globalThis : null), function () {
  "use strict";

  function parseQuantity(value) {
    var normalized = typeof value === "string" ? value.replace(/\s+/g, "").replace(",", ".") : value;
    var num = Number(normalized);
    return Number.isFinite(num) ? num : Number.NaN;
  }


  function normalizeValueToken(value) {
    return String(value || "")
      .replace(/\s+/g, "")
      .replace(",", ".")
      .trim();
  }

  function findPresetByInputValue(presets, value) {
    var normalized = normalizeValueToken(value);
    var numeric = parseQuantity(normalized);
    for (var i = 0; i < (Array.isArray(presets) ? presets : []).length; i++) {
      var preset = presets[i] || {};
      var xmlId = normalizeValueToken(preset.xml_id);
      var presetValue = normalizeValueToken(preset.value);
      if (normalized && (xmlId === normalized || presetValue === normalized)) return preset;
      var xmlNum = parseQuantity(xmlId);
      var valueNum = parseQuantity(presetValue);
      if (Number.isFinite(numeric) && (xmlNum === numeric || valueNum === numeric)) return preset;
    }
    return null;
  }

  function getPresetInputParts(preset, delimiter) {
    var separator = delimiter || "x";
    var xmlParts = String((preset && preset.xml_id) || "").split(separator);
    var valueParts = String((preset && preset.value) || "").split(separator);
    var hasNumericParts = function (parts) {
      return parts.length > 0 && parts.every(function (part) {
        return Number.isFinite(parseQuantity(part));
      });
    };

    if (hasNumericParts(xmlParts)) return xmlParts;
    if (hasNumericParts(valueParts)) return valueParts;
    return String((preset && (preset.value != null ? preset.value : preset.xml_id)) || "").split(separator);
  }

  function buildCustomSelectionPayload(selectedByProperty, presetsByCode, draftPropertyCode, draftValue, volumeCode, requiredCodes, committedInputValuesByProperty, delimitersByCode) {
    var payload = {};
    var codes = Array.isArray(requiredCodes) && requiredCodes.length
      ? requiredCodes.slice()
      : Object.keys(selectedByProperty || {});
    for (var c = 0; c < codes.length; c++) {
      var code = codes[c];
      if (volumeCode && code === volumeCode) continue;
      if (code === draftPropertyCode) {
        payload[code] = { value: String(draftValue || ""), xmlId: "" };
        continue;
      }
      var selected = selectedByProperty[code];
      if (selected == null || String(selected).trim() === "") {
        var committed = committedInputValuesByProperty && committedInputValuesByProperty[code];
        if (Array.isArray(committed) && committed.length) {
          payload[code] = { value: committed.map(function (value) { return normalizeValueToken(value); }).join((delimitersByCode && delimitersByCode[code]) || "x"), xmlId: "" };
          continue;
        }
        return { payload: payload, error: { code: code, message: "Не заполнено обязательное свойство" } };
      }
      var preset = findPresetByInputValue((presetsByCode && presetsByCode[code]) || [], selected);
      payload[code] = preset
        ? { value: String(preset.value != null ? preset.value : preset.xml_id), xmlId: String(preset.xml_id || "") }
        : { value: String(selected == null ? "" : selected), xmlId: "" };
    }
    if (draftPropertyCode && !Object.prototype.hasOwnProperty.call(payload, draftPropertyCode)) {
      payload[draftPropertyCode] = { value: String(draftValue || ""), xmlId: "" };
    }
    return { payload: payload, error: null };
  }

  function parsePositiveInteger(value) {
    var parsed = parseQuantity(value);
    if (!Number.isFinite(parsed) || parsed <= 0) return Number.NaN;
    if (Math.abs(parsed - Math.round(parsed)) > 0.000001) return Number.NaN;
    return Math.round(parsed);
  }

  function resolvePresetQuantity(preset) {
    if (!preset) return Number.NaN;
    var byValue = parsePositiveInteger(preset.value);
    if (Number.isFinite(byValue)) return byValue;
    var byQuantity = parsePositiveInteger(preset.quantity);
    if (Number.isFinite(byQuantity)) return byQuantity;
    return parsePositiveInteger(preset.xml_id);
  }


  function deriveStepFromPresets(presets) {
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

  function buildAllowedVolumeNumbers(baseValues, minValue, maxValue, currentValue, stepValue, tailStepValue, tailLimitValue) {
    var minV = Number.isFinite(parseQuantity(minValue)) ? parseQuantity(minValue) : 1;
    var maxV = Number.isFinite(parseQuantity(maxValue)) ? parseQuantity(maxValue) : Number.POSITIVE_INFINITY;
    var current = parseQuantity(currentValue);
    var step = parseQuantity(stepValue);
    var tailStep = parseQuantity(tailStepValue);
    var tailLimit = parseQuantity(tailLimitValue);
    var seen = {};
    var list = (Array.isArray(baseValues) ? baseValues : [])
      .map(parseQuantity)
      .filter(function (value) { return Number.isFinite(value) && value > 0; });
    list.sort(function (a, b) { return a - b; });

    var lastBase = list.length ? list[list.length - 1] : Number.NaN;
    if (Number.isFinite(lastBase) && Number.isFinite(tailStep) && tailStep > 0) {
      var tailMax = Number.isFinite(maxV) ? maxV : (Number.isFinite(tailLimit) && tailLimit > lastBase ? tailLimit : 1000000);
      for (var tail = lastBase + tailStep; tail <= tailMax; tail += tailStep) {
        list.push(tail);
      }
    }
    if (Number.isFinite(current)) list.push(current);

    return list.filter(function (value) {
      if (!Number.isFinite(value) || value < minV || value > maxV || seen[value]) return false;
      if (Number.isFinite(step) && step > 0) {
        var ratio = (value - minV) / step;
        if (Math.abs(ratio - Math.round(ratio)) > 0.000001) return false;
      }
      seen[value] = true;
      return true;
    }).sort(function (a, b) { return a - b; });
  }

  function pickFiveCentered(list, currentValue) {
    var values = Array.isArray(list) ? list : [];
    if (values.length <= 5) return values.slice();
    var current = parseQuantity(currentValue);
    var idx = values.indexOf(current);
    if (idx < 0) {
      idx = 0;
      for (var i = 0; i < values.length; i++) {
        if (values[i] <= current) idx = i;
      }
    }
    var start = idx - 2;
    if (idx >= values.length - 2) start = values.length - 5;
    start = Math.max(0, Math.min(start, values.length - 5));
    return values.slice(start, start + 5);
  }

  function roundCatalogPrice(value, rules) {
    var price = parseQuantity(value);
    if (!Number.isFinite(price) || price <= 0) return price;
    var rows = (Array.isArray(rules) ? rules : []).slice().sort(function (a, b) {
      return parseQuantity(b && b.price) - parseQuantity(a && a.price);
    });
    var rule = null;
    for (var i = 0; i < rows.length; i++) {
      var threshold = parseQuantity(rows[i] && rows[i].price);
      if (Number.isFinite(threshold) && threshold < price) {
        rule = rows[i];
        break;
      }
    }
    if (!rule) return price;

    var precision = Math.abs(parseQuantity(rule.precision));
    var type = Math.round(parseQuantity(rule.type));
    if (!Number.isFinite(precision) || precision === 0) return price;
    if (precision >= 1) precision = Math.round(precision);

    var eps = 0.000001;
    function roundWhole(number, unit) {
      var quotient = number / unit;
      var quotientFloor = Math.floor(quotient);
      if (type === 2) {
        if ((quotient - quotientFloor) > eps) quotientFloor += 1;
      } else if (type === 4) {
        if (quotientFloor < Math.floor((number + eps) / unit)) quotientFloor += 1;
      } else if ((quotient - quotientFloor + eps) >= 0.5) {
        quotientFloor += 1;
      }
      return quotientFloor * unit;
    }

    if (Math.abs(price) <= eps) return 0;
    if (precision >= 1) return roundWhole(price, precision);
    var floor = Math.floor(price);
    var fraction = price - floor;
    return fraction <= eps ? price : floor + roundWhole(fraction, precision);
  }

  function resolveCurrentTargetQuantity(selectedByProperty, presetsByCode, offers, volumeCode, customVolumeValue) {
    var custom = parsePositiveInteger(customVolumeValue);
    if (Number.isFinite(custom)) return custom;

    var selectedToken = selectedByProperty && selectedByProperty[volumeCode];
    var preset = findPresetByInputValue((presetsByCode && presetsByCode[volumeCode]) || [], selectedToken);
    var presetQuantity = resolvePresetQuantity(preset);
    if (Number.isFinite(presetQuantity)) return presetQuantity;

    var normalizedSelected = normalizeValueToken(selectedToken);
    for (var i = 0; i < (Array.isArray(offers) ? offers : []).length; i++) {
      var offer = offers[i] || {};
      var prop = offer.properties && offer.properties[volumeCode];
      if (normalizedSelected && getOfferPropertyToken(prop) !== normalizedSelected) continue;
      var offerQuantity = parsePositiveInteger(offer.quantity);
      if (Number.isFinite(offerQuantity)) return offerQuantity;
    }

    return parsePositiveInteger(selectedToken);
  }

  function validateInputComponents(values, fields) {
    var list = Array.isArray(values) ? values : [];
    var configs = Array.isArray(fields) ? fields : [];
    if (list.length !== configs.length) {
      return { valid: false, message: "Проверьте количество значений" };
    }
    for (var i = 0; i < configs.length; i++) {
      var field = configs[i] || {};
      var raw = list[i];
      if (normalizeValueToken(raw) === "") return { valid: false, message: "Заполните значение" };
      var value = parseQuantity(raw);
      if (!Number.isFinite(value)) return { valid: false, message: "Введите число" };
      var min = parseQuantity(field.min);
      var max = parseQuantity(field.max);
      var step = parseQuantity(field.step);
      if (Number.isFinite(min) && value < min) return { valid: false, message: "Значение меньше минимума" };
      if (Number.isFinite(max) && value > max) return { valid: false, message: "Значение больше максимума" };
      if (Number.isFinite(step) && step > 0) {
        var anchor = Number.isFinite(min) ? min : 0;
        var ratio = (value - anchor) / step;
        if (Math.abs(ratio - Math.round(ratio)) > 0.000001) {
          return { valid: false, message: "Проверьте шаг значения" };
        }
      }
    }
    return { valid: true, message: "" };
  }

  function canonicalizeInputComponents(values, fields) {
    var validation = validateInputComponents(values, fields);
    if (!validation.valid) return { valid: false, message: validation.message, values: [] };
    return {
      valid: true,
      message: "",
      values: (Array.isArray(values) ? values : []).map(function (raw) {
        var value = parseQuantity(raw);
        if (!Number.isFinite(value)) return "";
        return String(value);
      })
    };
  }

  function calculateAreaMm2FromValue(rawValue) {
    var text = String(rawValue || "")
      .replace(/[×*хХ]/g, "x")
      .replace(/,/g, ".");
    // Preserve whitespace: "A6 105x148" must not become "A6105x148".
    var match = text.match(/(?:^|[^\d.])(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)(?:[^\d.]|$)/i);
    if (!match) return Number.NaN;
    var width = parseQuantity(match[1]);
    var height = parseQuantity(match[2]);
    return Number.isFinite(width) && Number.isFinite(height) ? width * height : Number.NaN;
  }

  function canAddToBasketWithDraft(pendingPropertyCode, customRequestLoading) {
    if (customRequestLoading) return { allowed: false, reason: "loading" };
    if (pendingPropertyCode) return { allowed: false, reason: "pending", code: pendingPropertyCode };
    return { allowed: true, reason: "" };
  }

  function restoreDisabledAfterBusy(wasDisabledBeforeBusy) {
    return wasDisabledBeforeBusy === true || wasDisabledBeforeBusy === "Y";
  }

  function getOfferPropertyToken(property) {
    if (!property) return "";
    return normalizeValueToken(property.xmlId != null ? property.xmlId : (property.xml_id != null ? property.xml_id : property.value));
  }

  function readPropertyValue(property, key) {
    return property && Object.prototype.hasOwnProperty.call(property, key) ? property[key] : undefined;
  }

  function resolveOfferQuantity(offer, volumeCode) {
    var props = (offer && offer.properties) || {};
    var property = props[volumeCode] || {};
    var candidates = [
      offer && offer.quantity,
      readPropertyValue(property, "value"),
      readPropertyValue(property, "VALUE"),
      readPropertyValue(property, "xmlId"),
      readPropertyValue(property, "xml_id"),
      readPropertyValue(property, "VALUE_XML_ID")
    ];

    for (var i = 0; i < candidates.length; i++) {
      var quantity = parseQuantity(candidates[i]);
      if (Number.isFinite(quantity) && quantity > 0) return quantity;
    }

    return Number.NaN;
  }

  function offerMatchesSelectionExcept(offer, selectedByProperty, customByProperty, skipCode) {
    var props = (offer && offer.properties) || {};
    for (var code in (selectedByProperty || {})) {
      if (!Object.prototype.hasOwnProperty.call(selectedByProperty, code)) continue;
      if (skipCode && code === skipCode) continue;
      if (customByProperty && customByProperty[code]) return false;
      var selectedToken = normalizeValueToken(selectedByProperty[code]);
      if (!selectedToken) continue;
      if (getOfferPropertyToken(props[code]) !== selectedToken) return false;
    }
    return true;
  }

  function normalizeCurrency(currency) {
    return String(currency || "RUB").trim().toUpperCase();
  }

  function areCurrenciesEqual(left, right) {
    return normalizeCurrency(left) === normalizeCurrency(right);
  }

  function getQuantity(point) {
    return parseQuantity(point && (point.quantity !== undefined ? point.quantity : point.volume));
  }

  function getValue(point) {
    var raw = point && (point.value !== undefined ? point.value : point.price);
    var num = parseQuantity(raw);
    return Number.isFinite(num) ? num : Number.NaN;
  }

  function isBitrixPoint(point) {
    var offer = point && point.offer;
    return !(offer && (offer.is_virtual || offer.isVirtual || offer.source === "calc-server"));
  }

  function selectLinearPoints(points, targetQuantity) {
    var target = parseQuantity(targetQuantity);
    var sorted = (Array.isArray(points) ? points : []).filter(function (point) {
      return Number.isFinite(getQuantity(point));
    }).slice().sort(function (a, b) {
      var diff = getQuantity(a) - getQuantity(b);
      if (diff !== 0) return diff;
      if (isBitrixPoint(a) !== isBitrixPoint(b)) return isBitrixPoint(a) ? -1 : 1;
      return 0;
    });

    if (!sorted.length || !Number.isFinite(target)) return [];

    var unique = [];
    for (var i = 0; i < sorted.length; i++) {
      if (!unique.length || getQuantity(unique[unique.length - 1]) !== getQuantity(sorted[i])) {
        unique.push(sorted[i]);
      }
    }

    for (var exactIndex = 0; exactIndex < unique.length; exactIndex++) {
      if (getQuantity(unique[exactIndex]) === target) {
        return [unique[exactIndex]];
      }
    }

    if (unique.length === 1) return [unique[0]];

    if (target < getQuantity(unique[0])) return [unique[0], unique[1]];
    if (target > getQuantity(unique[unique.length - 1])) return [unique[unique.length - 2], unique[unique.length - 1]];

    var left = null;
    var right = null;
    for (var j = 0; j < unique.length; j++) {
      var quantity = getQuantity(unique[j]);
      if (quantity < target) left = unique[j];
      if (quantity > target) {
        right = unique[j];
        break;
      }
    }

    return left && right ? [left, right] : [unique[0]];
  }

  function interpolateLinear(left, right, targetQuantity) {
    if (!left) return Number.NaN;
    var leftQuantity = getQuantity(left);
    var leftValue = getValue(left);
    if (!right) return Number.isFinite(leftValue) ? Math.max(0, leftValue) : Number.NaN;

    var rightQuantity = getQuantity(right);
    var rightValue = getValue(right);
    var target = parseQuantity(targetQuantity);
    if (!Number.isFinite(leftQuantity) || !Number.isFinite(rightQuantity) || leftQuantity === rightQuantity) {
      return Number.isFinite(leftValue) ? Math.max(0, leftValue) : Number.NaN;
    }
    if (!Number.isFinite(leftValue) || !Number.isFinite(rightValue) || !Number.isFinite(target)) return Number.NaN;

    return Math.max(0, leftValue + (target - leftQuantity) * (rightValue - leftValue) / (rightQuantity - leftQuantity));
  }

  function resolveLinearMode(points, targetQuantity) {
    var selected = selectLinearPoints(points, targetQuantity);
    var target = parseQuantity(targetQuantity);
    if (!selected.length || !Number.isFinite(target)) return { mode: "", points: [] };
    if (selected.length === 1) {
      return { mode: getQuantity(selected[0]) === target ? "exact" : "single", points: selected };
    }
    var first = getQuantity(selected[0]);
    var last = getQuantity(selected[selected.length - 1]);
    return { mode: target > first && target < last ? "interpolated" : "extrapolated", points: selected };
  }

  function hasInternalData(offer) {
    var internal = offer && offer.internal;
    return internal && typeof internal === "object";
  }

  function buildInternalViewModel(selectedPoints, targetQuantity) {
    var resolved = resolveLinearMode(selectedPoints, targetQuantity);
    return {
      mode: resolved.mode,
      targetQuantity: parseQuantity(targetQuantity),
      sources: resolved.points.map(function (point) {
        var offer = point && point.offer ? point.offer : {};
        var internal = hasInternalData(offer) ? offer.internal : {};
        return {
          quantity: getQuantity(point),
          source: String(offer.source || (offer.isVirtual || offer.is_virtual ? "calc-server" : "Bitrix")),
          directPurchasePrice: Object.prototype.hasOwnProperty.call(internal, "directPurchasePrice") ? internal.directPurchasePrice : null,
          purchasePrice: Object.prototype.hasOwnProperty.call(internal, "purchasePrice") ? internal.purchasePrice : null,
          currency: internal.currency || "RUB",
          parametrValues: internal.parametrValues && typeof internal.parametrValues === "object" && !Array.isArray(internal.parametrValues) ? internal.parametrValues : {},
          hasInternal: hasInternalData(offer)
        };
      })
    };
  }

  function stringifyParametrValue(value) {
    if (value === null || typeof value === "undefined") return "—";
    if (typeof value === "boolean") return value ? "Да" : "Нет";
    if (typeof value === "string" || typeof value === "number") return String(value);
    try { return JSON.stringify(value); } catch (e) { return "—"; }
  }

  function buildParametrValueRows(sources) {
    var list = Array.isArray(sources) ? sources : [];
    var keys = [];
    var seen = Object.create(null);
    list.forEach(function (source) {
      var values = source && source.parametrValues && typeof source.parametrValues === "object" ? source.parametrValues : {};
      Object.keys(values).forEach(function (key) {
        if (Object.prototype.hasOwnProperty.call(seen, key)) return;
        seen[key] = true;
        keys.push(key);
      });
    });
    return keys.map(function (key) {
      return {
        key: key,
        values: list.map(function (source) {
          var values = source && source.parametrValues && typeof source.parametrValues === "object" ? source.parametrValues : {};
          return Object.prototype.hasOwnProperty.call(values, key) ? stringifyParametrValue(values[key]) : "—";
        })
      };
    });
  }

  function shouldShowInternalPanel(access, sources) {
    var permissions = (access && access.permissions) || {};
    if (permissions.can_view_internal_calculation_data !== true) return false;
    return (Array.isArray(sources) ? sources : []).some(function (source) { return source && source.hasInternal === true; });
  }

  function getDeadlineAdjustment(settingsOrConfig, type, quantity) {
    var settings = (settingsOrConfig && (settingsOrConfig.deadline_adjustments || settingsOrConfig)) || {};
    var key = type === "urgent" ? "urgent_markup" : (type === "flexible" ? "flexible_discount" : "");
    if (!key) return 0;
    var mode = String(settings.mode || "simple");
    if (mode === "advanced" && settings.advanced && Array.isArray(settings.advanced[key])) {
      var target = parseQuantity(quantity);
      var rows = settings.advanced[key].filter(function (row) {
        return Number.isFinite(parseQuantity(row && row.volume));
      }).slice().sort(function (a, b) { return parseQuantity(a && a.volume) - parseQuantity(b && b.volume); });
      var result = Number.NaN;
      rows.forEach(function (row) {
        var volume = parseQuantity(row && row.volume);
        var percent = parseQuantity(row && row.percent);
        if (Number.isFinite(target) && target >= volume && Number.isFinite(percent)) result = percent;
      });
      return Number.isFinite(result) ? result : 0;
    }
    var simple = parseQuantity(settings[key]);
    return Number.isFinite(simple) ? simple : 0;
  }

  function getOfferMergeKey(offer) {
    var explicit = String((offer && (offer.offerKey || offer.offer_key || offer.key)) || "").trim();
    if (explicit) return explicit;

    var props = (offer && offer.properties) || {};
    var parts = Object.keys(props).sort().map(function (code) {
      return code + "=" + getOfferPropertyToken(props[code]);
    }).filter(function (part) {
      return part.slice(-1) !== "=";
    });
    if (parts.length) return parts.join("|");

    var id = parseQuantity(offer && offer.id);
    return Number.isFinite(id) && id > 0 ? "id:" + String(offer.id) : "";
  }

  function mergeOffersByOfferKey(currentOffers, incomingOffers) {
    var merged = [];
    var indexByKey = {};

    function appendOrReplace(offer) {
      var key = getOfferMergeKey(offer);
      if (key && Object.prototype.hasOwnProperty.call(indexByKey, key)) {
        merged[indexByKey[key]] = offer;
        return;
      }
      if (key) indexByKey[key] = merged.length;
      merged.push(offer);
    }

    (Array.isArray(currentOffers) ? currentOffers : []).forEach(appendOrReplace);
    (Array.isArray(incomingOffers) ? incomingOffers : []).forEach(appendOrReplace);
    return merged;
  }

  function reducerResetPendingDraft(state, nextPropertyCode, volumeCode) {
    var pending = state && state.pendingPropertyCode ? String(state.pendingPropertyCode) : "";
    var next = String(nextPropertyCode || "");
    var volume = String(volumeCode || "CALC_PROP_VOLUME");
    if (!pending || pending === next) return state;
    var copy = Object.assign({}, state);
    copy.pendingPropertyCode = "";
    copy.resetPropertyCode = pending;
    copy.volumeDoesNotRequireConfirmation = next === volume;
    return copy;
  }

  function reducerApplyPresetDraft(state, code, presetXmlId) {
    var copy = {
      selectedByProperty: Object.assign({}, (state && state.selectedByProperty) || {}),
      customByProperty: Object.assign({}, (state && state.customByProperty) || {}),
      ajaxCalled: false,
      pendingPropertyCode: ""
    };
    copy.selectedByProperty[code] = presetXmlId;
    copy.customByProperty[code] = false;
    return copy;
  }

  function reducerApplyCustomError(state, message) {
    var copy = Object.assign({}, state || {});
    copy.error = message || "Ошибка расчёта";
    return copy;
  }

  function reducerApplyCustomSuccess(state, code, draftValue) {
    var copy = {
      selectedByProperty: Object.assign({}, (state && state.selectedByProperty) || {}),
      customByProperty: Object.assign({}, (state && state.customByProperty) || {}),
      pendingPropertyCode: "",
      error: ""
    };
    copy.selectedByProperty[code] = draftValue;
    copy.customByProperty[code] = false;
    return copy;
  }

  return {
    normalizeValueToken: normalizeValueToken,
    findPresetByInputValue: findPresetByInputValue,
    getPresetInputParts: getPresetInputParts,
    buildCustomSelectionPayload: buildCustomSelectionPayload,
    resolvePresetQuantity: resolvePresetQuantity,
    deriveStepFromPresets: deriveStepFromPresets,
    buildAllowedVolumeNumbers: buildAllowedVolumeNumbers,
    pickFiveCentered: pickFiveCentered,
    roundCatalogPrice: roundCatalogPrice,
    resolveCurrentTargetQuantity: resolveCurrentTargetQuantity,
    validateInputComponents: validateInputComponents,
    canonicalizeInputComponents: canonicalizeInputComponents,
    calculateAreaMm2FromValue: calculateAreaMm2FromValue,
    canAddToBasketWithDraft: canAddToBasketWithDraft,
    restoreDisabledAfterBusy: restoreDisabledAfterBusy,
    getOfferPropertyToken: getOfferPropertyToken,
    resolveOfferQuantity: resolveOfferQuantity,
    offerMatchesSelectionExcept: offerMatchesSelectionExcept,
    normalizeCurrency: normalizeCurrency,
    areCurrenciesEqual: areCurrenciesEqual,
    selectLinearPoints: selectLinearPoints,
    interpolateLinear: interpolateLinear,
    resolveLinearMode: resolveLinearMode,
    buildInternalViewModel: buildInternalViewModel,
    buildParametrValueRows: buildParametrValueRows,
    shouldShowInternalPanel: shouldShowInternalPanel,
    getDeadlineAdjustment: getDeadlineAdjustment,
    getOfferMergeKey: getOfferMergeKey,
    mergeOffersByOfferKey: mergeOffersByOfferKey,
    reducerResetPendingDraft: reducerResetPendingDraft,
    reducerApplyPresetDraft: reducerApplyPresetDraft,
    reducerApplyCustomError: reducerApplyCustomError,
    reducerApplyCustomSuccess: reducerApplyCustomSuccess
  };
});
