import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

const source = await readFile(new URL('../install/assets/js/integration.js', import.meta.url), 'utf8')

const loadIntegration = window => {
  vm.runInNewContext(source, {
    window,
    console: { log() {}, error() {}, warn() {}, info() {} },
    URL,
    URLSearchParams,
    Math,
    Date,
  })
  return window.ProspektwebCalcIntegration
}

const createHarness = () => {
  const parentMessages = []
  const childMessages = []
  const timers = new Map()
  let timerId = 0
  const parent = {
    postMessage(message, origin) { parentMessages.push({ message, origin }) },
  }
  const window = {
    location: { origin: 'https://example.test', href: 'https://example.test/admin' },
    parent,
    addEventListener() {},
    removeEventListener() {},
    setTimeout(callback) { timerId += 1; timers.set(timerId, callback); return timerId },
    clearTimeout(id) { timers.delete(id) },
  }
  const Integration = loadIntegration(window)
  const bridge = Object.create(Integration.prototype)
  bridge.config = {
    presetId: 0,
    versionOriginalPresetId: 12740,
    versionId: 'v_3caf71f29edbb97234c4',
    editorInstanceId: '0123456789abcdef0123456789abcdef',
  }
  bridge.pendingFormEditorRequest = null
  bridge.sendPwrtMessage = (type, payload, requestId, origin) => {
    childMessages.push({ type, payload, requestId, origin })
  }
  return { bridge, childMessages, parent, parentMessages, timers, window }
}

test('form workspace request is scoped and remains pending until parent acknowledgement', () => {
  const harness = createHarness()
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-1' }, 'https://example.test')
  assert.equal(harness.parentMessages.length, 1)
  assert.equal(harness.childMessages.length, 0)
  const request = harness.parentMessages[0]
  assert.equal(request.origin, 'https://example.test')
  assert.equal(request.message.type, 'OPEN_CONTROL_CENTER_FORM_EDITOR')
  assert.match(request.message.requestId, /^form_workspace_/)
  assert.deepEqual(JSON.parse(JSON.stringify(request.message.payload)), {
    editorInstanceId: '0123456789abcdef0123456789abcdef',
    presetId: 12740,
    versionId: 'v_3caf71f29edbb97234c4',
  })
})

test('only the exact parent acknowledgement completes the child request', () => {
  const harness = createHarness()
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-2' }, 'https://example.test')
  const requestId = harness.parentMessages[0].message.requestId
  assert.equal(harness.bridge.handleControlCenterFormEditorResponse({
    source: {}, origin: 'https://example.test', data: {},
  }), false)
  assert.equal(harness.childMessages.length, 0)
  assert.equal(harness.bridge.handleControlCenterFormEditorResponse({
    source: harness.parent,
    origin: 'https://example.test',
    data: {
      protocol: 'pwrt-v1', source: 'bitrix', target: 'prospektweb.calc',
      type: 'CONTROL_CENTER_FORM_EDITOR_OPENED', requestId,
      payload: { editorInstanceId: '0123456789abcdef0123456789abcdef' },
    },
  }), true)
  assert.equal(harness.childMessages[0].type, 'RESPONSE')
  assert.equal(harness.childMessages[0].requestId, 'child-2')
  assert.equal(harness.bridge.pendingFormEditorRequest, null)
})

test('parent rejection becomes a correlated visible error', () => {
  const harness = createHarness()
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-3' }, 'https://example.test')
  const requestId = harness.parentMessages[0].message.requestId
  harness.bridge.handleControlCenterFormEditorResponse({
    source: harness.parent,
    origin: 'https://example.test',
    data: {
      protocol: 'pwrt-v1', source: 'bitrix', target: 'prospektweb.calc',
      type: 'CONTROL_CENTER_FORM_EDITOR_ERROR', requestId,
      payload: { editorInstanceId: '0123456789abcdef0123456789abcdef', message: 'Редактор формы занят.' },
    },
  })
  assert.equal(harness.childMessages[0].type, 'ERROR')
  assert.equal(harness.childMessages[0].payload.details, 'Редактор формы занят.')
})

test('a second click is rejected while the first form request is pending', () => {
  const harness = createHarness()
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-4a' }, 'https://example.test')
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-4b' }, 'https://example.test')
  assert.equal(harness.parentMessages.length, 1)
  assert.equal(harness.childMessages.length, 1)
  assert.equal(harness.childMessages[0].type, 'ERROR')
  assert.match(harness.childMessages[0].payload.details, /уже открывается/)
})

test('timeout fails closed instead of reporting false success', () => {
  const harness = createHarness()
  harness.bridge.handleOpenFormEditorRequest({ requestId: 'child-5' }, 'https://example.test')
  assert.equal(harness.timers.size, 1)
  Array.from(harness.timers.values())[0]()
  assert.equal(harness.childMessages.length, 1)
  assert.equal(harness.childMessages[0].type, 'ERROR')
  assert.match(harness.childMessages[0].payload.details, /не подтвердил/)
  assert.equal(harness.bridge.pendingFormEditorRequest, null)
})

test('the bridge no longer contains recursive control-center or SidePanel launch code', () => {
  assert.equal(source.includes('prospektweb_calc_control_center.php'), false)
  assert.equal(source.includes('sidePanel.open(targetUrl'), false)
})
