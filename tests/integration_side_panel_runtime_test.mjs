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

test('opened form slider is elevated above the full-screen control-center shell', () => {
  const style = () => ({
    values: {},
    setProperty(name, value, priority) { this.values[name] = { value, priority } },
  })
  const classes = () => ({ values: [], add(value) { this.values.push(value) } })
  const container = { style: style(), classList: classes() }
  const overlay = { style: style(), classList: classes() }
  const appended = []
  const window = {
    ...sidePanelWindow({ open() {} }),
    top: null,
    document: {
      head: { appendChild(node) { appended.push(node) } },
      getElementById() { return null },
      createElement(tagName) { return { tagName, id: '', textContent: '' } },
      querySelectorAll(selector) {
        if (selector === '.side-panel-container') return [container]
        if (selector === '.side-panel-overlay.--open') return [overlay]
        return []
      },
    },
  }
  window.top = window
  const Integration = loadIntegration(window)
  assert.equal(Integration.elevateOpenedSidePanel(window), true)
  assert.deepEqual(container.style.values['z-index'], { value: '2147483646', priority: 'important' })
  assert.deepEqual(overlay.style.values['z-index'], { value: '2147483645', priority: 'important' })
  assert.deepEqual(container.classList.values, ['prospektweb-form-editor-layer'])
  assert.deepEqual(overlay.classList.values, ['prospektweb-form-editor-layer'])
  assert.equal(appended.length, 1)
  assert.match(appended[0].textContent, /side-panel-overlay\.prospektweb-form-editor-layer/)
})
