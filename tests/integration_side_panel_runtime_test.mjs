import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

const source = await readFile(new URL('../install/assets/js/integration.js', import.meta.url), 'utf8')

const loadIntegration = window => {
  vm.runInNewContext(source, { window, console: { log() {}, error() {}, warn() {} }, URLSearchParams })
  return window.ProspektwebCalcIntegration
}

const sidePanelWindow = sidePanel => ({ BX: { SidePanel: { Instance: sidePanel } }, innerWidth: 1400 })

test('visible side panel resolver prefers a callable top manager', () => {
  const local = { open() {} }
  const top = sidePanelWindow({ open() {} })
  const window = { ...sidePanelWindow(local), top }
  const Integration = loadIntegration(window)
  const resolved = Integration.resolveVisibleSidePanelHost(window)
  assert.equal(resolved.hostWindow, top)
  assert.equal(resolved.sidePanel, top.BX.SidePanel.Instance)
})

test('visible side panel resolver falls back when top access is blocked', () => {
  const local = { open() {} }
  const blockedTop = {}
  Object.defineProperty(blockedTop, 'BX', { get() { throw new DOMException('Blocked', 'SecurityError') } })
  const window = { ...sidePanelWindow(local), top: blockedTop }
  const Integration = loadIntegration(window)
  const resolved = Integration.resolveVisibleSidePanelHost(window)
  assert.equal(resolved.hostWindow, window)
  assert.equal(resolved.sidePanel, local)
})

test('visible side panel resolver ignores a partially initialized top manager', () => {
  const local = { open() {} }
  const window = { ...sidePanelWindow(local), top: sidePanelWindow({}) }
  const Integration = loadIntegration(window)
  const resolved = Integration.resolveVisibleSidePanelHost(window)
  assert.equal(resolved.hostWindow, window)
  assert.equal(resolved.sidePanel, local)
})

test('opened form slider is elevated by its exact URL and standalone shell is restored', () => {
  const style = initial => ({
    values: initial ? { 'z-index': { value: initial, priority: '' } } : {},
    setProperty(name, value, priority = '') { this.values[name] = { value, priority } },
    getPropertyValue(name) { return this.values[name]?.value || '' },
    getPropertyPriority(name) { return this.values[name]?.priority || '' },
    removeProperty(name) { delete this.values[name] },
  })
  const classes = initial => ({
    values: new Set(initial || []),
    add(value) { this.values.add(value) },
    remove(value) { this.values.delete(value) },
    contains(value) { return this.values.has(value) },
  })
  const container = { style: style(), classList: classes() }
  const overlay = { style: style('1050'), classList: classes(['--open']) }
  const shell = { style: style(), classList: classes() }
  const slider = {
    getContainer() { return container },
    getOverlay() { return overlay },
    isOpen() { return true },
  }
  const otherSlider = {
    getContainer() { throw new Error('unrelated slider must not be touched') },
    getOverlay() { throw new Error('unrelated slider must not be touched') },
  }
  const observations = []
  class MutationObserver {
    constructor(callback) { this.callback = callback; this.disconnected = false }
    observe(element, options) { observations.push({ element, options }) }
    disconnect() { this.disconnected = true }
  }
  const sidePanel = {
    open() {},
    getSlider(url) { return url === '/target' ? slider : otherSlider },
  }
  const window = {
    ...sidePanelWindow(sidePanel),
    top: null,
    MutationObserver,
    document: {
      getElementById(id) { return id === 'calc-container' ? shell : null },
    },
  }
  window.top = window
  const Integration = loadIntegration(window)
  assert.equal(Integration.elevateSidePanelByUrl(window, sidePanel, '/target'), true)
  assert.deepEqual(container.style.values['z-index'], { value: '2147483646', priority: 'important' })
  assert.deepEqual(overlay.style.values['z-index'], { value: '2147483645', priority: 'important' })
  assert.deepEqual(shell.style.values['z-index'], { value: '2147483644', priority: 'important' })
  assert.equal(container.classList.contains('prospektweb-form-editor-layer'), true)
  assert.equal(overlay.classList.contains('prospektweb-form-editor-layer'), true)
  assert.equal(observations.length, 2)

  assert.equal(Integration.restoreOpenedSidePanel(slider), true)
  assert.equal(container.style.getPropertyValue('z-index'), '')
  assert.equal(overlay.style.getPropertyValue('z-index'), '1050')
  assert.equal(shell.style.getPropertyValue('z-index'), '')
  assert.equal(container.classList.contains('prospektweb-form-editor-layer'), false)
  assert.equal(overlay.classList.contains('prospektweb-form-editor-layer'), false)
})

test('exact URL lookup does not elevate another open slider', () => {
  const sidePanel = { open() {}, getSlider() { return null } }
  const window = { ...sidePanelWindow(sidePanel), top: null, document: {} }
  window.top = window
  const Integration = loadIntegration(window)
  assert.equal(Integration.elevateSidePanelByUrl(window, sidePanel, '/missing'), false)
})
