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
