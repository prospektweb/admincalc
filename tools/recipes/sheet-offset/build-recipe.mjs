import fs from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import { buildFinishing } from './finishing.mjs'
import { MAKEREADY_COMPONENTS, MAKEREADY_ALIASES, MAKEREADY_REPORT_VARIABLES, MAKEREADY_REPORT_TEMPLATES, materialMakereadyVariables } from './makeready-model.mjs'

const clone = value => structuredClone(value)
const number = (code, title, sourcePath) => ({ code, title, type: 'number', sourcePath })
const text = (code, title, sourcePath) => ({ code, title, type: 'string', sourcePath })
const any = (code, title, sourcePath) => ({ code, title, type: 'any', sourcePath })
const globals = (code, title, type = 'number') => ({ code, title, type, sourcePath: `globalValues.${code}` })
const prop = (VALUE, DESCRIPTION = null) => ({ VALUE, DESCRIPTION })
const pairs = (names, descriptions) => prop(names, descriptions)
const jsonProperty = value => prop({ TEXT: JSON.stringify(value), TYPE: 'HTML' })
const readJson = value => typeof value === 'string' ? JSON.parse(value) : JSON.parse(value.TEXT)
const refs = { material: 16827, plates: 18854, setup: 16434, print: 16433, laminate: 16436, cut: 16439, pack: 16440 }
const settings = { material: 16828, plates: 18855, setup: 16418, print: 16417, laminate: 16419, cut: 16421, pack: 16422 }

class FormulaBlock {
  constructor(name) { this.name = name; this.inputs = []; this.variables = []; this.results = {}; this.additional = [] }
  input(...inputs) { this.inputs.push(...inputs); return this }
  add(code, formula, title, type = 'number', description = '') {
    this.variables.push({ code, formula, title: title || code, type, description }); return code
  }
  global(code, formula, title, kind = 'variable', type = 'number') {
    this.variables.push({ code, formula, title, type, scope: 'global', globalCode: code, globalKind: kind })
  }
  output(key, source) { this.results[key] = { source, sourceKind: 'var' } }
  extra(code, title) { this.additional.push({ code, title, source: code, sourceKind: 'var' }) }
  export() { return { $schema: 'prospektweb.calc.logic-import/v1', version: 1, snapshot: true, inputs: this.inputs, variables: this.variables, results: { ...this.results, additional: this.additional } } }
}

export function buildRecipe(init) {
  const store = init.elementsStore
  const all = [init.preset, ...Object.values(store).flat()].filter(e => e && Number.isInteger(e.id))
  const find = id => { const e = all.find(e => e.id === id); if (!e) throw new Error(`Missing element ${id}`); return e }
  const changes = new Map()
  const change = (id, properties, name) => {
    const original = find(id)
    const prior = changes.get(id) || { id, iblockId: original.iblockId, expectedName: original.name, expected: {}, properties: {} }
    for (const [key, value] of Object.entries(properties)) {
      const old = original.properties[key]
      prior.expected[key] = old ? prop(clone(old.VALUE), clone(old.DESCRIPTION ?? null)) : prop(null)
      prior.properties[key] = value
    }
    if (name) prior.name = name
    changes.set(id, prior)
  }
  const globalRows = clone(init.globalSymbols)
  const stageGroups = readJson(init.preset.properties.STAGE_GROUPS.VALUE)
  for (const group of stageGroups.groups) for (const branch of group.branches || []) for (const operand of branch.operands || []) {
    if (operand.code === 'is_roll_lamination') operand.code = 'is_laminatsiya_rulonnaya'
    if (operand.code === 'is_pouch_lamination') operand.code = 'is_laminatsiya_paketnaya'
  }
  change(init.versionContext.workingPresetId, { STAGE_GROUPS: jsonProperty(stageGroups) })
  // Unused hidden protection fields must not make ordinary digital printing
  // depend on an inactive optional section of the shared form.
  for (const [id,key] of [[16430,'INPUTS'],[16415,'PARAMS']]) {
    const p=find(id).properties[key], keep=p.VALUE.map((v,i)=>({v,i})).filter(({v})=>!['inputProtection','inputLaminationSides'].includes(v))
    change(id,{[key]:pairs(keep.map(({v})=>v),keep.map(({i})=>p.DESCRIPTION[i]))})
  }
  const global = (code, title, kind, initialValue = '', dataType = 'number', description = '') => {
    const existing = globalRows.find(row => row.code === code)
    const row = { ...(existing || { id: 0, presetId: init.versionContext.workingPresetId, active: 'Y' }), code, title, kind, dataType, initialValue, description }
    if (existing) Object.assign(existing, row); else globalRows.push(row)
  }
  const parameters = (id, additions) => {
    const old = find(id).properties.PARAMETRS || prop([], [])
    const list = (old.VALUE || []).map((code, i) => [code, old.DESCRIPTION?.[i] || ''])
    for (const [code, value, title, description = ''] of additions) {
      const entry = [code, `${typeof value === 'string' ? value : JSON.stringify(value)}|${title}|${description}`]
      const i = list.findIndex(row => row[0] === code)
      if (i >= 0) list[i] = entry; else list.push(entry)
    }
    change(id, { PARAMETRS: pairs(list.map(row => row[0]), list.map(row => row[1])) })
  }

  // Exact site tariffs for ONE side are the source of the new pass table.
  // Missing three-ink tariff is deliberately not estimated.
  const rateRows = [null, find(1071), find(18856), null, find(1067)]
  const rates = rateRows.map((e, i) => i === 0 ? 0 : e?.purchasingPrice ?? null)
  parameters(1051, [
    ...[1, 2, 4].map(n => [`OFFSET_PASS_COST_${n}`, rates[n], `Закупка одного ${n}-красочного листопрогона (руб)`, 'Перенесено из действующего одностороннего варианта операции. Это полная ставка, без дополнительной платы за красочные секции.']),
  ])
  parameters(1082, [
    ['PRINTING_UNITS', 4, 'Количество печатных секций'],
    ['QUANTITY_SHEETS_MAKEREADY', 150, 'Бумага на приладку одного комплекта форм (шт)', 'Подтверждено владельцем 06.09.2026. На комплект форм, не на цвет.'],
    ['EXTRA_THICK_KOEF', 1.5, 'Коэффициент работы на толстом материале', 'Существующая норма цеха; применяется один раз к печатным работам.'],
    ['SHEET_HEIGHT_FOR_EXTRA', 0.2, 'Порог толщины для усложнения печати (мм)', 'Существующая норма цеха. Плотность г/м² не подменяет толщину.'],
    ['WORK_AND_TURN_AXIS', 'length', 'Ось разделения спуска своим оборотом', 'В принятой карточкой ориентации 375×520 мм боковая сторона машины соответствует длине 520 мм. Делится печатная область по длине, клапан сохраняется.'],
    ['WORK_AND_TURN_ALLOWED', true, 'Разрешён свой оборот с сохранением клапана'],
    ['HAS_AUTOMATIC_PERFECTING', false, 'Автоматический переворот листа', 'Ryobi 524HXX: прямое исполнение. Двусторонний заказ проходит машину дважды.'],
    ['OFFSET_RUN_WASTE_PERCENT', 2, 'Потери одного тиражного прогона (%)', 'Перенесено из действующего расчёта материала; производственная норма, требует последующей сверки по фактическим заказам.'],
  ])
  parameters(1074, [
    ...MAKEREADY_COMPONENTS,
    ['TURN_REGISTER_EXTRA_PERCENT', 30, 'Доплата за повторную приводку своим оборотом (%)', 'Перенесено из параметра этапа EXTRA_TURN_OVER; применяется к приладке без второго комплекта форм.'],
    ['INCLUDED_PRODUCTION_SHEETS', 0, 'Годные тиражные листы, включённые в приладку (шт)', 'Бумага, потраченная на приладку, не является бесплатными годными тиражными листами.'],
  ])
  change(1074, {}, 'Приладка одного комплекта форм Ryobi 524HXX')
  // Only material variants already selected by this working graph receive a
  // neutral, editable surface factor. No internet-derived tariff is invented.
  const paperIds = [18449,18300,18476,18450,18303,18451,18298,18299,18301,18302,18453,18454]
  for (const id of paperIds) parameters(id, [['OFFSET_WORK_FACTOR', 1, 'Коэффициент печатных работ для поверхности материала', 'Базовый материал: 1. Дополнительное замедление на пылящей, фактурной или плохо закрепляющей краску поверхности задаётся по замеру цеха; толщина учитывается отдельно.']])
  const materialTree = readJson(find(refs.material).properties.OPTIONS_MATERIAL.VALUE)
  const paperTypeNode = materialTree.tree.branches.find(b=>b.option_id==='paper').child
  paperTypeNode.branches.find(b=>b.option_id==='vhi-paper').child.branches.find(b=>b.option_id==='80').child.result.entity_id=18454
  const glossy = paperTypeNode.branches.find(b=>b.option_id==='mel-glossy-paper').child
  glossy.branches = glossy.branches.filter(b=>b.option_id!=='120')
  change(refs.material,{OPTIONS_MATERIAL:prop(JSON.stringify(materialTree))})
  for (const [id,originalId] of [[18191,5618],[18192,5619]]) {
    change(id,{})
    Object.assign(changes.get(id),{expectedCatalogProduct:{WEIGHT:find(id).attributes.weight},catalogProduct:{WEIGHT:find(originalId).attributes.weight}})
  }
  change(18315,{},'Готовая печатная пластина для Ryobi 524HXX')
  global('offset_job_complexity_factor', 'Коэффициент особых условий печати', 'constant', '1', 'number', 'По умолчанию 1. Для заливок, сложной приводки, проблем с подачей и состоянием бумаги — подтверждённая норма цеха. Не заменяет проверку пригодности материала.')
  global('print_vibrancy_text', 'Красочность печати', 'constant', 'get(input, "values.color.scheme")', 'string')
  global('print_method_text', 'Способ печати', 'constant', 'get(input, "values.method")', 'string')
  global('finished_item_qty', 'Общее количество готовых изделий (все макеты)', 'constant', 'toNumber(get(input, "values.volume")) * toNumber(get(input, "values.system.layout-count"))')
  global('is_coated_paper', 'Признак мелованной бумаги', 'constant', 'get(input, "values.type.paper") == "mel-mat-paper" || get(input, "values.type.paper") == "mel-glossy-paper" || get(input, "values.type.paper") == "mel-half-glossy-paper"', 'boolean')
  global('offset_shared_ink_set', 'Общая палитра красок лица и оборота', 'constant', 'true', 'boolean', 'Для CMYK и чёрного — да. Если на сторонах разные смесевые краски, установите false: потребуется сумма красок. Не определяет одинаковость изображений.')
  global('offset_work_and_turn_allowed', 'Разрешить подбор спуска своим оборотом', 'constant', 'true', 'boolean', 'Отключить для неподходящего спуска, фальцовки целым листом, несовместимых сторон или особых требований к приводке.')
  global('sheet_setup_allowance_qty', 'Общий технологический запас печатных листов (шт)', 'constant', '1')
  global('postpress_setup_waste_sheet_qty', 'Листы на подготовку послепечатной обработки (шт)', 'constant', '1')
  global('postpress_yield_ratio', 'Выход годной продукции после обработки', 'constant', '0.98', 'number', 'Существующая норма: 0.98 = 98%. Запас рассчитывается до печати; отдельно от приладочной макулатуры.')
  global('print_sheet_trim_allowance_mm', 'Допустимая подрезка исходного листа (мм)', 'constant', '5')

  const material = new FormulaBlock('Материал и план печати')
  material.input(
    number('original_sheet_width_mm', 'Ширина исходного листа (мм)', 'stage_16827.materialVariant.attributes.width'),
    number('original_sheet_length_mm', 'Длина исходного листа (мм)', 'stage_16827.materialVariant.attributes.length'),
    any('source_sheet_weight_g', 'Масса исходного листа (г)', 'stage_16827.materialVariant.attributes.weight'),
    number('source_thickness_um', 'Точная толщина материала (мкм)', 'stage_16827.materialVariant.properties.PARAMETRS.DESCRIPTION.CODE.thickness%2Eum'),
    number('paper_work_factor', 'Коэффициент поверхности материала', 'stage_16827.materialVariant.properties.PARAMETRS.DESCRIPTION.CODE.OFFSET_WORK_FACTOR'),
    number('requested_density', 'Выбранная плотность бумаги (г/м²)', 'input.values.density.paper'),
    number('source_sheet_density_g_m2', 'Плотность материала (г/м²)', 'stage_16827.materialVariant.properties.PARAMETRS.DESCRIPTION.CODE.density%2Enum'),
    number('source_sheet_purchase_price_rub', 'Закупка исходного листа (руб)', 'stage_16827.materialVariant.purchasingPrice'),
    number('source_sheet_sale_price_rub', 'Базовая цена исходного листа (руб)', 'stage_16827.materialVariant.prices.TYPE.1.price'),
    number('material_id', 'ID печатной основы', 'stage_16827.materialVariant.id'),
    any('machine', 'Характеристики выбранной печатной машины', 'stage_16827.equipment.selectionFacts'),
    number('horizontal_margins_mm', 'Поля по ширине листа (мм)', 'stage_16827.equipment.properties.FIELDS.VIRTUAL.HORIZONTAL_SUM'),
    number('vertical_margins_mm', 'Поля по длине листа (мм)', 'stage_16827.equipment.properties.FIELDS.VIRTUAL.VERTICAL_SUM'),
    number('quantity', 'Тираж одного макета (шт)', 'input.values.volume'),
    number('layout_count', 'Количество самостоятельных макетов', 'input.values.system.layout-count'),
    globals('finished_item_width_mm', 'Ширина изделия (мм)'), globals('finished_item_length_mm', 'Длина изделия (мм)'),
    globals('bleeds_usual_one_side_mm', 'Вылет с одной стороны (мм)'), globals('print_vibrancy_text', 'Красочность', 'string'),
    globals('is_ofsetnaya_pechat', 'Офсетная технология', 'bool'),
    globals('offset_job_complexity_factor', 'Коэффициент особых условий'),
    globals('offset_shared_ink_set', 'Общая палитра сторон', 'bool'), globals('offset_work_and_turn_allowed', 'Разрешить свой оборот', 'bool'),
    globals('sheet_setup_allowance_qty', 'Общий запас (шт)'), globals('postpress_setup_waste_sheet_qty', 'Подготовка обработки (шт)'),
    globals('postpress_yield_ratio', 'Послепечатный выход'), globals('print_sheet_trim_allowance_mm', 'Подрезка исходного листа (мм)'),
    number('plate_purchase_rate', 'Закупка готовой пластины (руб)', 'stage_18854.materialVariant.purchasingPrice'),
    ...MAKEREADY_COMPONENTS.map(([code,,title],i)=>number(MAKEREADY_ALIASES[i],title,`stage_16434.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.${code}`)),
    number('turn_setup_extra_pct', 'Повторная приводка своим оборотом (%)', 'stage_16434.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.TURN_REGISTER_EXTRA_PERCENT'),
    ...[1, 2, 4].map(n => number(`pass_cost_${n}`, `Закупка ${n}-красочного прогона (руб)`, `stage_16433.operation.properties.PARAMETRS.DESCRIPTION.CODE.OFFSET_PASS_COST_${n}`)),
  )
  const m = (...args) => material.add(...args)
  for (const v of materialMakereadyVariables()) m(v.name, v.formula, v.title, v.type || 'number')
  m('source_sheet_thickness_mm', 'source_thickness_um / 1000', 'Толщина материала без округления до сотых (мм)')
  m('front_colors', 'toNumber(get(split(print_vibrancy_text, "+"), 0))', 'Красок лица')
  m('back_colors', 'toNumber(get(split(print_vibrancy_text, "+"), 1))', 'Красок оборота')
  m('ink_union', 'if(offset_shared_ink_set, max(front_colors, back_colors), front_colors + back_colors)', 'Красок общего комплекта')
  m('max_width', 'toNumber(get(machine, "module.MAX_WIDTH.value"))', 'Максимальная ширина машины (мм)')
  m('max_length', 'toNumber(get(machine, "module.MAX_LENGTH.value"))', 'Максимальная длина машины (мм)')
  m('min_width', 'toNumber(get(machine, "module.MIN_WIDTH.value"))', 'Минимальная ширина машины (мм)')
  m('min_length', 'toNumber(get(machine, "module.MIN_LENGTH.value"))', 'Минимальная длина машины (мм)')
  m('units', 'if(is_ofsetnaya_pechat, toNumber(get(machine, "selectionFacts.parameters.PRINTING_UNITS.value")), 4)', 'Печатных секций')
  m('perfecting', 'is_ofsetnaya_pechat && (get(machine, "selectionFacts.parameters.HAS_AUTOMATIC_PERFECTING.value") == true || get(machine, "selectionFacts.parameters.HAS_AUTOMATIC_PERFECTING.value") == "true")', 'Автоматический переворот', 'bool')
  m('setup_sheets', 'if(is_ofsetnaya_pechat, toNumber(get(machine, "selectionFacts.parameters.QUANTITY_SHEETS_MAKEREADY.value")), 1)', 'Листов на комплект форм')
  m('run_waste_pct', 'if(is_ofsetnaya_pechat, toNumber(get(machine, "selectionFacts.parameters.OFFSET_RUN_WASTE_PERCENT.value")), 2)', 'Потери одного прогона (%)')
  m('run_yield', '1 - run_waste_pct / 100', 'Выход одного прогона')
  m('thick_factor', 'if(is_ofsetnaya_pechat, if(source_sheet_thickness_mm > toNumber(get(machine, "selectionFacts.parameters.SHEET_HEIGHT_FOR_EXTRA.value")), toNumber(get(machine, "selectionFacts.parameters.EXTRA_THICK_KOEF.value")), 1) * paper_work_factor * offset_job_complexity_factor, 1)', 'Коэффициент толщины, поверхности и условий печати')
  m('turn_enabled', 'is_ofsetnaya_pechat && back_colors > 0 && offset_work_and_turn_allowed && (get(machine, "selectionFacts.parameters.WORK_AND_TURN_ALLOWED.value") == true || get(machine, "selectionFacts.parameters.WORK_AND_TURN_ALLOWED.value") == "true") && ink_union <= units', 'Свой оборот разрешён', 'bool')
  m('split_on_length', 'is_ofsetnaya_pechat && get(machine, "selectionFacts.parameters.WORK_AND_TURN_AXIS.value") == "length"', 'Разделение спуска по длине', 'bool')
  m('item_pitch_w', 'finished_item_width_mm + 2 * bleeds_usual_one_side_mm', 'Шаг изделия по ширине (мм)')
  m('item_pitch_l', 'finished_item_length_mm + 2 * bleeds_usual_one_side_mm', 'Шаг изделия по длине (мм)')
  const costFor = n => `if(${n} == 0, 0, if(${n} == 1, toNumber(pass_cost_1), if(${n} == 2, toNumber(pass_cost_2), if(${n} == 4, toNumber(pass_cost_4), -1))))`
  m('rate_front', `if(is_ofsetnaya_pechat, ${costFor('front_colors')}, 0)`, 'Закупочная ставка прогона лица (руб)')
  m('rate_back', `if(is_ofsetnaya_pechat, ${costFor('back_colors')}, 0)`, 'Закупочная ставка прогона оборота (руб)')
  m('rate_turn', `if(turn_enabled, ${costFor('ink_union')}, 0)`, 'Закупочная ставка прогона своим оборотом (руб)')
  m('input_valid', 'quantity > 0 && floor(quantity) == quantity && requested_density == source_sheet_density_g_m2 && paper_work_factor >= 1 && offset_job_complexity_factor >= 1 && floor(front_colors) == front_colors && floor(back_colors) == back_colors && layout_count >= 1 && floor(layout_count) == layout_count && original_sheet_width_mm > 0 && original_sheet_length_mm > 0 && max_width > 0 && max_length > 0 && finished_item_width_mm > 0 && finished_item_length_mm > 0 && bleeds_usual_one_side_mm >= 0 && source_sheet_thickness_mm > 0 && source_sheet_density_g_m2 > 0 && postpress_yield_ratio > 0 && postpress_yield_ratio <= 1 && run_yield > 0 && run_yield <= 1 && (!is_ofsetnaya_pechat || (front_colors >= 1 && front_colors <= units && back_colors >= 0 && back_colors <= units && !perfecting && rate_front > 0 && (back_colors == 0 || rate_back > 0) && setup_purchase_rate > 0 && plate_purchase_rate > 0))', 'Исходные данные технически допустимы', 'bool')
  const candidateCodes = []
  for (const [orientation, sw, sl] of [['a', 'original_sheet_width_mm', 'original_sheet_length_mm'], ['b', 'original_sheet_length_mm', 'original_sheet_width_mm']]) {
    m(`${orientation}_width`, `min(max_width, floor(${sw} / ceil(${sw} / (max_width + print_sheet_trim_allowance_mm))))`, `Ширина листа, раскрой ${orientation.toUpperCase()} (мм)`)
    m(`${orientation}_length`, `min(max_length, floor(${sl} / ceil(${sl} / (max_length + print_sheet_trim_allowance_mm))))`, `Длина листа, раскрой ${orientation.toUpperCase()} (мм)`)
    m(`${orientation}_source_yield`, `floor(${sw} / ${orientation}_width) * floor(${sl} / ${orientation}_length)`, `Листов из исходного, раскрой ${orientation.toUpperCase()}`)
    for (const turn of [false, true]) {
      const c = `${orientation}_${turn ? 'turn' : 'separate'}`
      candidateCodes.push(c)
      const title = `${orientation.toUpperCase()}: ${turn ? 'свой оборот' : 'раздельные формы'}`
      m(`${c}_area_w`, turn ? `(${orientation}_width - horizontal_margins_mm) / if(split_on_length, 1, 2)` : `${orientation}_width - horizontal_margins_mm`, `${title}: ширина зоны (мм)`)
      m(`${c}_area_l`, turn ? `(${orientation}_length - vertical_margins_mm) / if(split_on_length, 2, 1)` : `${orientation}_length - vertical_margins_mm`, `${title}: длина зоны (мм)`)
      m(`${c}_n0`, `max(0, floor(${c}_area_w / item_pitch_w)) * max(0, floor(${c}_area_l / item_pitch_l))`, `${title}: выход без поворота`)
      m(`${c}_n1`, `max(0, floor(${c}_area_w / item_pitch_l)) * max(0, floor(${c}_area_l / item_pitch_w))`, `${title}: выход с поворотом`)
      m(`${c}_rotated`, `${c}_n1 > ${c}_n0`, `${title}: поворот изделия`, 'bool')
      m(`${c}_capacity`, `max(${c}_n0, ${c}_n1) * ${turn ? 2 : 1}`, `${title}: готовых изделий на лист`)
      m(`${c}_valid`, `input_valid && ${orientation}_width >= min_width && ${orientation}_length >= min_length && ${c}_capacity > 0 && ${orientation}_source_yield > 0${turn ? ' && turn_enabled && rate_turn > 0' : ''}`, `${title}: допустимость`, 'bool')
      m(`${c}_net`, `ceil(quantity / max(1, ${c}_capacity))`, `${title}: чистые листы`)
      m(`${c}_good`, `ceil(${c}_net / max(0.0001, postpress_yield_ratio)) + postpress_setup_waste_sheet_qty + sheet_setup_allowance_qty`, `${title}: годные листы на выходе печати`)
      m(`${c}_setup1`, 'setup_sheets', `${title}: приладочные листы лица`)
      m(`${c}_setup2`, turn ? '0' : 'if(back_colors > 0, setup_sheets, 0)', `${title}: приладочные листы оборота`)
      m(`${c}_feed2`, `if(back_colors > 0, ceil(${c}_good / max(0.0001, run_yield)), 0)`, `${title}: тиражных листов второго прогона`)
      m(`${c}_feed1`, `ceil(if(back_colors > 0, ${c}_feed2 + ${c}_setup2, ${c}_good) / max(0.0001, run_yield))`, `${title}: тиражных листов первого прогона`)
      m(`${c}_stock`, `${c}_feed1 + ${c}_setup1`, `${title}: всего исходных печатных листов`)
      m(`${c}_source`, `ceil(${c}_stock / max(1, ${orientation}_source_yield))`, `${title}: исходных листов на один макет`)
      m(`${c}_plates`, turn ? 'ink_union' : 'front_colors + back_colors', `${title}: пластин на макет`)
      m(`${c}_sets`, turn ? '1' : 'if(back_colors > 0, 2, 1)', `${title}: комплектов форм на макет`)
      m(`${c}_setup_cost`, `${c}_sets * setup_purchase_rate * thick_factor${turn ? ' * (1 + turn_setup_extra_pct / 100)' : ''}`, `${title}: приладка (руб)`)
      m(`${c}_run_cost`, turn ? `(${c}_feed1 + ${c}_feed2) * rate_turn * thick_factor` : `(${c}_feed1 * rate_front + ${c}_feed2 * rate_back) * thick_factor`, `${title}: печатные прогоны (руб)`)
      m(`${c}_cost`, `if(${c}_valid, ${c}_source * source_sheet_purchase_price_rub + if(is_ofsetnaya_pechat, ${c}_plates * plate_purchase_rate + ${c}_setup_cost + ${c}_run_cost, 0), 100000000000000000000)`, `${title}: сравниваемая себестоимость (руб)`)
    }
  }
  m('best_a', 'if(a_turn_cost <= a_separate_cost && a_turn_valid, 1, 0)', 'Лучший оборот для раскроя А')
  m('best_b', 'if(b_turn_cost <= b_separate_cost && b_turn_valid, 3, 2)', 'Лучший оборот для раскроя Б')
  m('selected_index', 'if(min(a_turn_cost, a_separate_cost) <= min(b_turn_cost, b_separate_cost), best_a, best_b)', 'Индекс выбранного плана')
  const select = (suffix) => `if(selected_index == 0, a_separate_${suffix}, if(selected_index == 1, a_turn_${suffix}, if(selected_index == 2, b_separate_${suffix}, b_turn_${suffix})))`
  m('plan_valid', select('valid'), 'Найден допустимый план', 'bool')
  m('plan_guard', 'if(plan_valid, 1, 1 / 0)', 'Проверка возможности расчёта', 'number', 'Недопустимый формат, неутверждённый тариф или неполные характеристики должны остановить расчёт, а не дать нулевую цену.')
  m('selected_turn', 'selected_index == 1 || selected_index == 3', 'Выбран свой оборот', 'bool')
  m('print_width', 'if(selected_index < 2, a_width, b_width) * plan_guard', 'Ширина выбранного печатного листа (мм)')
  m('print_length', 'if(selected_index < 2, a_length, b_length) * plan_guard', 'Длина выбранного печатного листа (мм)')
  m('source_yield', 'if(selected_index < 2, a_source_yield, b_source_yield)', 'Печатных листов с исходного')
  for (const [code, suffix, title] of [
    ['items_per_sheet', 'capacity', 'Изделий с печатного листа'], ['net_sheets', 'net', 'Чистых печатных листов'],
    ['good_sheets', 'good', 'Годных листов после печати'], ['feed1', 'feed1', 'Тиражных листов первого прогона'],
    ['feed2', 'feed2', 'Тиражных листов второго прогона'], ['stock_sheets', 'stock', 'Печатных листов с отходами'],
    ['source_sheets', 'source', 'Исходных листов'], ['plate_qty', 'plates', 'Готовых пластин'], ['set_qty', 'sets', 'Комплектов форм'],
    ['setup_cost', 'setup_cost', 'Закупочная стоимость приладки (руб)'], ['run_cost', 'run_cost', 'Закупочная стоимость прогонов (руб)'],
  ]) m(code, `${select(suffix)} * ${['items_per_sheet'].includes(code) ? 'plan_guard' : 'layout_count * plan_guard'}`, title)
  m('selected_rotated', select('rotated'), 'Выбран поворот изделия', 'bool')
  m('grid_columns', `floor(${select('area_w')} / if(selected_rotated, item_pitch_l, item_pitch_w)) * if(selected_turn && !split_on_length, 2, 1)`, 'Колонок в фактическом спуске')
  m('grid_rows', `floor(${select('area_l')} / if(selected_rotated, item_pitch_w, item_pitch_l)) * if(selected_turn && split_on_length, 2, 1)`, 'Рядов в фактическом спуске')
  m('layout_width', 'if(selected_turn && !split_on_length, print_width - horizontal_margins_mm, grid_columns * if(selected_rotated, item_pitch_l, item_pitch_w))', 'Ширина занятой раскладки (мм)')
  m('layout_length', 'if(selected_turn && split_on_length, print_length - vertical_margins_mm, grid_rows * if(selected_rotated, item_pitch_w, item_pitch_l))', 'Длина занятой раскладки (мм)')
  m('source_weight', 'if(source_sheet_weight_g > 0, source_sheet_weight_g, original_sheet_width_mm * original_sheet_length_mm * source_sheet_density_g_m2 / 1000000)', 'Масса исходного листа (г)')
  m('one_sheet_weight', 'source_weight * print_width * print_length / (original_sheet_width_mm * original_sheet_length_mm)', 'Масса одного печатного листа (г)')
  m('material_cost', 'source_sheets * source_sheet_purchase_price_rub', 'Закупочная стоимость всей бумаги (руб)')
  m('material_base', 'source_sheets * source_sheet_sale_price_rub', 'Базовая стоимость всей бумаги (руб)')
  m('turn_name', 'if(back_colors == 0, "Без оборота", if(selected_turn, "Свой оборот", "Чужой оборот"))', 'Способ оборота', 'string')
  m('is_cut_required', 'min(original_sheet_width_mm, original_sheet_length_mm) != min(print_width, print_length) || max(original_sheet_width_mm, original_sheet_length_mm) != max(print_width, print_length)', 'Нужен раскрой исходного листа', 'bool')
  m('source_cut_columns', 'floor(if(selected_index < 2, original_sheet_width_mm, original_sheet_length_mm) / print_width)', 'Полос при раскрое по ширине')
  m('source_cut_rows', 'floor(if(selected_index < 2, original_sheet_length_mm, original_sheet_width_mm) / print_length)', 'Полос при раскрое по длине')
  m('source_cut_lines', 'if(is_cut_required, source_cut_columns + source_cut_rows - 2 + if(if(selected_index < 2, original_sheet_width_mm, original_sheet_length_mm) > source_cut_columns * print_width, 1, 0) + if(if(selected_index < 2, original_sheet_length_mm, original_sheet_width_mm) > source_cut_rows * print_length, 1, 0), 0)', 'Линий подготовительного раскроя на стопу')
  const publications = [
    ['print_sheet_width_mm', 'print_width', 'Ширина текущего полуфабриката (мм)', 'variable'],
    ['print_sheet_length_mm', 'print_length', 'Длина текущего полуфабриката (мм)', 'variable'],
    ['print_sheet_thickness_mm', 'source_sheet_thickness_mm', 'Толщина одного текущего листа (мм)', 'variable'],
    ['print_sheet_weight_g', 'one_sheet_weight', 'Масса одного текущего листа (г)', 'variable'],
    ['print_sheet_width_initial_mm', 'print_width', 'Ширина печатного листа до обработки (мм)', 'constant'],
    ['print_sheet_length_initial_mm', 'print_length', 'Длина печатного листа до обработки (мм)', 'constant'],
    ['print_sheet_thickness_initial_mm', 'source_sheet_thickness_mm', 'Толщина печатного листа до обработки (мм)', 'constant'],
    ['print_sheet_weight_initial_g', 'one_sheet_weight', 'Масса печатного листа до обработки (г)', 'constant'],
    ['print_sheet_qty', 'net_sheets', 'Чистое количество печатных листов (шт)', 'constant'],
    ['print_sheet_qty_with_waste', 'stock_sheets', 'Печатные листы с отходами печати (шт)', 'constant'],
    ['postpress_sheet_qty', 'good_sheets', 'Годные печатные листы для обработки (шт)', 'constant'],
    ['source_material_sheet_qty', 'source_sheets', 'Количество исходных листов материала (шт)', 'constant'],
    ['source_cut_line_qty', 'source_cut_lines', 'Линий раскроя исходной стопы', 'constant'],
    ['items_per_printsheet', 'items_per_sheet', 'Готовых изделий с печатного листа (шт)', 'constant'],
    ['printsheets_per_source_sheet', 'source_yield', 'Печатных листов с исходного листа (шт)', 'constant'],
    ['id_materiala_pechatnoy_osnovy', 'material_id', 'ID печатной основы', 'constant'],
    ['is_source_sheet_cutting_required', 'is_cut_required', 'Нужен раскрой исходного листа', 'constant', 'bool'],
    ['is_item_rotated', 'selected_rotated', 'Поворот изделия в спуске', 'constant', 'bool'],
    ['print_layout_width_mm', 'layout_width', 'Ширина занятой печатной раскладки (мм)', 'constant'],
    ['print_layout_length_mm', 'layout_length', 'Длина занятой печатной раскладки (мм)', 'constant'],
    ['print_layout_columns', 'grid_columns', 'Колонок в печатном спуске', 'constant'], ['print_layout_rows', 'grid_rows', 'Рядов в печатном спуске', 'constant'],
    ['tehnologicheskie_polya_po_gorizontali_mm', 'horizontal_margins_mm', 'Поля по ширине листа (мм)', 'constant'],
    ['tehnologicheskie_polya_po_vertikali_mm', 'vertical_margins_mm', 'Поля по длине листа (мм)', 'constant'],
    ['paper_purchase_price_per_sheet_rub', 'source_sheet_purchase_price_rub / source_yield', 'Закупка одного печатного листа (руб)', 'constant'],
    ['paper_sale_price_per_sheet_rub', 'source_sheet_sale_price_rub / source_yield', 'Базовая цена одного печатного листа (руб)', 'constant'],
    ['offset_work_and_turn_enabled', 'selected_turn', 'Выбран свой оборот', 'variable', 'bool'],
    ['offset_turn_mode', 'turn_name', 'Способ оборота', 'variable', 'string'],
    ['offset_front_color_qty', 'front_colors', 'Красок лица', 'variable'], ['offset_back_color_qty', 'back_colors', 'Красок оборота', 'variable'],
    ['offset_print_side_qty', 'if(back_colors > 0, 2, 1)', 'Печатных сторон', 'variable'],
    ['offset_print_form_qty', 'set_qty', 'Комплектов офсетных форм', 'variable'], ['offset_plate_qty', 'plate_qty', 'Готовых офсетных пластин (шт)', 'variable'],
    ['offset_first_pass_sheet_qty', 'feed1', 'Тиражных листов первого прогона (шт)', 'variable'], ['offset_second_pass_sheet_qty', 'feed2', 'Тиражных листов второго прогона (шт)', 'variable'],
    ['offset_first_pass_colors', 'if(selected_turn, ink_union, front_colors)', 'Активных красок первого прогона', 'variable'],
    ['offset_second_pass_colors', 'if(selected_turn, ink_union, back_colors)', 'Активных красок второго прогона', 'variable'],
    ['offset_setup_purchase_cost', 'setup_cost', 'Закупочная стоимость офсетной приладки (руб)', 'variable'],
    ['offset_run_purchase_cost', 'run_cost', 'Закупочная стоимость офсетных прогонов (руб)', 'variable'],
    ['offset_print_complexity_factor', 'thick_factor', 'Коэффициент сложности печати', 'variable'],
    ['offset_reference_sheet_purchase_rate', 'rate_front + rate_back', 'Базовая закупка печати листа раздельными формами (руб)', 'variable'],
  ]
  for (const [code, formula, title, kind, type = 'number'] of publications) {
    material.global(code, formula, title, kind, type)
    global(code, title, kind, kind === 'variable' ? type === 'bool' ? 'false' : type === 'string' ? '""' : '0' : '', type === 'bool' ? 'boolean' : type)
  }
  material.output('width', 'print_width'); material.output('length', 'print_length')
  material.output('height', 'source_sheet_thickness_mm'); material.output('weight', 'one_sheet_weight')
  material.output('materialPurchasingPrice', 'material_cost'); material.output('materialBasePrice', 'material_base')
  for (const code of ['turn_name', 'items_per_sheet', 'net_sheets', 'good_sheets', 'stock_sheets', 'source_sheets', 'feed1', 'feed2', 'plate_qty', 'set_qty', 'one_sheet_weight']) material.extra(code, material.variables.find(v => v.code === code).title)

  const plates = new FormulaBlock('Готовые офсетные пластины')
  plates.input(globals('offset_plate_qty', 'Количество готовых пластин'),
    number('unit_cost', 'Закупка готовой пластины (руб)', 'stage_18854.materialVariant.purchasingPrice'),
    { code: 'unit_prices', title: 'Отпускная шкала готовой пластины', type: 'array', sourcePath: 'stage_18854.materialVariant.prices' })
  plates.add('plate_material_cost', 'offset_plate_qty * unit_cost', 'Закупочная стоимость готовых пластин (руб)')
  plates.add('plate_material_base', 'offset_plate_qty * getPrice(offset_plate_qty, unit_prices, true)', 'Базовая стоимость готовых пластин (руб)')
  plates.output('materialPurchasingPrice', 'plate_material_cost'); plates.output('materialBasePrice', 'plate_material_base')

  const setup = new FormulaBlock('Приладка офсетной печати')
  setup.input(globals('offset_setup_purchase_cost', 'Стоимость приладки по выбранному плану'), globals('offset_plate_qty', 'Прилаживаемых красок'), globals('offset_print_form_qty', 'Комплектов форм'),
    { code: 'setup_prices', title: 'Процент за цвет по количеству комплектов форм', type: 'array', sourcePath: 'stage_16434.operationVariant.prices' })
  setup.add('setup_cost', 'offset_setup_purchase_cost', 'Закупочная стоимость приладки (руб)')
  setup.add('setup_markup', 'getPrice(offset_print_form_qty, setup_prices)', 'Наценка за один цвет (%)')
  setup.add('setup_base', 'round(setup_cost * (1 + offset_plate_qty / offset_print_form_qty * setup_markup / 100) * 100) / 100', 'Базовая стоимость приладки (руб)', 'number', 'Себестоимость берётся за комплект форм; процент — за каждый цвет комплекта. Шкала процента зависит от количества комплектов. Один спуск 4+0: 2012 * (1 + 4 * 15 / 100) = 3219.20 руб.')
  setup.input(...MAKEREADY_COMPONENTS.map(([code,,title],i)=>number(MAKEREADY_ALIASES[i],title,`stage_16434.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.${code}`)))
  for (const [name,formula,title] of MAKEREADY_REPORT_VARIABLES) {setup.add(name,formula,title);setup.extra(name,title)}
  setup.output('operationPurchasingPrice', 'setup_cost'); setup.output('operationBasePrice', 'setup_base')

  const print = new FormulaBlock('Офсетная печать — фактические прогоны')
  print.input(...['offset_run_purchase_cost', 'offset_first_pass_sheet_qty', 'offset_second_pass_sheet_qty', 'offset_first_pass_colors', 'offset_second_pass_colors', 'offset_print_complexity_factor', 'offset_reference_sheet_purchase_rate'].map(code => globals(code, globalRows.find(g => g.code === code).title)),
    globals('print_vibrancy_text', 'Красочность заказа', 'string'), text('selected_vibrancy', 'Красочность выбранного тарифа', 'stage_16433.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.VIBRANCY'),
    { code: 'sheet_prices', title: 'Отпускная шкала печати листа выбранной красочности', type: 'array', sourcePath: 'stage_16433.operationVariant.prices' })
  print.add('run_cost', 'if(selected_vibrancy == print_vibrancy_text, offset_run_purchase_cost, 1 / 0)', 'Себестоимость тиражных прогонов (руб)')
  print.add('reference_sale_rate', 'getPrice(offset_first_pass_sheet_qty, sheet_prices, true)', 'Ставка выбранной красочности (руб за лист)')
  print.add('run_base', 'run_cost / offset_reference_sheet_purchase_rate * reference_sale_rate', 'Базовая стоимость печати (руб)', 'number', 'Действующая отпускная шкала за готовый лист переводится в фактическую стоимость выбранных прогонов пропорционально их закупочным ставкам. Стороны и коэффициент толщины повторно не умножаются.')
  print.add('physical_passes', 'offset_first_pass_sheet_qty + offset_second_pass_sheet_qty', 'Тиражные листопрогоны (шт)')
  print.add('color_impressions', 'offset_first_pass_sheet_qty * offset_first_pass_colors + offset_second_pass_sheet_qty * offset_second_pass_colors', 'Красочные оттиски (шт)')
  print.output('operationPurchasingPrice', 'run_cost'); print.output('operationBasePrice', 'run_base')
  print.extra('physical_passes', 'Тиражные листопрогоны (шт)'); print.extra('color_impressions', 'Красочные оттиски (шт)')

  const titledGlobal = (code, title, type) => globals(code, globalRows.find(g=>g.code===code)?.title || title, type)
  const blocks = { material, plates, setup, print, ...buildFinishing({ FormulaBlock, number, text, any, globals:titledGlobal, parameters, global }) }
  // Selection ranges are authored from actual box geometry. The packing stage
  // independently checks the selected box, so edited dimensions cannot overfill it.
  const boxRule = (id, longMin, longMax, shortMin, shortMax) => ({input_values:{},metric_ranges:{'output:packing_long_side':{min:longMin,max:longMax},'output:packing_short_side':{min:shortMin,max:shortMax}},variant_id:id})
  const small=find(18191).attributes, large=find(18192).attributes
  const sw=Math.max(small.width,small.length)-12, sl=Math.min(small.width,small.length)-12
  const lw=Math.max(large.width,large.length)-12, ll=Math.min(large.width,large.length)-12
  change(refs.pack,{OPTIONS_MATERIAL:prop(JSON.stringify({contract:'prospektweb.calc.stage-variant-mapping/v1',input_field_ids:[],metric_source:{detail_id:16441,stage_id:refs.cut},metric_keys:['output:packing_long_side','output:packing_short_side'],rules:[boxRule(18191,1,sw,1,sl),boxRule(18192,sw+0.000001,lw,1,ll),boxRule(18192,1,sw,sl+0.000001,ll)]}))})
  const templates = {
    material: [['Название ТП', '{self}']],
    plates: [['Готовые пластины', '{offset_plate_qty} шт']],
    setup: MAKEREADY_REPORT_TEMPLATES,
    print: [['Офсетная печать', '{print_vibrancy_text}; {offset_turn_mode}; {physical_passes} листопрогонов']],
    laminate: [['Ламинация', '{film_name}; {sides} сторон']],
    cut: [['Трудоёмкость резки', '{cut_work_unit_qty} резов'], ['Наценка резки', '{cut_markup_pct}%']],
    pack: [['Упаковка', '{package_qty} × {packaging_type}'], ['Габариты короба', '{box_width} × {box_length} × {box_height} мм']],
  }
  for (const variable of material.variables) variable.formula = variable.formula.replaceAll('selectionFacts.parameters.', 'parameters.')
  for (const [key, block] of Object.entries(blocks)) {
    change(settings[key], {
      PARAMS: pairs(block.inputs.map(i => i.code), block.inputs.map(i => `${i.type}|${i.title}|${i.description || ''}`)),
      LOGIC_JSON: jsonProperty({ version: 2, vars: block.variables.map(({ code, type, ...v }, i) => ({ id: `offset_recipe_${key}_${i}`, name: code, inferredType: type, ...v })) }),
      GLOBAL_DEPENDENCIES: pairs(block.inputs.filter(i => i.sourcePath.startsWith('globalValues.')).map(i => i.sourcePath.slice(13)), []),
    }, block.name)
    const outputEntries = [...Object.entries(block.results).map(([key, v]) => [key, v.source]), ...block.additional.map(v => [`${v.code}|${v.title}`, v.source])]
    change(refs[key], { INPUTS: pairs(block.inputs.map(i => i.code), block.inputs.map(i => i.sourcePath)), OUTPUTS: pairs(outputEntries.map(v => v[0]), outputEntries.map(v => v[1])), GLOBAL_ASSIGNMENTS: jsonProperty({ version: 1, assignments: [] }), CUSTOM_FIELDS: pairs([], []), CUSTOM_FIELDS_VALUE: pairs([], []), SCHEME_PARAMETR_VALUES: pairs(templates[key].map(r=>r[0]), templates[key].map(r=>JSON.stringify({version:2,template:r[1],writeToOffer:false}))) })
  }
  return { contract: 'prospektweb.calc.sheet-offset-recipe/v1', presetId: 12740, workingPresetId: 16411, versionId: 'v_3caf71f29edbb97234c4', expectedGlobalRevision: init.globalMutationRevision, expectedGlobalFingerprint: init.globalMutationFingerprint, changes: [...changes.values()], expectedGlobals: clone(init.globalSymbols), globals: globalRows, blocks: Object.fromEntries(Object.entries(blocks).map(([key, block]) => [key, block.export()])) }
}

if (process.argv[1] && import.meta.url === pathToFileURL(await fs.realpath(process.argv[1])).href) {
  const [input, output] = process.argv.slice(2)
  const snapshot = JSON.parse(await fs.readFile(input, 'utf8'))
  const recipe = buildRecipe(snapshot.data || snapshot)
  await fs.mkdir(output, { recursive: true })
  await fs.writeFile(path.join(output, 'recipe.json'), JSON.stringify(recipe, null, 2))
  for (const [key, block] of Object.entries(recipe.blocks)) await fs.writeFile(path.join(output, `${key}.logic-import.json`), JSON.stringify(block, null, 2))
  console.log(JSON.stringify({ elements: recipe.changes.length, globals: recipe.globals.length, formulas: Object.fromEntries(Object.entries(recipe.blocks).map(([key, b]) => [key, b.variables.length])) }))
}
