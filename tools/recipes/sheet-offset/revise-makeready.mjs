import fs from 'node:fs/promises'
import assert from 'node:assert/strict'
import { MAKEREADY_COMPONENTS, MAKEREADY_ALIASES, MAKEREADY_REPORT_VARIABLES, MAKEREADY_REPORT_TEMPLATES, materialMakereadyVariables } from './makeready-model.mjs'

// A bounded upgrade of the already installed recipe. Capture a fresh working
// snapshot and global authority before use; admin.php owns backup, CAS and clones.
const [input, output, candidateFile, publicationFile] = process.argv.slice(2)
const snapshot = JSON.parse(await fs.readFile(input, 'utf8'))
const init = snapshot.working.runtimePayload
const elements = Object.values(init.elementsStore).flat()
const changes = []
const property = v => ({VALUE: structuredClone(v.VALUE), DESCRIPTION: structuredClone(v.DESCRIPTION ?? null)})
const settingsFor = stageId => {
  const stage = elements.find(e => e.id === stageId)
  return elements.find(e => e.id === Number(stage.properties.CALC_SETTINGS.VALUE))
}
const edit = (element, propertyNames, mutate, name) => {
  const expected = Object.fromEntries(propertyNames.map(k => [k, property(element.properties[k])]))
  const properties = structuredClone(expected)
  mutate(properties)
  changes.push({id:element.id, iblockId:element.iblockId, expectedName:element.name, expected, properties, ...(name ? {name} : {})})
  for (const [key,value] of Object.entries(properties)) element.properties[key] = {...element.properties[key],...value,'~VALUE':value.VALUE}
  if (name) element.name = name
}
edit(settingsFor(16827), ['LOGIC_JSON','PARAMS'], props => {
  const logic = JSON.parse(props.LOGIC_JSON.VALUE.TEXT)
  for (const prefix of ['a_separate','a_turn','b_separate','b_turn']) {
    const v=logic.vars.find(v=>v.name===`${prefix}_setup_cost`)
    assert.ok(v.formula.startsWith(`${prefix}_plates * setup_purchase_rate`), `Unexpected formula ${v.name}`)
    v.formula=v.formula.replace(`${prefix}_plates *`,`${prefix}_sets *`)
  }
  logic.vars.unshift(...materialMakereadyVariables().map(({type,...v},i)=>({...v,formula:v.formula.replaceAll('selectionFacts.parameters.','parameters.'),id:`makeready_norm_${i}`,inferredType:type||'number'})))
  props.LOGIC_JSON.VALUE.TEXT=JSON.stringify(logic)
  const i=props.PARAMS.VALUE.indexOf('setup_purchase_rate'); assert.ok(i>=0)
  props.PARAMS.VALUE.splice(i,1);props.PARAMS.DESCRIPTION.splice(i,1)
  MAKEREADY_COMPONENTS.forEach(([code,,title],i)=>{props.PARAMS.VALUE.push(MAKEREADY_ALIASES[i]);props.PARAMS.DESCRIPTION.push(`number|${title}|`)})
})
edit(elements.find(e=>e.id===16827), ['INPUTS'], props=>{
  const i=props.INPUTS.VALUE.indexOf('setup_purchase_rate');assert.ok(i>=0)
  props.INPUTS.VALUE.splice(i,1);props.INPUTS.DESCRIPTION.splice(i,1)
  MAKEREADY_COMPONENTS.forEach(([code],i)=>{props.INPUTS.VALUE.push(MAKEREADY_ALIASES[i]);props.INPUTS.DESCRIPTION.push(`stage_16434.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.${code}`)})
})
edit(settingsFor(16434), ['LOGIC_JSON','PARAMS'], props => {
  const logic=JSON.parse(props.LOGIC_JSON.VALUE.TEXT)
  const markup=logic.vars.find(v=>v.name==='setup_markup')
  assert.equal(markup.formula,'getPrice(offset_plate_qty, setup_prices)')
  markup.formula='getPrice(offset_print_form_qty, setup_prices)'
  markup.title='Наценка за один цвет (%)'
  const base=logic.vars.find(v=>v.name==='setup_base')
  assert.equal(base.formula,'setup_cost * (1 + setup_markup / 100)')
  base.formula='round(setup_cost * (1 + offset_plate_qty / offset_print_form_qty * setup_markup / 100) * 100) / 100'
  base.description='Закупка — за комплект форм. Процент — за каждый цвет; шкала по количеству комплектов. 4+0: 2012 * (1 + 4 * 15%) = 3219.20 руб.'
  logic.vars.push(...MAKEREADY_REPORT_VARIABLES.map(([name,formula,title],i)=>({id:`makeready_report_${i}`,name,formula,title,inferredType:'number',description:''})))
  props.LOGIC_JSON.VALUE.TEXT=JSON.stringify(logic)
  const i=props.PARAMS.VALUE.indexOf('setup_prices'); assert.ok(i>=0)
  props.PARAMS.DESCRIPTION[i]='array|Процент за цвет по количеству комплектов форм|'
  MAKEREADY_COMPONENTS.forEach(([code,,title],i)=>{props.PARAMS.VALUE.push(MAKEREADY_ALIASES[i]);props.PARAMS.DESCRIPTION.push(`number|${title}|`)})
})
edit(elements.find(e=>e.id===16434), ['INPUTS','OUTPUTS','SCHEME_PARAMETR_VALUES'], props=>{
  MAKEREADY_COMPONENTS.forEach(([code,,title],i)=>{props.INPUTS.VALUE.push(MAKEREADY_ALIASES[i]);props.INPUTS.DESCRIPTION.push(`stage_16434.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.${code}`)})
  for(const [name,,title] of MAKEREADY_REPORT_VARIABLES){props.OUTPUTS.VALUE.push(`${name}|${title}`);props.OUTPUTS.DESCRIPTION.push(name)}
  const rows=MAKEREADY_REPORT_TEMPLATES
  props.SCHEME_PARAMETR_VALUES.VALUE=rows.map(r=>r[0]);props.SCHEME_PARAMETR_VALUES.DESCRIPTION=rows.map(r=>JSON.stringify({version:2,template:r[1],writeToOffer:false}))
})
const operation=elements.find(e=>e.id===1074)
edit(operation, ['PARAMETRS'], props=>{
  for(const [code,value,title] of MAKEREADY_COMPONENTS){assert.ok(!props.PARAMETRS.VALUE.includes(code));props.PARAMETRS.VALUE.push(code);props.PARAMETRS.DESCRIPTION.push(`${value}|${title}|Норматив владельца: 11340 / 7.5 + 500 = 2012 руб/комплект. Не включает готовые пластины и тиражные прогоны.`)}
}, 'Приладка одного комплекта форм Ryobi 524HXX')
for(const [code,value,title] of MAKEREADY_COMPONENTS) operation.selectionFacts.parameters[code]={code,value,title,valueType:'number'}
const globals=structuredClone(init.globalSymbols)
const recipe={contract:'prospektweb.calc.sheet-offset-recipe/v1',presetId:12740,workingPresetId:16411,versionId:'v_3caf71f29edbb97234c4',expectedGlobalRevision:snapshot.globalAuthority.revision,expectedGlobalFingerprint:snapshot.globalAuthority.fingerprint,changes,expectedGlobals:globals,globals}
await fs.writeFile(output,JSON.stringify(recipe,null,2))
const publication=JSON.parse(await fs.readFile(publicationFile,'utf8'))
const form=publication.documents.form
init.editorRuntime={formDefinition:form.formDefinition,bindingDefinition:form.bindingDefinition,publication:form.runtimePublication.publication}
init.commercialPolicy=publication.documents.commercialPolicy
await fs.writeFile(candidateFile,JSON.stringify(init,null,2))
console.log(JSON.stringify({changes:changes.map(c=>c.id),globalRevision:recipe.expectedGlobalRevision}))
