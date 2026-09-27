const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const page=fs.readFileSync(__dirname+'/../admin/prospektweb_calc_control_center.php','utf8');
const start=page.indexOf('    var calculatorWorkspaceHashPattern =');
const end=page.indexOf('    function syncCalculatorWorkspaceFromHost()',start);
const context=vm.createContext({URLSearchParams});vm.runInContext(page.slice(start,end),context);
const normalize=context.normalizeCalculatorWorkspaceHash;
const version='v_'+'a'.repeat(32),native='#/presets?document=calc_native-7&version='+version+'&tab=form';
test('exact native document form route reaches child unchanged, with 32 or 40 hex version',()=>{
  for(const size of [32,40]){const hash='#/presets?document='+encodeURIComponent('site:calc_7.v1')+'&version=v_'+'a'.repeat(size)+'&tab=form';assert.equal(normalize(hash),hash);}
  assert.equal(normalize(native),native);
});
test('native route rejects missing, duplicate or additional query keys',()=>{
  for(const hash of [native+'&q=extra',native+'&document=another',native+'&tab=form',native+'&version='+version,native.replace('&tab=form',''),native.replace('document=calc_native-7&',''),native.replace('&version='+version,'')])assert.equal(normalize(hash),'',hash);
});
test('native route rejects wrong path, wrong tab, unsafe IDs and oversized targets',()=>{
  for(const hash of [native.replace('#/presets?','#/presets/7/form?'),native.replace('tab=form','tab=logic'),native.replace('calc_native-7','../other'),native.replace('calc_native-7','x'.repeat(129)),native.replace(version,'v_'+'a'.repeat(31)),native.replace(version,'v_'+'a'.repeat(33)),native.replace(version,'v_'+'A'.repeat(32)),native.replace('calc_native-7','%0Ainvalid'),native+'&extra='+'x'.repeat(1200)])assert.equal(normalize(hash),'',hash);
  for(const hash of [native.replace('calc_native-7','calc_native-7%0A'),native.replace(version,version+'%0A')])assert.equal(normalize(hash),'',hash);
});
test('legacy list and numeric preset routes retain existing acceptance',()=>{
  for(const hash of ['#/presets','#/presets?q=paper&status=active&sort=name_asc','#/presets/7/form?version=v_'+'a'.repeat(16),'#/presets/71/logic?field=CALC_PROP_VOLUME'])assert.equal(normalize(hash),hash);
  for(const hash of ['#/unknown','#/presets/0/form','#/presets?q=a&q=b','#/presets?status=unknown','#/presets?unknown=x'])assert.equal(normalize(hash),'',hash);
});
