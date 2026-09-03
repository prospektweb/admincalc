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
