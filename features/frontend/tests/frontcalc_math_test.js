'use strict';

const math = require('../assets/js/frontcalc-math.js');
const fs = require('fs');

function assertClose(actual, expected, label) {
  if (Math.abs(actual - expected) > 0.000001) {
    throw new Error(label + ': expected ' + expected + ', got ' + actual);
  }
}

function assertEqual(actual, expected, label) {
  if (actual !== expected) {
    throw new Error(label + ': expected ' + expected + ', got ' + actual);
  }
}

function calculate(points, target) {
  const selected = math.selectLinearPoints(points, target);
  if (selected.length === 1) return math.interpolateLinear(selected[0], null, target);
  return math.interpolateLinear(selected[0], selected[1], target);
}

const pricePoints = [
  { quantity: 100, value: 1000 },
  { quantity: 200, value: 1800 }
];
assertClose(calculate(pricePoints, 50), 600, 'price extrapolate below');
assertClose(calculate(pricePoints, 150), 1400, 'price interpolate');
assertClose(calculate(pricePoints, 250), 2200, 'price extrapolate above');
assertClose(calculate(pricePoints, 100), 1000, 'exact price');
assertClose(calculate([{ quantity: 100, value: 1000 }], 500), 1000, 'single point');

const weightPoints = [
  { quantity: 100, value: 1.00 },
  { quantity: 200, value: 1.80 }
];
assertClose(calculate(weightPoints, 50), 0.60, 'weight below');
assertClose(calculate(weightPoints, 150), 1.40, 'weight middle');
assertClose(calculate(weightPoints, 250), 2.20, 'weight above');

const volumePoints = [
  { quantity: 100, value: 0.00100 },
  { quantity: 200, value: 0.00180 }
];
assertClose(calculate(volumePoints, 50), 0.00060, 'volume below');
assertClose(calculate(volumePoints, 150), 0.00140, 'volume middle');
assertClose(calculate(volumePoints, 250), 0.00220, 'volume above');

assertEqual(math.resolveOfferQuantity({
  quantity: 1000,
  properties: { CALC_PROP_VOLUME: { value: '1000', xmlId: 'VOLUME_1000' } }
}, 'CALC_PROP_VOLUME'), 1000, 'canonical offer.quantity');

assertEqual(math.resolveOfferQuantity({
  properties: { CALC_PROP_VOLUME: { value: '1 000', xmlId: 'VOLUME_1000' } }
}, 'CALC_PROP_VOLUME'), 1000, 'quantity fallback by value');

if (Number.isFinite(math.resolveOfferQuantity({
  quantity: 0,
  properties: { CALC_PROP_VOLUME: { value: '0' } }
}, 'CALC_PROP_VOLUME'))) {
  throw new Error('Non-positive quantity must be rejected');
}

const selectedByProperty = { CALC_PROP_FORMAT: '215x305', CALC_PROP_COLOR: 'red', CALC_PROP_VOLUME: '100' };
const matchingOffer = {
  properties: {
    CALC_PROP_FORMAT: { value: '215x305' },
    CALC_PROP_COLOR: { xml_id: 'red' },
    CALC_PROP_VOLUME: { value: '100' }
  }
};

assertEqual(math.offerMatchesSelectionExcept(
  matchingOffer,
  selectedByProperty,
  { CALC_PROP_VOLUME: true },
  'CALC_PROP_VOLUME'
), true, 'only custom volume may be skipped for linear calculation');

assertEqual(math.offerMatchesSelectionExcept(
  matchingOffer,
  selectedByProperty,
  { CALC_PROP_FORMAT: true },
  'CALC_PROP_VOLUME'
), false, 'custom non-volume property must not match');

const metricsWhenCustomFormat = math.offerMatchesSelectionExcept(
  matchingOffer,
  selectedByProperty,
  { CALC_PROP_FORMAT: true },
  'CALC_PROP_VOLUME'
) ? { weightKg: 1, volumeM3: 0.001 } : { weightKg: null, volumeM3: null };
assertEqual(metricsWhenCustomFormat.weightKg, null, 'custom format weight');
assertEqual(metricsWhenCustomFormat.volumeM3, null, 'custom format volume');

const virtualWithoutColor = { source: 'calc-server', isVirtual: true, properties: { CALC_PROP_VOLUME: { xml_id: '100' } } };
assertEqual(math.offerMatchesSelectionExcept(
  virtualWithoutColor,
  { CALC_PROP_COLOR: 'red', CALC_PROP_VOLUME: '100' },
  {},
  'CALC_PROP_VOLUME'
), false, 'virtual calc-server offer without selected property must not match');

assertEqual(math.normalizeCurrency(' rub '), 'RUB', 'normalize currency');
assertEqual(math.areCurrenciesEqual('rub', 'RUB'), true, 'same currency with different case/space');
assertEqual(math.areCurrenciesEqual('RUB', 'USD'), false, 'different currencies');

const mergedOffers = math.mergeOffersByOfferKey(
  [
    { id: -1, offerKey: 'format=55x55|volume=100', price: 10 },
    { id: 101, offerKey: 'format=90x50|volume=100', price: 20 }
  ],
  [
    { id: -2, offerKey: 'format=55x55|volume=100', price: 15 },
    { id: -3, offerKey: 'format=70x70|volume=100', price: 30 }
  ]
);
assertEqual(mergedOffers.length, 3, 'merge offers by offerKey keeps unique keys');
assertEqual(mergedOffers[0].price, 15, 'same offerKey replaces old offer');
assertEqual(mergedOffers[2].id, -3, 'different negative ids are not merge keys');

const propertyKeyA = math.getOfferMergeKey({
  id: -10,
  properties: { CALC_PROP_FORMAT: { xml_id: '55x55' }, CALC_PROP_VOLUME: { xml_id: '100' } }
});
const propertyKeyB = math.getOfferMergeKey({
  id: -11,
  properties: { CALC_PROP_VOLUME: { xml_id: '100' }, CALC_PROP_FORMAT: { xml_id: '55x55' } }
});
assertEqual(propertyKeyA, propertyKeyB, 'offerKey fallback ignores negative id and uses properties');

const resetState = math.reducerResetPendingDraft({ pendingPropertyCode: 'CALC_PROP_FORMAT' }, 'CALC_PROP_COLOR', 'CALC_PROP_VOLUME');
assertEqual(resetState.resetPropertyCode, 'CALC_PROP_FORMAT', 'changing another property resets pending draft');

const volumeResetState = math.reducerResetPendingDraft({ pendingPropertyCode: 'CALC_PROP_FORMAT' }, 'CALC_PROP_VOLUME', 'CALC_PROP_VOLUME');
assertEqual(volumeResetState.volumeDoesNotRequireConfirmation, true, 'CALC_PROP_VOLUME does not require confirmation');

assertEqual(volumeResetState.resetPropertyCode, 'CALC_PROP_FORMAT', 'manual volume input change resets pending draft for another property');
assertEqual(volumeResetState.pendingPropertyCode, '', 'manual volume input change leaves no pending volume confirmation');

const presetState = math.reducerApplyPresetDraft({
  selectedByProperty: { CALC_PROP_FORMAT: 'old' },
  customByProperty: { CALC_PROP_FORMAT: true }
}, 'CALC_PROP_FORMAT', '55x55');
assertEqual(presetState.ajaxCalled, false, 'preset match does not call AJAX');
assertEqual(presetState.selectedByProperty.CALC_PROP_FORMAT, '55x55', 'preset match commits preset');

const beforeError = {
  selectedByProperty: { CALC_PROP_FORMAT: '55x55' },
  customByProperty: { CALC_PROP_FORMAT: false }
};
const afterError = math.reducerApplyCustomError(beforeError, 'bad value');
assertEqual(afterError.selectedByProperty.CALC_PROP_FORMAT, '55x55', 'calculate_custom error keeps committed state');

const successState = math.reducerApplyCustomSuccess(beforeError, 'CALC_PROP_FORMAT', '66x66');
assertEqual(successState.selectedByProperty.CALC_PROP_FORMAT, '66x66', 'calculate_custom success commits draft');
assertEqual(successState.customByProperty.CALC_PROP_FORMAT, false, 'calculate_custom success clears custom flag');

const payload = math.buildCustomSelectionPayload(
  {
    CALC_PROP_FORMAT: '50x60',
    CALC_PROP_COLOR: '4+0',
    CALC_PROP_DENSITY: 'PAPER_150',
    CALC_PROP_CUSTOM: '60x70',
    CALC_PROP_VOLUME: 'VOLUME_30000'
  },
  {
    CALC_PROP_COLOR: [{ xml_id: '4+0', value: '4+0' }],
    CALC_PROP_DENSITY: [{ xml_id: 'PAPER_150', value: 'Мелованная бумага 150 г' }],
    CALC_PROP_VOLUME: [{ xml_id: 'VOLUME_30000', value: '30 000' }]
  },
  'CALC_PROP_FORMAT',
  '60x70',
  'CALC_PROP_VOLUME'
).payload;
assertEqual(typeof payload.CALC_PROP_FORMAT, 'object', 'selected_values uses nested objects');
assertEqual(payload.CALC_PROP_DENSITY.value, 'Мелованная бумага 150 г', 'known preset payload value');
assertEqual(payload.CALC_PROP_DENSITY.xmlId, 'PAPER_150', 'known preset payload xmlId');
assertEqual(payload.CALC_PROP_CUSTOM.value, '60x70', 'custom committed payload value');
assertEqual(payload.CALC_PROP_CUSTOM.xmlId, '', 'custom committed payload has empty xmlId');
assertEqual(payload.CALC_PROP_FORMAT.value, '60x70', 'draft overrides committed payload value');
assertEqual(payload.CALC_PROP_FORMAT.xmlId, '', 'draft payload has empty xmlId');

assertEqual(math.resolveCurrentTargetQuantity(
  { CALC_PROP_VOLUME: 'VOLUME_30000' },
  { CALC_PROP_VOLUME: [{ xml_id: 'VOLUME_30000', value: '30 000' }] },
  [],
  'CALC_PROP_VOLUME',
  Number.NaN
), 30000, 'opaque volume XML_ID resolves via preset.value');

assertEqual(math.resolveCurrentTargetQuantity(
  { CALC_PROP_VOLUME: 'VOLUME_100' },
  {},
  [{ quantity: 100, properties: { CALC_PROP_VOLUME: { xml_id: 'VOLUME_100' } } }],
  'CALC_PROP_VOLUME',
  Number.NaN
), 100, 'target quantity resolves via matching offer.quantity');

const a4Parts = math.getPresetInputParts({ xml_id: 'A4', value: '210x297' }, 'x');
assertEqual(a4Parts[0], '210', 'preset.value first input part');
assertEqual(a4Parts[1], '297', 'preset.value second input part');

const labelledA3Parts = math.getPresetInputParts({ xml_id: '297x420', value: 'A3 297×420мм' }, 'x');
assertEqual(labelledA3Parts[0], '297', 'numeric composite XML_ID restores committed first input');
assertEqual(labelledA3Parts[1], '420', 'numeric composite XML_ID restores committed second input');

const restoredAfterOtherChip = labelledA3Parts.slice();
assertEqual(restoredAfterOtherChip[0], '297', 'unconfirmed format draft reset restores first committed component');
assertEqual(restoredAfterOtherChip[1], '420', 'unconfirmed format draft reset restores second committed component');

assertEqual(math.validateInputComponents(['55'], [{ min: 50, step: 5 }]).valid, true, 'step allows 55 from min 50 by 5');
assertEqual(math.validateInputComponents(['57'], [{ min: 50, step: 5 }]).valid, false, 'step rejects 57 from min 50 by 5');

assertEqual(math.resolvePresetQuantity({ xml_id: 'VOLUME_30000', value: '30 000' }), 30000, 'resolve preset quantity from formatted value');
assertEqual(math.deriveStepFromPresets([
  { xml_id: 'VOLUME_30000', value: '30 000' },
  { xml_id: 'VOLUME_35000', value: '35 000' },
  { xml_id: 'VOLUME_40000', value: '40 000' }
]), 5000, 'derive step from formatted preset values');
assertEqual(math.deriveStepFromPresets([
  { xml_id: 'VOLUME_30000', value: '30 000' },
  { xml_id: 'VOLUME_30000_COPY', value: '30 000' },
  { xml_id: 'VOLUME_35000', value: '35 000' }
]), 5000, 'derive step ignores duplicate quantities');
assertEqual(math.deriveStepFromPresets([
  { xml_id: 'VOLUME_30000', value: '30 000' }
]), 1, 'derive step falls back with one valid quantity');
assertEqual(math.deriveStepFromPresets([
  { xml_id: '123', value: '30 000' },
  { xml_id: '124', value: '35 000' }
]), 5000, 'derive step prefers valid preset value over numeric opaque XML_ID');

const standardGrid = [1,2,3,4,5,10,15,20,30,40,50,100,150,200,300,400,500,1000,1500,2000,3000,4000,5000,10000,15000,20000,30000,40000,50000,100000,150000,200000];
function volumeWindow(current) {
  return math.pickFiveCentered(math.buildAllowedVolumeNumbers(standardGrid, 100, 200000, current, 100, 50000, 1000000), current);
}
assertDeepEqual(volumeWindow(5500), [4000, 5000, 5500, 10000, 15000], 'custom 5500 is centered between base grid values');
assertDeepEqual(volumeWindow(10000), [4000, 5000, 10000, 15000, 20000], 'base 10000 becomes center value');
assertDeepEqual(volumeWindow(10100), [5000, 10000, 10100, 15000, 20000], 'custom 10100 keeps nearest base neighbors');
assertDeepEqual(
  math.buildAllowedVolumeNumbers([100, 150, 200, 300, 400, 500], 100, 500, 400, 300, 50000, 1000000),
  [100, 400],
  'grid values are filtered by calculator step anchored at minimum'
);
assertEqual(volumeWindow(5500).includes(5400), false, 'micro-step neighbor is not injected into the table');
assertEqual(volumeWindow(5500).includes(5600), false, 'positive micro-step neighbor is not injected into the table');

assertEqual(math.roundCatalogPrice(9446.4, [{ price: 0, type: 1, precision: 1 }]), 9446, 'Bitrix math rounding rule');
assertEqual(math.roundCatalogPrice(9446.01, [{ price: 0, type: 2, precision: 1 }]), 9447, 'Bitrix upward rounding rule');
assertEqual(math.roundCatalogPrice(9446.99, [{ price: 0, type: 4, precision: 1 }]), 9446, 'Bitrix downward rounding rule');
assertEqual(math.roundCatalogPrice(100, [{ price: 100, type: 2, precision: 10 }]), 100, 'rounding threshold is strict like Bitrix');
assertEqual(math.calculateAreaMm2FromValue('A4 (210×297 мм)'), 62370, 'area parser extracts A4 dimensions');

assertEqual(math.calculateAreaMm2FromValue('A6 105x148 mm'), 15540, 'area parser does not merge format index with width');
assertEqual(math.calculateAreaMm2FromValue('A6 105×148 мм'), 15540, 'area parser supports multiplication sign in labeled format');

const canonical = math.canonicalizeInputComponents(['055', '55.0', '55,50'], [{}, {}, {}]);
assertEqual(canonical.values[0], '55', 'canonical removes leading zeroes');
assertEqual(canonical.values[1], '55', 'canonical removes insignificant fraction');
assertEqual(canonical.values[2], '55.5', 'canonical normalizes decimal comma');

if (Number.isFinite(math.resolvePresetQuantity({ value: '12.5' }))) {
  throw new Error('Fractional target quantity must not be rounded silently');
}

const inputOnlyResult = math.buildCustomSelectionPayload(
  { CALC_PROP_COLOR: '4+0' },
  { CALC_PROP_COLOR: [{ xml_id: '4+0', value: '4+0' }] },
  'CALC_PROP_FORMAT',
  '60x70',
  'CALC_PROP_VOLUME',
  ['CALC_PROP_FORMAT', 'CALC_PROP_COLOR', 'CALC_PROP_SIZE'],
  { CALC_PROP_SIZE: ['210', '297'] },
  { CALC_PROP_SIZE: 'x' }
);
assertEqual(inputOnlyResult.payload.CALC_PROP_SIZE.value, '210x297', 'payload includes input-only committed property');
assertEqual(inputOnlyResult.payload.CALC_PROP_SIZE.xmlId, '', 'input-only committed property is custom');

const missingResult = math.buildCustomSelectionPayload(
  {},
  {},
  '',
  '',
  'CALC_PROP_VOLUME',
  ['CALC_PROP_REQUIRED'],
  {},
  {}
);
assertEqual(missingResult.error.code, 'CALC_PROP_REQUIRED', 'payload returns error for missing required property');

assertEqual(math.canAddToBasketWithDraft('CALC_PROP_FORMAT', false).allowed, false, 'pending draft blocks basket');
assertEqual(math.restoreDisabledAfterBusy('Y'), true, 'disabled element remains disabled after busy');
assertEqual(math.restoreDisabledAfterBusy('N'), false, 'enabled element is restored enabled after busy');

function assertDeepEqual(actual, expected, label) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a !== e) throw new Error(label + ': expected ' + e + ', got ' + a);
}

const sourcePoints = [
  { quantity: 1000, value: 100, offer: { source: 'calc-server', isVirtual: true, internal: { directPurchasePrice: 800, purchasePrice: 1000, currency: 'RUB', parametrValues: { 'Вес бумаги до подрезки': '12.5 кг' } } } },
  { quantity: 2000, value: 190, offer: { source: 'calc-server', isVirtual: true, internal: { directPurchasePrice: 1400, purchasePrice: 1750, currency: 'RUB', parametrValues: { 'Количество листов': '250' } } } },
  { quantity: 3000, value: 270, offer: { source: 'bitrix', isVirtual: false } }
];
assertEqual(math.resolveLinearMode(sourcePoints, 1000).mode, 'exact', 'exact source point mode');
assertEqual(math.resolveLinearMode(sourcePoints, 1000).points.length, 1, 'exact source point count');
assertEqual(math.resolveLinearMode(sourcePoints, 1500).mode, 'interpolated', 'interpolated source point mode');
assertDeepEqual(math.resolveLinearMode(sourcePoints, 1500).points.map(p => p.quantity), [1000, 2000], 'interpolation adjacent points');
assertEqual(math.resolveLinearMode(sourcePoints, 500).mode, 'extrapolated', 'below extrapolated mode');
assertDeepEqual(math.resolveLinearMode(sourcePoints, 500).points.map(p => p.quantity), [1000, 2000], 'below extrapolated points');
assertEqual(math.resolveLinearMode(sourcePoints, 4000).mode, 'extrapolated', 'above extrapolated mode');
assertDeepEqual(math.resolveLinearMode(sourcePoints, 4000).points.map(p => p.quantity), [2000, 3000], 'above extrapolated points');
const sameQuantity = math.selectLinearPoints([
  { quantity: 1000, value: 1, offer: { source: 'calc-server', isVirtual: true } },
  { quantity: 1000, value: 2, offer: { source: 'bitrix', isVirtual: false } }
], 1000);
assertEqual(sameQuantity.length, 1, 'same quantity collapsed');
assertEqual(sameQuantity[0].value, 2, 'same quantity uses Bitrix priority');

const internalVm = math.buildInternalViewModel(math.resolveLinearMode(sourcePoints, 1500).points, 1500);
assertEqual(math.shouldShowInternalPanel({ permissions: { can_view_internal_calculation_data: false } }, internalVm.sources), false, 'verified permission forbids internal panel');
assertEqual(math.shouldShowInternalPanel({ scenario: 'extended', permissions: { can_view_internal_calculation_data: true } }, internalVm.sources), true, 'extended permission with internal source allows panel');
assertEqual(math.shouldShowInternalPanel({ permissions: { can_view_internal_calculation_data: true } }, [{ hasInternal: false }]), false, 'extended without internal hides panel');
assertEqual(internalVm.sources[0].parametrValues['Вес бумаги до подрезки'], '12.5 кг', 'cyrillic parametr key preserved');
const rows = math.buildParametrValueRows(internalVm.sources);
assertDeepEqual(rows.map(r => r.key), ['Вес бумаги до подрезки', 'Количество листов'], 'parametr values union order');
assertEqual(rows[0].values[1], '—', 'missing parametr value is dash');
assertEqual(math.buildParametrValueRows([{ parametrValues: { '<b>ключ</b>': '<img src=x>' } }])[0].key, '<b>ключ</b>', 'renderer must escape raw html key');
assertEqual(math.buildParametrValueRows([{ parametrValues: { '<b>ключ</b>': '<img src=x>' } }])[0].values[0], '<img src=x>', 'renderer must escape raw html value');
const zeroInternalVm = math.buildInternalViewModel([{ quantity: 1000, offer: { source: 'calc-server', isVirtual: true, internal: { directPurchasePrice: 0, purchasePrice: 0, currency: 'RUB', parametrValues: {} } } }], 1000);
assertEqual(zeroInternalVm.sources[0].directPurchasePrice, 0, 'zero direct purchase price is preserved');
assertEqual(zeroInternalVm.sources[0].purchasePrice, 0, 'zero purchase price is preserved');
assertDeepEqual(
  math.buildParametrValueRows([
    {
      parametrValues: JSON.parse('{"__proto__":"a","constructor":"b","toString":"c","Обычный":"d"}')
    }
  ]).map(row => row.key),
  ['__proto__', 'constructor', 'toString', 'Обычный'],
  'prototype-like parametr keys are preserved'
);

const popupSource = fs.readFileSync(__dirname + '/../assets/js/frontcalc-jqm-popup.js', 'utf8');
if (popupSource.includes('$price.on("toggle", ".frontcalc-internal-details"')) {
  throw new Error('delegated details toggle handler must not be used');
}
if (!popupSource.includes('$internalDetails.on("shown.bs.collapse.frontcalc"')) {
  throw new Error('Aspro collapse event binding must be present');
}
if (!popupSource.includes('renderInternalPanelAspro(internalViewModel, internalPanelOpen, internalStatus)')) {
  throw new Error('internalPanelOpen must be reused during render');
}
if (!popupSource.includes('controlsByCode[code].chips.find(".is-active").removeClass("is-active")')) {
  throw new Error('manual grouped input must clear the previously active preset chip');
}
if (!popupSource.includes('match(/([\\d\\s\\u00a0]+)\\s*экз') || !popupSource.includes('var displayOffer = calculatedTitleOffer || matched')) {
  throw new Error('calc-server title must take precedence for the matching calculated selection');
}
if (!popupSource.includes('var titleTargetQuantity = Number.isFinite(customVolumeValue) ? customVolumeValue : resolvePresetQuantity(selectedVolumePreset)')) {
  throw new Error('calc-server title must use the explicitly selected volume preset');
}
if (!popupSource.includes('var renderedSelectedQuantity = parseNumber($priceInner.find(".frontcalc-cell.is-picked[data-col]")')) {
  throw new Error('calc-server title must be synchronized with the visibly selected price row');
}
if (!popupSource.includes('formatQuantityValue(renderedSelectedQuantity) + " экз"')) {
  throw new Error('calc-server title quantity must match the visibly selected row');
}

const normalizedDeadline = { mode: 'advanced', advanced: { urgent_markup: [{ volume: 1000, percent: 10 }, { volume: 30000, percent: 20 }] } };
assertEqual(math.getDeadlineAdjustment(normalizedDeadline, 'urgent', 30000), 20, 'advanced normalized deadline uses numeric 30000');
assertEqual(math.getDeadlineAdjustment({ mode: 'advanced', advanced: { urgent_markup: [{ volume: 'QTY_30K', percent: 99 }, { volume: 1000, percent: 10 }] } }, 'urgent', 30000), 10, 'raw XML_ID deadline volume is ignored');
assertClose(1000 * (1 + math.getDeadlineAdjustment(normalizedDeadline, 'urgent', 30000) / 100), 1200, 'deadline adjusted price at 30000');

console.log('frontcalc math tests passed');
