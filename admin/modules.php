<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

global $APPLICATION, $USER;
if (!$USER || !$USER->IsAuthorized()) {
    $APPLICATION->AuthForm('Требуется авторизация');
}
if (!Loader::includeModule('prospektweb.calc')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Модуль prospektweb.calc не загружен');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

$APPLICATION->SetTitle('Библиотека модулей калькуляции');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
.pwm-shell{height:calc(100vh - 135px);min-height:620px;display:grid;grid-template-rows:42px 1fr;background:#f5f7f9;border:1px solid #cfd7df;color:#263238;font:13px/1.35 Arial,sans-serif}
.pwm-toolbar{display:flex;align-items:center;gap:7px;padding:5px 8px;border-bottom:1px solid #cfd7df;background:#fff}
.pwm-toolbar__title{font-weight:700;font-size:15px;margin-right:auto}.pwm-btn{border:1px solid #9daab5;border-radius:3px;background:#fff;color:#263238;padding:5px 10px;cursor:pointer}.pwm-btn:hover{background:#eef3f6}.pwm-btn--primary{background:#2f7db5;border-color:#2f7db5;color:#fff}.pwm-btn--danger{color:#a52525}.pwm-btn[disabled]{opacity:.5;cursor:default}
.pwm-grid{min-height:0;display:grid;grid-template-columns:260px minmax(360px,1fr) minmax(420px,1.25fr)}
.pwm-pane{min-width:0;min-height:0;overflow:auto;border-right:1px solid #cfd7df;background:#fff}.pwm-pane:last-child{border-right:0}.pwm-pane__head{position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:8px;min-height:34px;padding:0 9px;border-bottom:1px solid #dce3e8;background:#f8fafb;font-weight:700}
.pwm-list{margin:0;padding:0;list-style:none}.pwm-list button{display:block;width:100%;border:0;border-bottom:1px solid #edf0f2;background:#fff;text-align:left;padding:8px 10px;cursor:pointer}.pwm-list button:hover,.pwm-list button.is-active{background:#eaf4fb}.pwm-muted{color:#6b7780;font-size:12px}.pwm-badge{display:inline-block;padding:1px 5px;border-radius:8px;background:#e8eef2;font-size:11px}.pwm-badge--published{background:#dff3e4;color:#176b2d}.pwm-badge--draft{background:#fff1cb;color:#805c00}.pwm-badge--deprecated,.pwm-badge--archived{background:#ececec;color:#666}
.pwm-card{margin:8px;border:1px solid #d5dde3;border-radius:4px;background:#fff}.pwm-card__head{display:flex;align-items:center;gap:6px;padding:7px 9px;border-bottom:1px solid #e2e7eb;background:#fafbfc;font-weight:700}.pwm-card__body{padding:8px}.pwm-kv{display:grid;grid-template-columns:120px 1fr;gap:3px 8px}.pwm-table{width:100%;border-collapse:collapse}.pwm-table th,.pwm-table td{padding:5px 6px;border-bottom:1px solid #e2e7eb;text-align:left;vertical-align:top}.pwm-table th{background:#f7f9fa;font-size:11px;text-transform:uppercase;color:#64717a}.pwm-row-actions{display:flex;gap:4px;flex-wrap:wrap}
.pwm-form{display:grid;grid-template-columns:145px minmax(0,1fr);gap:6px 8px;align-items:center}.pwm-form input,.pwm-form select,.pwm-form textarea{box-sizing:border-box;width:100%;min-height:28px;border:1px solid #aeb9c2;border-radius:3px;background:#fff;padding:4px 6px}.pwm-form textarea{min-height:80px;font-family:Consolas,monospace}.pwm-port{display:grid;grid-template-columns:minmax(115px,.8fr) 115px minmax(150px,1.3fr);gap:5px;align-items:center;padding:4px 0;border-bottom:1px solid #edf0f2}.pwm-port:last-child{border-bottom:0}.pwm-port__name{overflow:hidden;text-overflow:ellipsis}.pwm-alert{margin:8px;padding:7px 9px;border-radius:3px;background:#eaf4fb;border:1px solid #b9d8ec}.pwm-alert--error{background:#fff0f0;border-color:#e5b6b6;color:#8a1f1f}.pwm-json{max-height:260px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:11px/1.35 Consolas,monospace;background:#19232b;color:#d8e5ec;padding:8px;border-radius:3px}.pwm-empty{padding:18px;color:#75818a;text-align:center}
@media(max-width:1200px){.pwm-grid{grid-template-columns:220px minmax(320px,.9fr) minmax(390px,1.1fr)}}
</style>
<div class="pwm-shell" id="pwm-app">
  <div class="pwm-toolbar">
    <div class="pwm-toolbar__title">Библиотека модулей калькуляции</div>
    <button class="pwm-btn" data-action="refresh">Обновить</button>
    <button class="pwm-btn pwm-btn--primary" data-action="install-pilot">Опубликовать пилот «Цифровая печать»</button>
  </div>
  <div class="pwm-grid">
    <section class="pwm-pane">
      <div class="pwm-pane__head">Семейства</div>
      <ul class="pwm-list" id="pwm-families"></ul>
    </section>
    <section class="pwm-pane">
      <div class="pwm-pane__head">Контракт и версии</div>
      <div id="pwm-module"><div class="pwm-empty">Выберите семейство</div></div>
    </section>
    <section class="pwm-pane">
      <div class="pwm-pane__head">Подключение и экземпляры</div>
      <div id="pwm-workspace"><div class="pwm-empty">Выберите опубликованную версию</div></div>
    </section>
  </div>
</div>
<script>
(function(){
  'use strict';
  const endpoint='/bitrix/tools/prospektweb.calc/modules.php';
  const sessid='<?= CUtil::JSEscape(bitrix_sessid()) ?>';
  const state={catalog:[],options:null,family:null,version:null,presetId:0,instances:[],preview:null};
  const q=(s,r=document)=>r.querySelector(s);
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function api(action,payload={}){
    const body=new URLSearchParams({action,sessid,payload:JSON.stringify(payload)});
    const response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});
    const json=await response.json();
    if(!response.ok||!json.success)throw new Error(json.error||'Ошибка запроса');
    return json.data;
  }
  function notice(message,error=false){
    let node=q('#pwm-notice');
    if(!node){node=document.createElement('div');node.id='pwm-notice';q('#pwm-workspace').prepend(node);}
    node.className='pwm-alert'+(error?' pwm-alert--error':'');
    node.textContent=message;
  }
  async function load(){
    [state.catalog,state.options]=await Promise.all([api('catalog'),api('options')]);
    if(state.family){
      state.family=state.catalog.find(f=>Number(f.ID)===Number(state.family.ID))||null;
      state.version=state.family?.VERSIONS.find(v=>Number(v.ID)===Number(state.version?.ID))||state.family?.VERSIONS[0]||null;
    }
    renderFamilies();renderModule();renderWorkspace();
  }
  function renderFamilies(){
    q('#pwm-families').innerHTML=state.catalog.length?state.catalog.map(f=>`
      <li><button data-family="${f.ID}" class="${state.family&&Number(state.family.ID)===Number(f.ID)?'is-active':''}">
        <strong>${esc(f.NAME)}</strong><br><span class="pwm-muted">${esc(f.CODE)} · ${f.VERSIONS.length} вер.</span>
      </button></li>`).join(''):'<li class="pwm-empty">Библиотека пуста</li>';
  }
  function renderModule(){
    const root=q('#pwm-module');
    if(!state.family){root.innerHTML='<div class="pwm-empty">Выберите семейство</div>';return;}
    const versions=state.family.VERSIONS||[];
    const v=state.version||versions[0]||null;
    state.version=v;
    root.innerHTML=`
      <div class="pwm-card"><div class="pwm-card__head">${esc(state.family.NAME)}</div><div class="pwm-card__body pwm-kv">
        <span class="pwm-muted">Код</span><code>${esc(state.family.CODE)}</code>
        <span class="pwm-muted">Описание</span><span>${esc(state.family.DESCRIPTION||'—')}</span>
      </div></div>
      <div class="pwm-card"><div class="pwm-card__head">Версии</div><div class="pwm-card__body">
        <select id="pwm-version">${versions.map(x=>`<option value="${x.ID}" ${v&&Number(v.ID)===Number(x.ID)?'selected':''}>${esc(x.VERSION)} · ${esc(x.STATUS)}</option>`).join('')}</select>
        ${v?`<div class="pwm-row-actions" style="margin-top:7px">
          ${v.STATUS==='published'?`<button class="pwm-btn" data-action="deprecate">Пометить устаревшей</button>`:''}
          ${v.STATUS!=='archived'?`<button class="pwm-btn pwm-btn--danger" data-action="archive">Архивировать</button>`:''}
        </div>`:''}
      </div></div>
      ${v?contractHtml(v.CONTENT):'<div class="pwm-empty">Нет версий</div>'}`;
  }
  function contractHtml(m){
    return `<div class="pwm-card"><div class="pwm-card__head">${esc(m.name)} <span class="pwm-badge pwm-badge--${esc(m.status)}">${esc(m.status)}</span></div>
      <div class="pwm-card__body pwm-kv"><span class="pwm-muted">Тип</span><span>${esc(m.kind)}</span><span class="pwm-muted">Hash</span><code title="${esc(m.contentHash)}">${esc(m.contentHash.slice(0,16))}…</code><span class="pwm-muted">Узлы</span><span>${m.content.nodes.length}</span><span class="pwm-muted">Тесты</span><span>${m.tests.length}</span></div></div>
      <div class="pwm-card"><div class="pwm-card__head">Порты</div><table class="pwm-table"><thead><tr><th>Код</th><th>Направление</th><th>Тип</th><th>Обяз.</th></tr></thead><tbody>${m.ports.map(p=>`<tr><td><code>${esc(p.code)}</code></td><td>${esc(p.direction)}</td><td>${esc(p.valueType)}${p.unit?' · '+esc(p.unit):''}</td><td>${p.required?'да':'нет'}</td></tr>`).join('')}</tbody></table></div>
      <div class="pwm-card"><div class="pwm-card__head">Роли сущностей</div><table class="pwm-table"><tbody>${m.entityRoles.map(r=>`<tr><td><code>${esc(r.code)}</code></td><td>${esc(r.entityType)}</td><td>${esc(r.cardinality)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  function optionRows(items){return (items||[]).map(x=>`<option value="${x.id}">${esc(x.name)}${x.active?'':' (неактивно)'}</option>`).join('');}
  function renderWorkspace(){
    const root=q('#pwm-workspace'),v=state.version,m=v?.CONTENT;
    if(!v||!m||!['published','deprecated'].includes(v.STATUS)){root.innerHTML='<div class="pwm-empty">Для подключения выберите опубликованную версию</div>';return;}
    const presets=state.options?.presets||[];
    if(!state.presetId&&presets.length)state.presetId=Number(presets[0].id);
    const bindPorts=m.ports.filter(p=>p.direction!=='output');
    root.innerHTML=`
      <div id="pwm-notice" class="pwm-alert">Preview не изменяет пресет. Apply создаёт новый immutable snapshot; старые snapshot остаются доступны для rollback.</div>
      <div class="pwm-card"><div class="pwm-card__head">Мастер подключения</div><div class="pwm-card__body">
        <div class="pwm-form"><label>Пресет</label><select id="pwm-preset">${presets.map(x=>`<option value="${x.id}" ${Number(x.id)===Number(state.presetId)?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div>
        <h4>Сопоставление портов</h4>
        <div id="pwm-ports">${bindPorts.map(p=>`<div class="pwm-port" data-port="${esc(p.code)}">
          <div class="pwm-port__name"><code>${esc(p.code)}</code>${p.required?' *':''}<br><span class="pwm-muted">${esc(p.direction)} · ${esc(p.valueType)}</span></div>
          <select data-field="kind">${['input','global-input'].includes(p.direction)?'<option value="source-path">Источник</option><option value="global" '+(p.direction==='global-input'?'selected':'')+'>Глобальное</option>':'<option value="global">Глобальное</option><option value="module-port">Порт модуля</option>'}<option value="literal">Литерал</option></select>
          <input data-field="value" value="${esc((p.direction==='global-input'?'preset.global.':'product.')+p.code)}" aria-label="Значение ${esc(p.code)}">
        </div>`).join('')}</div>
        <h4>Динамические роли</h4>
        <div class="pwm-form" id="pwm-roles">${m.entityRoles.map(r=>`<label>${esc(r.code)} *</label><select data-role="${esc(r.code)}" data-type="${esc(r.entityType)}"><option value="">— выбрать —</option>${optionRows(state.options?.[roleGroup(r.entityType)])}</select>`).join('')}</div>
        <div class="pwm-row-actions" style="margin-top:10px"><button class="pwm-btn" data-action="preview">Preview</button><button class="pwm-btn pwm-btn--primary" data-action="apply" ${state.preview?'':'disabled'}>Apply snapshot</button></div>
      </div></div>
      <div id="pwm-preview">${state.preview?`<div class="pwm-card"><div class="pwm-card__head">Resolved snapshot</div><div class="pwm-card__body"><div class="pwm-kv"><span class="pwm-muted">Hash</span><code>${esc(state.preview.snapshotHash)}</code><span class="pwm-muted">Версия</span><span>${esc(state.preview.familyId)}@${esc(state.preview.version)}</span><span class="pwm-muted">Узлы</span><span>${state.preview.resolvedGraph.nodes.length}</span></div></div></div>`:''}</div>
      <div class="pwm-card"><div class="pwm-card__head">Экземпляры выбранного пресета <button class="pwm-btn" data-action="load-instances">Обновить</button></div><div id="pwm-instances">${instancesHtml()}</div></div>`;
  }
  function roleGroup(type){return ({material:'materials',materialVariant:'materialVariants',operation:'operations',operationVariant:'operationVariants',equipment:'equipment'})[type]||'equipment';}
  function instancesHtml(){
    if(!state.instances.length)return '<div class="pwm-empty">Подключённых экземпляров нет</div>';
    return `<table class="pwm-table"><thead><tr><th>Экземпляр</th><th>Версия</th><th>Rev</th><th>Snapshot</th><th></th></tr></thead><tbody>${state.instances.map(x=>`<tr><td>${esc(x.INSTANCE_UID)}</td><td>${esc(x.VERSION_ID)}</td><td>${esc(x.REVISION)}</td><td>${esc(x.SNAPSHOT_ID||'—')}</td><td><button class="pwm-btn" data-action="history" data-instance="${x.ID}">История</button></td></tr>`).join('')}</tbody></table>`;
  }
  function collectInstance(){
    const bindings=[...document.querySelectorAll('#pwm-ports [data-port]')].map(row=>{
      const kind=q('[data-field="kind"]',row).value;
      let value=q('[data-field="value"]',row).value;
      if(kind==='literal'){try{value=JSON.parse(value);}catch(_){}}
      return {portCode:row.dataset.port,target:{kind,value}};
    });
    const entityBindings=[...document.querySelectorAll('#pwm-roles [data-role]')].map(select=>({
      roleCode:select.dataset.role,entityType:select.dataset.type,localElementIds:select.value?[Number(select.value)]:[]
    }));
    return {schema:'prospektweb.calc.module-instance/v1',instanceId:'preview-'+Date.now(),presetId:state.presetId,familyId:state.version.CONTENT.familyId,version:state.version.CONTENT.version,contentHash:state.version.CONTENT.contentHash,revision:1,bindings,entityBindings,dependencyLock:[],provenance:{createdAt:new Date().toISOString(),createdBy:'admin-ui',legacyElementIds:{}}};
  }
  async function loadInstances(){
    state.instances=await api('instances',{presetId:state.presetId});renderWorkspace();
  }
  document.addEventListener('change',async e=>{
    if(e.target.id==='pwm-version'){state.version=state.family.VERSIONS.find(v=>Number(v.ID)===Number(e.target.value));state.preview=null;renderModule();renderWorkspace();}
    if(e.target.id==='pwm-preset'){state.presetId=Number(e.target.value);state.preview=null;await loadInstances();}
    if(e.target.closest('#pwm-ports,#pwm-roles')){state.preview=null;const apply=q('[data-action="apply"]');if(apply)apply.disabled=true;}
  });
  document.addEventListener('click',async e=>{
    const familyButton=e.target.closest('[data-family]');
    if(familyButton){state.family=state.catalog.find(f=>Number(f.ID)===Number(familyButton.dataset.family));state.version=state.family.VERSIONS[0]||null;state.preview=null;renderFamilies();renderModule();renderWorkspace();return;}
    const button=e.target.closest('[data-action]');if(!button)return;
    button.disabled=true;
    try{
      const action=button.dataset.action;
      if(action==='refresh')await load();
      if(action==='install-pilot'){await api('pilot.install');await load();notice('Пилотная версия опубликована.');}
      if(action==='load-instances')await loadInstances();
      if(action==='preview'){
        const instance=collectInstance();
        state.preview=(await api('instance.preview',{versionId:Number(state.version.ID),instance,options:{snapshotId:'preview-'+Date.now(),presetRevision:1,createdAt:new Date().toISOString(),resolvedBy:'admin-ui',resolverVersion:'1.0.0'}})).snapshot;
        renderWorkspace();notice('Preview прошёл validate. Можно применить snapshot.');
      }
      if(action==='apply'){
        if(!state.preview)throw new Error('Сначала выполните Preview');
        const instance=collectInstance();instance.instanceId='instance-'+crypto.randomUUID();
        const result=await api('instance.apply',{presetId:state.presetId,versionId:Number(state.version.ID),instance,options:{snapshotId:'snapshot-'+crypto.randomUUID(),presetRevision:1,createdAt:new Date().toISOString(),resolvedBy:'admin-ui',resolverVersion:'1.0.0'}});
        state.preview=null;await loadInstances();notice('Экземпляр применён. Snapshot '+result.snapshotHash);
      }
      if(action==='deprecate'||action==='archive'){
        await api('version.status',{versionId:Number(state.version.ID),status:action==='deprecate'?'deprecated':'archived',expectedRevision:Number(state.version.REVISION)});
        await load();
      }
      if(action==='history'){
        const snapshots=await api('snapshots',{instanceId:Number(button.dataset.instance)});
        const current=state.instances.find(x=>Number(x.ID)===Number(button.dataset.instance));
        q('#pwm-preview').innerHTML=`<div class="pwm-card"><div class="pwm-card__head">История snapshot</div><table class="pwm-table"><tbody>${snapshots.map(s=>`<tr><td>${esc(s.SNAPSHOT_UID)}<br><code>${esc(s.SNAPSHOT_HASH)}</code></td><td>rev ${esc(s.INSTANCE_REVISION)}</td><td>${Number(current.SNAPSHOT_ID)===Number(s.ID)?'<span class="pwm-badge pwm-badge--published">текущий</span>':`<button class="pwm-btn" data-action="rollback" data-instance="${current.ID}" data-snapshot="${s.ID}" data-revision="${current.REVISION}">Rollback</button>`}</td></tr>`).join('')}</tbody></table></div>`;
      }
      if(action==='rollback'){
        await api('instance.rollback',{instanceId:Number(button.dataset.instance),snapshotId:Number(button.dataset.snapshot),expectedRevision:Number(button.dataset.revision)});
        await loadInstances();notice('Rollback выполнен: прежний snapshot снова активен.');
      }
    }catch(error){notice(error.message||String(error),true);}finally{button.disabled=false;}
  });
  load().catch(error=>{q('#pwm-workspace').innerHTML='<div class="pwm-alert pwm-alert--error">'+esc(error.message)+'</div>';});
})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
