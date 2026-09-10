// Configure the owner's sheet-print document; no engine, pricing or topology changes.
const assert = require('node:assert/strict');
const q = JSON.stringify;
const value = key => `get(input, ${q('values.' + key)})`;
const text = key => `trim(toString(${value(key)}))`;
const clean = expression => `join(split(trim(toString(${expression}))), " ")`;
const enabled = key => `(${value(key)} == true || ${value(key)} == 1 || ${value(key)} == "Y")`;
function condition(rule) {
  if (!rule?.conditions?.length) return 'true';
  return '(' + rule.conditions.map(c => {
    if (c.conditions) return condition(c);
    const matches = (c.values || []).map(v => c.operator === 'contains' ? `contains(${value(c.fieldId)}, ${q(v)})` : `${text(c.fieldId)} == ${q(String(v))}`).join(' || ') || 'false';
    if (c.operator === 'not_equals') return `!(${matches})`;
    assert.ok(['equals', 'contains'].includes(c.operator), `Unsupported visibility: ${c.operator}`);
    return `(${matches})`;
  }).join(rule.mode === 'any' ? ' || ' : ' && ') + ')';
}
function configure(source) {
  const d = structuredClone(source);
  assert.equal(d.id, 'db538f5a-4cb1-8c8f-a113-93120de3f035');
  assert.equal(d.schemaVersion, 2);
  assert.ok(!d.globals.some(g => g.code.startsWith('offer_name_')), 'Naming already configured');
  const field = id => { const f = d.form.fields.find(f => f.fieldId === id); assert.ok(f, id); return f; };
  const labels = (id, overrides = {}) => (field(id).options || []).reduceRight((fallback, option) =>
    `if(${text(id)} == ${q(option.id)}, ${q(overrides[option.id] ?? option.label)}, ${fallback})`, '""');
  const visible = id => {
    const f = field(id), section = d.form.sections.find(s => s.fieldIds.includes(id));
    return [condition(f.visibleWhen), condition(section?.visibleWhen), section?.userActivatable ? enabled('section:' + section.id) : 'true'].join(' && ');
  };
  const selected = (id, overrides) => `if(${visible(id)}, ${labels(id, overrides)}, "")`;
  const add = (suffix, title, expression, description = '') => {
    const code = 'offer_name_' + suffix;
    d.globals.push({code, title, expression, description, kind:'constant', managed:null, type:'string'});
    return code;
  };
  const fmtNumber = key => `replace(${text(key)}, ".", ",")`;
  const compactUnit = (key, unit) => `if(len(${text(key)}) > 0 && ${value(key)} > 0, ${fmtNumber(key)} + ${q(unit)}, "")`;
  const name = d.globals.find(g => g.code === 'product_name');
  name.expression = clean(value('storefront:name'));
  name.description = 'Название выбранной витрины (storefront:name). Для BASE сервер передаёт название калькулятора. Количество макетов не входит в название позиции.';
  const format = d.globals.find(g => g.code === 'value_format_text');
  format.expression = `if(${value('format.width')} > 0 && ${value('format.length')} > 0, ${fmtNumber('format.width')} + "х" + ${fmtNumber('format.length')} + "мм", "")`;
  format.description = 'Готовый формат: 90х50мм; без имени пресета, лишних пробелов и единиц у каждой стороны.';
  add('header','Название позиции: изделие и формат','product_name + if(len(value_format_text) > 0, if(len(product_name) > 0, " - ", "") + value_format_text, "")');
  add('method','Название позиции: способ печати',labels('method'));
  add('color','Название позиции: красочность',labels('color.scheme'));
  add('material','Название позиции: вид материала',`if(${text('type.material')} == "paper", ${labels('type.paper')}, if(${text('type.material')} == "cardboard", ${labels('type.cartboard')}, if(${text('type.material')} == "film", "Плёнка" + if(len(${text('type.film')}) > 0, " " + ${text('type.film')}, ""), "")))`);
  add('brand','Название позиции: марка',`if(${text('type.material')} == "paper", ${selected('brand.paper',{shyne:'Шайн',acquerello:'Акверелло',myPlike:'МайПлайк',gmund_cotton:'Гмунд Коттон'})}, if(${text('type.material')} == "cardboard", ${selected('brand.cardboard',{cmpc_natural_kraft:'CMPC Natural Kraft'})}, ""))`);
  add('density','Название позиции: плотность или толщина',`if(${text('type.material')} == "paper", ${compactUnit('density.paper','г/м2')}, if(${text('type.material')} == "cardboard", ${compactUnit('density.cardboard','г/м2')}, if(${text('type.material')} == "film", ${compactUnit('thickness.film','мкм')}, "")))`);
  add('material_color','Название позиции: цвет материала',`if(${text('type.material')} == "paper", ${selected('color.paper',{gmund_cotton_max_white:'Макс Вайт',plike_white_s:'Белый',acquerello_bianco_s:'Белый',acquerello_avorio_s:'Слоновая кость'})}, if(${text('type.material')} == "cardboard", ${selected('color.cardboard')}, if(${text('type.material')} == "film", ${text('color.film')}, "")))`);
  add('filling','Название позиции: специальное заполнение',`if(${visible('filling')} && ${text('filling')} == "text", "Текст и графика", "")`);
  add('core','Название позиции: основа','offer_name_header + " | " + offer_name_method + " | " + offer_name_color + " | " + offer_name_material + " | " + offer_name_brand + " | " + offer_name_density + " | " + offer_name_material_color + " | " + offer_name_filling');
  add('protection','Название позиции: защита',`if(${visible('protection')}, if(${text('protection')} == "uf-lak", "УФ-лак", if(${text('protection')} == "lamination-rulon" || ${text('protection')} == "lamination-pocket", ${labels('protection',{ 'lamination-rulon':'Ламинация', 'lamination-pocket':'Пакетная ламинация' })} + if(len(${text('lamination')}) > 0, " " + lower(${labels('lamination',{'soft-touch':'Soft-Touch'})}), "") + if(${text('protection')} == "lamination-rulon" && (${text('lamination.sides')} == "1" || ${text('lamination.sides')} == "2"), " " + ${text('lamination.sides')} + "ст", ""), "")), "")`, 'Только активная и видимая защита. Тип плёнки и число сторон сохраняются; скрытые старые значения не добавляются.');
  const option = (key, expression) => `if(contains(${value('options')}, ${q(key)}), ${expression}, "")`;
  const count = key => `if(${value(key)} > 0, " " + ${fmtNumber(key)}, "")`;
  const pieces = [
    option('round-corners','"Скругление углов"'),
    option('bigs','"Биговка" + '+count('quantity.hits.big')),
    option('falzovka','"Фальцовка" + '+count('quantity.hits.falz')),
    option('variable-data','"Переменные данные"'),option('numbering','"Нумерация"'),option('ind-qr','"Индивидуальные QR-коды"'),
    option('holes','"Отверстие Ø4мм" + if('+value('quantity.holes.rounded')+' > 0, " ×" + '+fmtNumber('quantity.holes.rounded')+', "")'),
    option('euroholes','"Евроотверстие"'),
    option('piccolo','"Пикколо" + if('+value('quantity.piccolo')+' > 0, " ×" + '+fmtNumber('quantity.piccolo')+', "") + if(len('+text('color.piccolo')+') > 0, " " + '+labels('color.piccolo',{gold_piccolo:'золото',silver_piccolo:'серебро',white_piccolo:'белый'})+', "")'),
  ];
  add('options','Название позиции: выбранные опции',pieces.join(' + " | " + '),'Количество бигов, фальцев, отверстий и пикколо относится к одному изделию. Дочерние параметры добавляются только вместе с выбранной опцией.');
  add('contour','Название позиции: контурная резка',`if(${visible('object.form')}, "Контурная резка" + if(len(${labels('object.form',{'round-shape':'круг','oval-shape':'овал','rectangular-shape':'прямоугольник','square-shape':'квадрат','another-form':'по макету'})}) > 0, " " + ${labels('object.form',{'round-shape':'круг','oval-shape':'овал','rectangular-shape':'прямоугольник','square-shape':'квадрат','another-form':'по макету'})}, "") + if(${text('object.form')} == "another-form" && ${value('cutting.contour.length')} > 0, " " + ${fmtNumber('cutting.contour.length')} + "мм", ""), "")`);
  add('run','Название позиции: тираж одного макета',`if(${value('volume')} > 0, "Тираж " + ${fmtNumber('volume')} + " шт", "")`,'Только volume. Не умножать на system.layout-count или количество тиражей в корзине. Сроки и дата готовности остаются отдельными параметрами заказа.');
  const set = (stageName, tokens) => {
    const s=d.stages.find(s=>s.name===stageName), c=d.calculations.find(c=>c.id===s?.calculationId);
    assert.ok(s && c, 'Executable stage required: '+stageName);
    for (const code of tokens.filter(x=>x!=='self')) {
      assert.ok(d.globals.some(g=>g.code===code),code);
      c.inputs.push({name:code,title:d.globals.find(g=>g.code===code).title,description:'Часть названия из глобальных значений; не влияет на стоимость.',type:'string'});
      s.inputs.push({name:code,source:{kind:'global',code}});
    }
    s.resultTemplates=s.resultTemplates.filter(t=>t.key!=='Название ТП');
    s.resultTemplates.push({key:'Название ТП',template:tokens.map(x=>'{'+x+'}').join(' | '),writeToOffer:true});
    s.description += '\n\nНазвание позиции: '+(stageName==='Материал'?'начать с выбранной витрины, готового формата, печати и материала.':'сохранить накопленное через {self}; добавить только выбранные параметры текущего этапа.')+' Разделитель — « | ». Пустые части и повторы исключаются. Единицы: мм, г/м2, мкм, шт; без точек. Макеты и сроки в название не входят.';
  };
  set('Материал',['offer_name_core']);
  set('Цифровая печать',['self']);
  set('Офсетная печать',['self']);
  set('Ламинация рулонная',['self','offer_name_protection']);
  set('Скругление углов',['self']);
  d.stages.find(s=>s.name==='Скругление углов').resultTemplates.find(t=>t.key==='Название ТП').template='{self} | Скругление углов';
  set('Резка в готовый формат',['self','offer_name_protection','offer_name_options','offer_name_contour','offer_name_run']);
  return d;
}
module.exports={configure};
if(require.main===module){
  const fs=require('node:fs'),[input,output]=process.argv.slice(2);
  const source=JSON.parse(fs.readFileSync(input,'utf8'));
  fs.writeFileSync(output,JSON.stringify(configure(source.envelope?JSON.parse(source.envelope.bodyJson):source),null,2)+'\n');
}
