const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '..', 'install', 'assets', 'js', 'integration.js'),
  'utf8',
);
const fakeWindow = {
  location: { origin: 'https://example.test', href: 'https://example.test/admin' },
  addEventListener() {},
  removeEventListener() {},
  setTimeout,
  clearTimeout,
  console,
};
vm.runInNewContext(source, {
  window: fakeWindow,
  console,
  URL,
  URLSearchParams,
  Set,
  FormData: class FormData {},
  fetch: async () => { throw new Error('Unexpected network request'); },
  Date,
});

const Bridge = fakeWindow.ProspektwebCalcIntegration;
const bridge = Object.create(Bridge.prototype);
bridge.config = { presetId: 12740, siteId: 's1', sessid: 'test' };
bridge.initData = { preset: { id: 12740 } };

const guarded = bridge.withAuthoritativePreset([
  { action: 'updateStageProperty', presetId: 999, stageId: 1 },
  { action: 'updateSettingsProperty', presetId: 999, settingsId: 2 },
  { action: 'saveCalcLogic', presetId: 999, settingsId: 2, stageId: 1 },
  { action: 'moveStage', presetId: 999, stageId: 1 },
  { action: 'clearPreset', presetId: 999 },
  { action: 'unrelated', presetId: 999 },
]);
assert.deepEqual(
  guarded.map(item => item.presetId),
  [12740, 12740, 12740, 12740, 12740, 999],
  'every preset mutation must overwrite caller data with the authoritative INIT preset',
);

bridge.config.presetId = 12741;
assert.throws(
  () => bridge.withAuthoritativePreset([{ action: 'updateStageProperty', stageId: 1 }]),
  /does not match authoritative INIT/,
);
bridge.config.presetId = 12740;

let captured = null;
bridge.fetchRefreshData = async (items) => {
  captured = bridge.withAuthoritativePreset(items);
  return [{ status: 'ok' }];
};
bridge.escapeHtmlValue = value => value;
bridge.updateSettingsPropertyInInitDataWithRaw = () => {};
bridge.updateSettingsPropertyInInitDataWithDescriptions = () => {};
bridge.updateStagePropertyInInitDataWithDescriptions = () => {};
bridge.sendPwrtMessage = () => {};

let parentFormMessage = null;
let formBridgeReply = null;
fakeWindow.parent = {
  postMessage(message, origin) {
    parentFormMessage = { message, origin };
  },
};
bridge.config = {
  presetId: 0,
  versionOriginalPresetId: 12740,
  versionId: 'v_3caf71f29edbb97234c4',
  editorInstanceId: '0123456789abcdef0123456789abcdef',
};
bridge.sendPwrtMessage = (type, payload, requestId, origin) => {
  formBridgeReply = { type, payload, requestId, origin };
};
bridge.handleOpenFormEditorRequest({ requestId: 'form-1' }, 'https://example.test');
assert.equal(parentFormMessage.origin, 'https://example.test');
assert.deepEqual(JSON.parse(JSON.stringify(parentFormMessage.message.payload)), {
  editorInstanceId: '0123456789abcdef0123456789abcdef',
  presetId: 12740,
  versionId: 'v_3caf71f29edbb97234c4',
});
assert.equal(parentFormMessage.message.type, 'OPEN_CONTROL_CENTER_FORM_EDITOR');
assert.equal(formBridgeReply, null, 'the child must not receive success before the control center acknowledges visibility');
bridge.handleMessage({
  source: fakeWindow.parent,
  origin: 'https://example.test',
  data: {
    protocol: 'pwrt-v1',
    source: 'bitrix',
    target: 'prospektweb.calc',
    type: 'CONTROL_CENTER_FORM_EDITOR_OPENED',
    requestId: parentFormMessage.message.requestId,
    payload: { editorInstanceId: '0123456789abcdef0123456789abcdef' },
  },
});
assert.equal(formBridgeReply.type, 'RESPONSE');
assert.equal(formBridgeReply.payload.status, 'success');

bridge.config.editorInstanceId = '';
parentFormMessage = null;
formBridgeReply = null;
bridge.handleOpenFormEditorRequest({ requestId: 'form-2' }, 'https://example.test');
assert.equal(parentFormMessage, null, 'an unowned calculator page must not open the control-center form');
assert.equal(formBridgeReply.type, 'ERROR');
assert.match(formBridgeReply.payload.details, /только из Центра управления/);

bridge.config = { presetId: 12740, siteId: 's1', sessid: 'test' };
bridge.sendPwrtMessage = () => {};

bridge.initData = {
  preset: { id: 12740, marker: 'before' },
  elementsStore: {
    CALC_STAGES: [{
      id: 601,
      properties: {
        OPTIONS_MATERIAL: { VALUE: 'stale', '~VALUE': 'stale' },
      },
    }],
  },
  globalSymbols: [{ code: 'before' }],
  untouched: 'keep',
};
assert.equal(bridge.applySemanticReadback({
  semanticRevision: 'revision-next',
  semanticReadback: {
    preset: { id: 12740, marker: 'after' },
    elementsStore: bridge.initData.elementsStore,
    globalSymbols: [{ code: 'after' }],
  },
}), true, 'version-aware semantic readback must be accepted');
assert.equal(bridge.initData.preset.marker, 'after');
assert.equal(bridge.initData.globalSymbols[0].code, 'after');
assert.equal(bridge.initData.semanticRevision, 'revision-next');
assert.equal(bridge.initData.untouched, 'keep', 'unrelated INIT data must survive partial readback');
bridge.updateStagePropertyInInitData(601, 'OPTIONS_MATERIAL', '');
assert.equal(bridge.initData.elementsStore.CALC_STAGES[0].properties.OPTIONS_MATERIAL.VALUE, '');
assert.equal(bridge.initData.elementsStore.CALC_STAGES[0].properties.OPTIONS_MATERIAL['~VALUE'], '');

(async () => {
  await bridge.handleSaveCalcLogicRequest({
    requestId: 'logic-1',
    payload: {
      settingsId: 501,
      stageId: 601,
      calcSettings: {
        logicJson: JSON.stringify({ version: 2, vars: [] }),
        params: [{ name: 'copies', type: 'number' }],
        globalDependencies: ['paper_cost'],
      },
      stageWiring: {
        inputs: [{ name: 'copies', path: 'input.values.COPIES' }],
        outputs: [{ key: 'cost', var: 'result.cost' }],
      },
      stageParametrValuesScheme: {
        offer: [{ name: 'title', template: '{copies}' }],
      },
    },
  }, 'https://example.test');

  assert.equal(captured.length, 1, 'SAVE_CALC_LOGIC_REQUEST must use one server mutation');
  assert.equal(captured[0].action, 'saveCalcLogic');
  assert.equal(captured[0].presetId, 12740);
  assert.deepEqual(Object.keys(captured[0]).sort(), [
    'action',
    'calcSettings',
    'presetId',
    'settingsId',
    'stageId',
    'stageParametrValuesScheme',
    'stageWiring',
  ]);
  console.log('Calculator logic bridge authority tests passed');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
