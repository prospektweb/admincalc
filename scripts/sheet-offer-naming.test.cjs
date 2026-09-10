const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {configure}=require('./sheet-offer-naming.cjs');
const [snapshotFile,corePath,outputFile]=process.argv.slice(2);
assert.ok(snapshotFile && corePath, 'Supply a current read-only document/resource snapshot and calc-server dist directory');
const snapshot=JSON.parse(fs.readFileSync(snapshotFile,'utf8')),before=JSON.parse(snapshot.envelope.bodyJson),after=configure(before);
const {prepareQuote}=require(path.resolve(corePath,'core/quote'));
const {compileExpression,evaluateExpression}=require(path.resolve(corePath,'core/expression'));
const prepared=prepareQuote(after,snapshot.resources),baseline=prepareQuote(before,snapshot.resources);
const base={volume:100,'system.layout-count':2,'system.deadline-type':'strict','format.width':90,'format.length':50,method:'DIGITAL','color.scheme':'4+4','type.material':'paper','type.paper':'mel-mat-paper','density.paper':'300','section:protection':false,options:['round-corners'],'storefront:name':'Визитки'};
let checks=0; const eq=(a,b,message)=>{assert.deepEqual(a,b,message);checks++};
const names=values=>{
  const ctx={input:{values}};
  for(const g of after.globals.filter(g=>['product_name','value_format_text'].includes(g.code)||g.code.startsWith('offer_name_'))) ctx[g.code]=evaluateExpression(compileExpression(g.expression),ctx);
  return ctx;
};
const cases=[];
function run(label,changes){
  const values={...base,...changes},execution={unitCount:values.volume,runCount:1,layoutCount:values['system.layout-count'],deadlineType:'strict'};
  const old=baseline.execute(values,execution,undefined,{report:true}),next=prepared.execute(values,execution,undefined,{report:true});
  for(const key of ['basePrice','purchasePrice','priceRanges','dimensions','quantities','deadline'])eq(next[key],old[key],`${label}: ${key}`);
  const stages=result=>result.parts.flatMap(p=>p.stages.map(s=>({id:s.id,outputs:s.outputs})));
  eq(stages(next),stages(old),`${label}: all physical and monetary stage outputs`);
  assert.ok(!/undefined|null|\{[^}]+\}|\|\s*\|/.test(next.name));checks++;
  cases.push({label,name:next.name,basePrice:next.basePrice});return next;
}
eq(run('owner-example',{}).name,'Визитки - 90х50мм | Цифровая печать | 4+4 | Мелованная матовая бумага | 300г/м2 | Скругление углов | Тираж 100 шт');
run('digital-one-sided',{'color.scheme':'4+0',options:[],'system.layout-count':1});
run('offset', {method:'OFSET',volume:1000,'density.paper':'150','system.layout-count':1,options:[]});
for(const lamination of ['gloss-low','mat-low'])for(const sides of ['1','2'])run(`lamination-${lamination}-${sides}`,{volume:1000,method:'OFSET','density.paper':'150','system.layout-count':1,options:[],'section:protection':true,protection:'lamination-rulon',lamination,'lamination.sides':sides});
eq(run('switch-off-stale-protection',{'section:protection':false,protection:'lamination-rulon',lamination:'mat-low','lamination.sides':'2'}).name,cases[0].name);
eq(run('layouts-do-not-change-name',{'system.layout-count':3}).name,cases[0].name);
eq(run('base-storefront',{'storefront:name':'Листовая печать'}).name.replace('Листовая печать','Визитки'),cases[0].name);
const design=names({...base,'type.paper':'design-paper','brand.paper':'acquerello','color.paper':'acquerello_avorio_s'});
eq(design.offer_name_brand,'Акверелло');eq(design.offer_name_material_color,'Слоновая кость');
const stale=names({...base,'brand.paper':'acquerello','color.paper':'acquerello_avorio_s','quantity.hits.big':9,'color.piccolo':'gold_piccolo'});
eq(stale.offer_name_brand,'');eq(stale.offer_name_material_color,'');eq(stale.offer_name_options.split('|').map(s=>s.trim()).filter(Boolean),['Скругление углов']);
const card=names({...base,'type.material':'cardboard','type.cartboard':'design-cardboard','brand.cardboard':'cmpc_natural_kraft','density.cardboard':'285','color.cardboard':'cmpc_natural_kraft'});
eq(card.offer_name_material,'Дизайнерский картон');eq(card.offer_name_density,'285г/м2');assert.ok(!card.offer_name_core.includes('300г/м2'));checks++;
const film=names({...base,'type.material':'film','type.film':'PET','thickness.film':150,'color.film':'Прозрачная'});
eq(film.offer_name_material,'Плёнка PET');eq(film.offer_name_density,'150мкм');eq(film.offer_name_brand,'');
for(const option of before.form.fields.find(f=>f.fieldId==='method').options)eq(names({...base,method:option.id}).offer_name_method,option.label);
for(const option of before.form.fields.find(f=>f.fieldId==='color.scheme').options)eq(names({...base,'color.scheme':option.id}).offer_name_color,option.label);
for(const id of ['type.paper','type.cartboard'])for(const option of before.form.fields.find(f=>f.fieldId===id).options)eq(names({...base,'type.material':id==='type.paper'?'paper':'cardboard',[id]:option.id}).offer_name_material,option.label);
for(const option of before.form.fields.find(f=>f.fieldId==='lamination').options){const n=names({...base,'section:protection':true,protection:'lamination-rulon',lamination:option.id,'lamination.sides':'2'});assert.ok(n.offer_name_protection.startsWith('Ламинация ')&&n.offer_name_protection.endsWith(' 2ст'));checks++;}
eq(names({...base,'section:protection':true,protection:'uf-lak',lamination:'mat-low','lamination.sides':'2'}).offer_name_protection,'УФ-лак');
eq(names({...base,'section:protection':true,protection:'lamination-pocket',lamination:'mat-low','lamination.sides':'2'}).offer_name_protection,'Пакетная ламинация матовая стандарт');
eq(names({...base,'type.paper':'design-paper','section:protection':true,protection:'uf-lak'}).offer_name_protection,'');
const opts=names({...base,options:['piccolo','holes','bigs','falzovka','euroholes','variable-data','numbering','ind-qr','round-corners'],'quantity.hits.big':2,'quantity.hits.falz':3,'quantity.holes.rounded':2,'quantity.piccolo':4,'color.piccolo':'silver_piccolo'}).offer_name_options;
for(const token of ['Биговка 2','Фальцовка 3','Отверстие Ø4мм ×2','Пикколо ×4 серебро','Евроотверстие','Переменные данные','Нумерация','Индивидуальные QR-коды']){assert.ok(opts.includes(token),token);checks++;}
eq(names({...base,'type.paper':'sticker-paper','section:contour.cutting':true,'object.form':'another-form','cutting.contour.length':350}).offer_name_contour,'Контурная резка по макету 350мм');
eq(names({...base,'section:contour.cutting':true,'object.form':'another-form','cutting.contour.length':350}).offer_name_contour,'');
eq(names({...base,'storefront:name':'  Визитки   премиум  ','format.width':85.5}).offer_name_header,'Визитки премиум - 85,5х50мм');
eq(names({...base,'storefront:name':'','format.width':0,'format.length':0}).offer_name_header,'');
eq(names({...base,'system.layout-count':15}).offer_name_run,'Тираж 100 шт');
// Configuration scope: retain all pricing, formula expressions, resources, activation and links.
for(const key of ['execution','form','groups','parts','presentations','pricing','resources','roots'])eq(after[key],before[key],key);
for(const s of before.stages){const a=after.stages.find(x=>x.id===s.id);for(const key of ['activation','calculationId','capabilities','globals','outputs','resources','selections'])eq(a[key],s[key],s.name+': '+key);}
for(const c of before.calculations){const a=after.calculations.find(x=>x.id===c.id);for(const key of ['formulas','authoring','globalDependencies','testValues'])eq(a[key],c[key],c.name+': '+key);}
const result={pass:true,checks,cases,namingOnlyBranches:['УФ-печать','Шелкография','Пакетная ламинация','Отверстия','Картон и плёнка: прежняя зависимость материала от density.paper'],snapshotAt:snapshot.at};
if(outputFile)fs.writeFileSync(outputFile,JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify(result,null,2));
