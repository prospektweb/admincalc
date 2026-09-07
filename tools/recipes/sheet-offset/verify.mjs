import fs from 'node:fs/promises'
import path from 'node:path'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'
import { buildRecipe } from './build-recipe.mjs'

const [file, frontendPath, serverPath, output] = process.argv.slice(2)
const root = await fs.realpath(frontendPath)
const serverRoot = await fs.realpath(serverPath)
const snapshot = JSON.parse(await fs.readFile(file,'utf8'))
const baseline = snapshot.data || snapshot
const recipe = process.argv.includes('--live') ? {changes:[],globals:baseline.globalSymbols} : buildRecipe(baseline)
const require = createRequire(path.join(root,'package.json'))
const {createServer} = require('vite')
const vite = await createServer({root, configFile:false, appType:'custom', server:{middlewareMode:true}, optimizeDeps:{noDiscovery:true,include:[]},resolve:{alias:{'@':`${root.replaceAll('\\','/')}/src`}}})
const log=console.log
console.log=()=>{}; console.warn=()=>{}
const cases=[]
const documentSourcePath = process.argv.find(a => a.startsWith('--document-source='))?.slice('--document-source='.length)
const documentSource = documentSourcePath ? JSON.parse(await fs.readFile(documentSourcePath, 'utf8')) : null
const core = documentSource ? await import(pathToFileURL(path.join(serverRoot, 'dist/core/calculator.js'))) : null
const importer = documentSource ? await import(pathToFileURL(path.join(serverRoot, 'dist/adapters/bitrix/importCalculatorDocument.js'))) : null
const values={volume:1000,'system.layout-count':1,'system.deadline-type':'strict','format.width':90,'format.length':50,method:'OFSET','color.scheme':'4+4','type.material':'paper','type.paper':'mel-mat-paper','density.paper':'150','section:protection':false,protection:'',options:[]}
function prepare(changed, modify=()=>{}) {
  const init=structuredClone(baseline)
  for(const c of recipe.changes) {
    const e=[init.preset,...Object.values(init.elementsStore).flat()].find(e=>e.id===c.id)
    for(const [k,v] of Object.entries(c.properties)){
      const copied=structuredClone(v)
      e.properties[k]={...e.properties[k],...copied,'~VALUE':copied.VALUE}
    }
    if(c.name)e.name=c.name
    if(c.catalogProduct)e.attributes.weight=c.catalogProduct.WEIGHT
    if(c.properties.PARAMETRS) {
      e.selectionFacts ||= {};e.selectionFacts.parameters ||= {}
      c.properties.PARAMETRS.VALUE.forEach((code,i)=>{
        const [raw,title,description]=c.properties.PARAMETRS.DESCRIPTION[i].split('|')
        let value=raw;try{value=JSON.parse(raw)}catch{}
        e.selectionFacts.parameters[code]={code,value,title,description,valueType:typeof value}
      })
    }
  }
  init.globalSymbols=recipe.globals.map((g,i)=>({...g,id:g.id || 900000+i,presetId:12740,iblockId:51}))
  init.preset.id=12740; init.preset.runtimePresetId=16411
  modify(init)
  for(const sibling of init.elementsSiblings || [])for(const key of ['CALC_OPERATIONS_VARIANTS','CALC_MATERIALS_VARIANTS'])if(Array.isArray(sibling[key]))sibling[key]=sibling[key].map(e=>structuredClone(Object.values(init.elementsStore).flat().find(x=>x.id===e.id)||e))
  init.calculationInput={contract:'prospektweb.calc.input-context/v1',source:'manual',scenario:{id:'offset-qa'},preset:{id:12740,revision:1,compileHash:init.editorRuntime.publication.compileHash},values:{...values,...changed}}
  init.__diagnosticPreview=true
  return init
}
try {
  const frontend=await vite.ssrLoadModule('/src/services/calculationEngine.ts')
  const transform=await vite.ssrLoadModule('/src/lib/bitrix-to-ui-transformer.ts')
  const server=await import(pathToFileURL(path.join(serverRoot,'dist/services/calculationEngine.js')))
  const summarize=r=>r[0].details.flatMap(d=>d.stages.map(s=>({id:s.stageElementId,incomplete:!!s.incomplete,cost:s.totalCost,outputs:s.outputs,issues:s.issues})))
  const execute=async(init,engine)=>engine.calculateAllOffers([{id:-1,productId:0,name:'Offset QA',attributes:{},properties:{},calculationInput:init.calculationInput}],null,init.preset,init.elementsStore.CALC_DETAILS.map(d=>transform.transformDetail(d,init.elementsStore)),[],[],init)
  async function test(name, input, check, modify) {
    const client=summarize(await execute(prepare(input,modify),frontend))
    if(client.some(s=>s.incomplete)) {
      await fs.writeFile(`${output}.last-incomplete.json`,JSON.stringify({name,client},null,2))
      await assert.rejects(()=>execute(prepare(input,modify),server))
    } else {
      const backend=summarize(await execute(prepare(input,modify),server))
      assert.deepEqual(backend,client,`${name}: frontend/server parity`)
    }
    check(client)
    if (documentSource) {
      const init = prepare(input, modify)
      const publication = structuredClone(documentSource.publication)
      publication.documents.logic.runtimePayload = init
      const imported = importer.importBitrixCalculator(publication, 'bitrix:prospektprint.ru')
      const prepared = core.prepareCalculator(imported.document, imported.resourceSnapshots)
      if (client.some(s => s.incomplete)) {
        assert.throws(() => prepared.execute(init.calculationInput.values), `${name}: document must reject incomplete quote`)
      } else {
        let result
        try { result = prepared.execute(init.calculationInput.values, { trace: true }) }
        catch (error) { await fs.writeFile(`${output}.document-error.json`, JSON.stringify({name, message:error.message, diagnostic:error.diagnostic}, null, 2)); throw error }
        const ids = new Map(imported.externalBindings.filter(b => b.category === 'stage').map(b => [b.id, Number(b.sourceId)]))
        const stages = result.parts.flatMap(p => p.stages.map(s => ({id:ids.get(s.id), cost:s.outputs.purchasingPrice, outputs:s.outputs})))
        const expected = client.map(({id,cost,outputs}) => ({id,cost,outputs}))
        try { assert.deepEqual(stages, expected, `${name}: independent document engine parity`) }
        catch (error) { await fs.writeFile(`${output}.document-diff.json`, JSON.stringify({name, expected, actual:stages}, null, 2)); throw error }
      }
    }
    cases.push({name,stages:client.map(({id,incomplete,cost,outputs,issues})=>({id,incomplete,cost,outputs,issues}))})
  }
  const get=(r,id)=>r.find(s=>s.id===id)
  const valid=r=>assert.ok(r.every(s=>!s.incomplete && Number.isFinite(s.cost)),JSON.stringify(r.filter(s=>s.incomplete)))
  for(const color of ['1+0','1+1','2+0','2+1','2+2','4+0','4+1','4+4'])await test(color,{'color.scheme':color},r=>{
    valid(r);const m=get(r,16827).outputs
    assert.ok(m.stock_sheets>m.good_sheets && m.good_sheets>=m.net_sheets)
    assert.ok(m.source_sheets*m.items_per_sheet>0)
    assert.equal(m.plate_qty*178.57,get(r,18854).cost)
    const setup=get(r,16434)
    assert.equal(setup.outputs.setup_unit_cost,2012)
    assert.ok(Math.abs(setup.cost - ['setup_labor_cost','setup_rent_cost','setup_consumables_cost','setup_reserve_cost'].reduce((s,k)=>s+setup.outputs[k],0))<0.000001)
    if(color==='4+0'){assert.equal(setup.cost,2012);assert.ok(Math.abs(setup.outputs.basePrice-3219.2)<0.000001)}
    if(color==='4+4'){assert.equal(m.plate_qty,4);assert.equal(m.set_qty,1);assert.equal(m.stock_sheets-m.feed1,150);assert.equal(m.items_per_sheet,8);assert.equal(m.source_sheets,72)}
  })
  await test('two independent layouts',{'system.layout-count':2},r=>{valid(r);assert.equal(get(r,16827).outputs.plate_qty,8);assert.equal(get(r,16827).outputs.source_sheets,144);assert.ok(Math.abs(get(r,16439).outputs.run_weight - 45.83 * 90 * 50 * 2000 / (470 * 650)) < 0.000001)})
  const setNorm=(init,code,value)=>{
    const operation=Object.values(init.elementsStore).flat().find(e=>e.id===1074)
    const params=operation.properties.PARAMETRS
    const i=params.VALUE.indexOf(code);assert.ok(i>=0)
    params.DESCRIPTION[i]=params.DESCRIPTION[i].replace(/^[^|]*/,String(value))
    operation.selectionFacts.parameters[code].value=value
  }
  await test('operation labor norm changes setup cost',{'color.scheme':'4+0'},r=>{valid(r);assert.equal(get(r,16434).cost,2112);assert.equal(get(r,16434).outputs.setup_unit_cost,2112)},init=>setNorm(init,'MAKEREADY_SHIFT_LABOR_RUB',6420))
  await test('catalog purchase price is not setup authority',{'color.scheme':'4+0'},r=>{valid(r);assert.equal(get(r,16434).cost,2012)},init=>{Object.values(init.elementsStore).flat().find(e=>e.id===1074).purchasingPrice=9999})
  await test('zero planned sets rejects quote',{},r=>assert.ok(get(r,16827).incomplete),init=>setNorm(init,'MAKEREADY_PLANNED_SETS_PER_SHIFT',0))
  for(const sides of ['1','2'])await test(`lamination ${sides} sides`,{'section:protection':true,protection:'lamination-rulon',lamination:'gloss-low','lamination.sides':sides},r=>{
    valid(r);const l=get(r,16436);assert.ok(l,'Lamination must execute');assert.ok(l.outputs.film_roll_fraction>0);assert.equal(l.outputs.height,0.147+Number(sides)*0.03)
    assert.ok(l.outputs.film_used_length<get(r,16827).outputs.stock_sheets*(325+4)*Number(sides)*1.03)
  })
  await test('300 gsm thickness factor',{'density.paper':'300'},r=>{valid(r);assert.equal(get(r,16827).outputs.height,0.283);assert.ok(Math.abs(get(r,16434).cost-2012*1.3*1.5)<0.000001)})
  for(const qty of [5000,5001,50000,500000])await test(`4+4 volume ${qty}`,{volume:qty},r=>{valid(r);assert.equal(get(r,16827).outputs.turn_name,'Свой оборот')})
  await test('impossible sheet size',{'format.width':1000,'format.length':1000},r=>assert.ok(get(r,16827).incomplete))
  await test('unpriced third ink',{'color.scheme':'3+0'},r=>assert.ok(get(r,16827).incomplete))
  await test('80 gsm uses actual 80 gsm paper',{'type.paper':'vhi-paper','density.paper':'80'},r=>{valid(r);assert.equal(get(r,16827).outputs.height,0.1)})
  await test('unavailable glossy 120 is not replaced by matte 150',{'type.paper':'mel-glossy-paper','density.paper':'120'},r=>assert.ok(get(r,16827).incomplete))
  await test('A3 selects a large box',{'format.width':297,'format.length':420},r=>{valid(r);assert.equal(get(r,16440).outputs.width,440);assert.equal(get(r,16434).cost,4024);assert.ok(Math.abs(get(r,16434).outputs.basePrice-6438.4)<0.000001);assert.equal(get(r,16827).outputs.set_qty,2);assert.equal(get(r,16827).outputs.stock_sheets-get(r,16827).outputs.feed1,150)})
  await test('own turn disabled',{},r=>{valid(r);assert.equal(get(r,16827).outputs.plate_qty,8);assert.equal(get(r,16827).outputs.set_qty,2)},init=>{init.globalSymbols.find(g=>g.code==='offset_work_and_turn_allowed').initialValue='false'})
  await test('digital shared material regression',{method:'DIGITAL','color.scheme':'4+0'},r=>valid(r))
  for(const qty of [100,1000,10000,50000]) await test(`4+1 economic choice ${qty}`,{volume:qty,'format.width':105,'format.length':148,'color.scheme':'4+1'},r=>{valid(r);assert.equal(get(r,16827).outputs.turn_name,qty>=50000?'Чужой оборот':'Свой оборот')})
  await test('sheet fits machine but exceeds box',{'format.width':297,'format.length':440},r=>{assert.ok(!get(r,16827).incomplete);assert.ok(get(r,16440).incomplete)})
  await fs.writeFile(output,JSON.stringify({passed:cases.length,parity:documentSource ? 'CalcConfig, Calc Server and independent document core' : 'CalcConfig and Calc Server',cases},null,2))
  log(JSON.stringify({passed:cases.length,parity:true,output}))
} finally {await vite.close();console.log=log}
