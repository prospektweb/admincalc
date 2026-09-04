import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const source = fs.readFileSync(path.join(root, 'install/assets/js/integration.js'), 'utf8')
const context = {
  window: {},
  console: { log() {}, warn() {}, error() {} },
}
vm.runInNewContext(source, context, { filename: 'integration.js' })

const CalcIntegration = context.window.ProspektwebCalcIntegration
assert.equal(typeof CalcIntegration, 'function', 'integration constructor must be exported')

const createBridge = () => {
  const bridge = Object.create(CalcIntegration.prototype)
  bridge.initData = { semanticRevision: 'revision-a' }
  bridge.initDataGeneration = 0
  bridge.calculatorMutationQueue = Promise.resolve()
  bridge.withAuthoritativePreset = items => items
  bridge.presetMutationActions = () => new Set(['semantic'])
  bridge.globalMutationActions = () => new Set(['global'])
  bridge.coordinatedPresetMutationActions = () => new Set(['coordinated'])
  return bridge
}

{
  const bridge = createBridge()
  const calls = []
  let releaseCoordinated
  const coordinatedGate = new Promise(resolve => { releaseCoordinated = resolve })
  bridge.fetchRefreshDataNow = async items => {
    calls.push(items[0].action)
    if (items[0].action === 'coordinated') await coordinatedGate
    return []
  }
  const coordinatedWrite = bridge.fetchRefreshData([{ action: 'coordinated' }])
  const semanticWrite = bridge.fetchRefreshData([{ action: 'semantic' }])
  await Promise.resolve()
  assert.deepEqual(calls, ['coordinated'], 'semantic mutation must wait for coordinated preset mutation')
  releaseCoordinated()
  await Promise.all([coordinatedWrite, semanticWrite])
  assert.deepEqual(calls, ['coordinated', 'semantic'])
}

{
  const bridge = createBridge()
  const calls = []
  let releaseFirst
  const firstGate = new Promise(resolve => { releaseFirst = resolve })
  bridge.fetchRefreshDataNow = async items => {
    calls.push({ action: items[0].action, revision: bridge.initData.semanticRevision })
    if (items[0].id === 1) {
      await firstGate
      bridge.initData.semanticRevision = 'revision-b'
    }
    return []
  }

  const first = bridge.fetchRefreshData([{ action: 'semantic', id: 1 }])
  const second = bridge.fetchRefreshData([{ action: 'semantic', id: 2 }])
  await Promise.resolve()
  assert.deepEqual(calls, [{ action: 'semantic', revision: 'revision-a' }], 'second mutation must wait')
  releaseFirst()
  await Promise.all([first, second])
  assert.deepEqual(calls, [
    { action: 'semantic', revision: 'revision-a' },
    { action: 'semantic', revision: 'revision-b' },
  ], 'second mutation must observe the revision produced by the first')
}

{
  const bridge = createBridge()
  const calls = []
  bridge.fetchRefreshDataNow = async items => {
    calls.push(items[0].id)
    if (items[0].id === 1) throw new Error('expected failure')
    return []
  }
  await assert.rejects(bridge.fetchRefreshData([{ action: 'semantic', id: 1 }]), /expected failure/)
  await bridge.fetchRefreshData([{ action: 'semantic', id: 2 }])
  assert.deepEqual(calls, [1, 2], 'queue must recover after a rejected mutation')
}

{
  const bridge = createBridge()
  const calls = []
  let releaseGlobal
  const globalGate = new Promise(resolve => { releaseGlobal = resolve })
  bridge.fetchRefreshDataNow = async items => {
    calls.push(items[0].action)
    if (items[0].action === 'global') await globalGate
    return []
  }
  const globalWrite = bridge.fetchRefreshData([{ action: 'global' }])
  const semanticWrite = bridge.fetchRefreshData([{ action: 'semantic' }])
  await Promise.resolve()
  assert.deepEqual(calls, ['global'], 'semantic mutation must wait for global refresh')
  releaseGlobal()
  await Promise.all([globalWrite, semanticWrite])
  assert.deepEqual(calls, ['global', 'semantic'])
}

console.log('Calculator mutation queue runtime tests passed')
