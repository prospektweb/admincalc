export const MAKEREADY_COMPONENTS = [
  ['MAKEREADY_SHIFT_LABOR_RUB', 5670, 'ФОТ смены для норматива приладки (руб/смена)'],
  ['MAKEREADY_SHIFT_RENT_RUB', 4050, 'Аренда для норматива приладки (руб/смена)'],
  ['MAKEREADY_SHIFT_CONSUMABLES_RUB', 1620, 'Расходные материалы приладки (руб/смена)'],
  ['MAKEREADY_PLANNED_SETS_PER_SHIFT', 7.5, 'Плановое число комплектов форм (комплектов/смена)'],
  ['MAKEREADY_REPAIR_RESERVE_PER_SET_RUB', 500, 'Резерв ремонта (руб/комплект форм)'],
]

export const MAKEREADY_ALIASES = ['setup_shift_labor', 'setup_shift_rent', 'setup_shift_consumables', 'setup_shift_sets', 'setup_set_reserve']
export const MAKEREADY_RATE_FORMULA = '(setup_shift_labor + setup_shift_rent + setup_shift_consumables) / setup_shift_sets + setup_set_reserve'

export function materialMakereadyVariables() {
  return [
    {name:'setup_norm_valid',title:'Норматив приладки заполнен',formula:'setup_shift_labor >= 0 && setup_shift_rent >= 0 && setup_shift_consumables >= 0 && setup_shift_sets > 0 && setup_set_reserve >= 0',type:'bool'},
    {name:'setup_purchase_rate',title:'Себестоимость одного комплекта форм (руб)',formula:`if(setup_norm_valid, ${MAKEREADY_RATE_FORMULA}, 1 / 0)`},
  ]
}

export const MAKEREADY_REPORT_VARIABLES = [
  ['setup_shift_total','setup_shift_labor + setup_shift_rent + setup_shift_consumables','Затраты смены (руб)'],
  ['setup_unit_cost',MAKEREADY_RATE_FORMULA,'Норматив за комплект форм (руб)'],
  ['setup_factor','setup_cost / (setup_unit_cost * offset_print_form_qty)','Коэффициент сложности и повторной приводки'],
  ['setup_labor_cost','setup_shift_labor / setup_shift_sets * offset_print_form_qty * setup_factor','ФОТ в стоимости приладки (руб)'],
  ['setup_rent_cost','setup_shift_rent / setup_shift_sets * offset_print_form_qty * setup_factor','Аренда в стоимости приладки (руб)'],
  ['setup_consumables_cost','setup_shift_consumables / setup_shift_sets * offset_print_form_qty * setup_factor','Расходные материалы приладки (руб)'],
  ['setup_reserve_cost','setup_set_reserve * offset_print_form_qty * setup_factor','Резерв ремонта в стоимости приладки (руб)'],
  ['setup_average_colors','offset_plate_qty / offset_print_form_qty','Среднее число цветов комплекта'],
  ['setup_markup_rub','round((setup_base - setup_cost) * 100) / 100','Наценка за цвета (руб)'],
]

export const MAKEREADY_REPORT_TEMPLATES = [
    ['Комплекты и цвета','{offset_print_form_qty} комплектов; {offset_plate_qty} цветов'],
    ['Норматив смены','ФОТ {setup_shift_labor} + аренда {setup_shift_rent} + расходники {setup_shift_consumables} = {setup_shift_total} руб.'],
    ['Норматив за комплект','{setup_shift_total} / {setup_shift_sets} + резерв {setup_set_reserve} = {setup_unit_cost} руб.'],
    ['Коэффициент работы','{setup_factor}'],
    ['Себестоимость приладки','{setup_cost} руб.'],
    ['Наценка за цвета','{setup_average_colors} цветов/комплект × {setup_markup}%; сумма {setup_markup_rub} руб.'],
    ['Базовая стоимость приладки','{setup_base} руб.'],
  ]
