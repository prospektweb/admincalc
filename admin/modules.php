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
    <button class="pwm-btn" data-action="migration-open">Миграция v1</button>
    <button class="pwm-btn pwm-btn--primary" data-action="install-pilot">Опубликовать вертикальные пилоты</button>
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
  const state={catalog:[],options:null,family:null,version:null,presetId:0,instances:[],preview:null,previewDiff:null,currentSnapshot:null,editingInstance:null,migrationMode:false,migrationAnalysis:null,migrationDraft:null};
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
    if(state.migrationMode){renderMigration(root);return;}
    if(!v||!m||!['published','deprecated'].includes(v.STATUS)){root.innerHTML='<div class="pwm-empty">Для подключения выберите опубликованную версию</div>';return;}
    const presets=state.options?.presets||[];
    if(!state.presetId&&presets.length)state.presetId=Number(presets[0].id);
    const bindPorts=m.ports.filter(p=>p.direction!=='output');
    root.innerHTML=`
      <div id="pwm-notice" class="pwm-alert">Preview не изменяет пресет. Apply создаёт новый immutable snapshot; старые snapshot остаются доступны для rollback.</div>
      <div class="pwm-card"><div class="pwm-card__head">Мастер подключения</div><div class="pwm-card__body">
        <div class="pwm-form"><label>Пресет</label><select id="pwm-preset">${presets.map(x=>`<option value="${x.id}" ${Number(x.id)===Number(state.presetId)?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div>
        <h4>Сопоставление портов</h4>
        <div id="pwm-ports">${bindPorts.map(p=>{const existing=(state.editingInstance?.BINDINGS||[]).find(b=>b.portCode===p.code)?.target;const kind=existing?.kind||(p.direction==='global-input'?'global':'source-path');const value=existing?.value??((p.direction==='global-input'?'preset.global.':'product.')+p.code);return `<div class="pwm-port" data-port="${esc(p.code)}">
          <div class="pwm-port__name"><code>${esc(p.code)}</code>${p.required?' *':''}<br><span class="pwm-muted">${esc(p.direction)} · ${esc(p.valueType)}</span></div>
          <select data-field="kind">${['source-path','global','module-port','literal'].map(x=>`<option value="${x}" ${x===kind?'selected':''}>${esc(x)}</option>`).join('')}</select>
          <input data-field="value" value="${esc(typeof value==='string'?value:JSON.stringify(value))}" aria-label="Значение ${esc(p.code)}">
        </div>`;}).join('')}</div>
        <h4>Динамические роли</h4>
        <div class="pwm-form" id="pwm-roles">${m.entityRoles.map(r=>{const selected=(state.editingInstance?.ENTITY_BINDINGS||[]).find(b=>b.roleCode===r.code)?.localElementIds?.[0]||'';return `<label>${esc(r.code)} *</label><select data-role="${esc(r.code)}" data-type="${esc(r.entityType)}"><option value="">— выбрать —</option>${(state.options?.[roleGroup(r.entityType)]||[]).map(x=>`<option value="${x.id}" ${Number(x.id)===Number(selected)?'selected':''}>${esc(x.name)}${x.active?'':' (неактивно)'}</option>`).join('')}</select>`;}).join('')}</div>
        <div class="pwm-row-actions" style="margin-top:10px"><button class="pwm-btn" data-action="preview">Preview</button><button class="pwm-btn pwm-btn--primary" data-action="apply" ${state.preview?'':'disabled'}>${state.editingInstance?'Update snapshot':'Apply snapshot'}</button>${state.editingInstance?'<button class="pwm-btn" data-action="edit-cancel">Отменить обновление</button>':''}</div>
      </div></div>
      <div id="pwm-preview">${state.preview?`<div class="pwm-card"><div class="pwm-card__head">Resolved snapshot</div><div class="pwm-card__body"><div class="pwm-kv"><span class="pwm-muted">Hash</span><code>${esc(state.preview.snapshotHash)}</code><span class="pwm-muted">Версия</span><span>${esc(state.preview.familyId)}@${esc(state.preview.version)}</span><span class="pwm-muted">Узлы</span><span>${state.preview.resolvedGraph.nodes.length}</span></div>${state.previewDiff?`<pre class="pwm-json">${esc(JSON.stringify(state.previewDiff,null,2))}</pre>`:''}</div></div>`:''}</div>
      <div class="pwm-card"><div class="pwm-card__head">Экземпляры выбранного пресета <button class="pwm-btn" data-action="load-instances">Обновить</button></div><div id="pwm-instances">${instancesHtml()}</div></div>`;
  }
  function roleGroup(type){return ({material:'materials',materialVariant:'materialVariants',operation:'operations',operationVariant:'operationVariants',equipment:'equipment'})[type]||'equipment';}
  function instancesHtml(){
    if(!state.instances.length)return '<div class="pwm-empty">Подключённых экземпляров нет</div>';
    return `<table class="pwm-table"><thead><tr><th>Экземпляр</th><th>Версия</th><th>Rev</th><th>Snapshot</th><th></th></tr></thead><tbody>${state.instances.map(x=>`<tr><td>${esc(x.INSTANCE_UID)}</td><td>${esc(x.MODULE_FAMILY_NAME||x.MODULE_FAMILY_CODE)}<br><code>${esc(x.MODULE_VERSION)}</code></td><td>${esc(x.REVISION)}</td><td>${x.SNAPSHOT_ID?'активен':'—'}</td><td><div class="pwm-row-actions"><button class="pwm-btn" data-action="edit-instance" data-instance="${x.ID}">Обновить</button><button class="pwm-btn" data-action="history" data-instance="${x.ID}">История</button></div></td></tr>`).join('')}</tbody></table>`;
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
    return {schema:'prospektweb.calc.module-instance/v1',instanceId:state.editingInstance?.INSTANCE_UID||'preview-'+Date.now(),presetId:state.presetId,familyId:state.version.CONTENT.familyId,version:state.version.CONTENT.version,contentHash:state.version.CONTENT.contentHash,revision:state.editingInstance?Number(state.editingInstance.REVISION)+1:1,bindings,entityBindings,dependencyLock:state.editingInstance?.DEPENDENCY_LOCK||[],provenance:state.editingInstance?.CONTEXT?.provenance||{createdAt:new Date().toISOString(),createdBy:'admin-ui',legacyElementIds:{}}};
  }
  function renderMigration(root){
    const presets=state.options?.presets||[];
    if(!state.presetId&&presets.length)state.presetId=Number(presets[0].id);
    root.innerHTML=`<div id="pwm-notice" class="pwm-alert">Анализ только читает legacy v1. Мастер не угадывает порты, роли, пути или ID и никогда не публикует автоматически.</div>
      <div class="pwm-card"><div class="pwm-card__head">Миграционный помощник v1</div><div class="pwm-card__body">
        <div class="pwm-form"><label>Пресет</label><select id="pwm-preset">${presets.map(x=>`<option value="${x.id}" ${Number(x.id)===Number(state.presetId)?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div>
        <div class="pwm-row-actions" style="margin-top:8px"><button class="pwm-btn pwm-btn--primary" data-action="migration-analyze">Анализировать</button><button class="pwm-btn" data-action="migration-close">Вернуться к библиотеке</button></div>
      </div></div>
      ${state.migrationAnalysis?`<div class="pwm-card"><div class="pwm-card__head">Инвентаризация: ${esc(state.migrationAnalysis.preset.name)}</div><div class="pwm-card__body">
        <table class="pwm-table"><thead><tr><th>Элемент</th><th>Тип</th><th>Этапы</th><th>Настройки</th></tr></thead><tbody>${state.migrationAnalysis.inventory.map(x=>`<tr><td>${esc(x.name)}</td><td>${esc(x.kind)}</td><td>${x.stages.map(s=>esc(s.name)).join('<br>')}</td><td>${x.stages.flatMap(s=>s.settingsLegacyIds).join(', ')||'—'}</td></tr>`).join('')}</tbody></table>
        ${state.migrationAnalysis.blockers.length?`<div class="pwm-alert pwm-alert--error">${state.migrationAnalysis.blockers.map(x=>esc(x.message)).join('<br>')}</div>`:'<div class="pwm-alert">Структура считана. Для извлечения внесите проверенный контракт ниже.</div>'}
        <label>Явно проверенный contract review JSON</label><textarea id="pwm-migration-review" style="box-sizing:border-box;width:100%;min-height:220px" placeholder='{"familyId":"...","version":"1.0.0","kind":"stage","name":"...","content":{},"ports":[],"entityRoles":[],"tests":[]}'></textarea>
        <div class="pwm-row-actions" style="margin-top:8px"><button class="pwm-btn" data-action="migration-extract">Проверить portable draft</button></div>
      </div></div>`:''}
      ${state.migrationDraft?`<div class="pwm-card"><div class="pwm-card__head">Portable draft preview — публикация запрещена</div><div class="pwm-card__body"><pre class="pwm-json">${esc(JSON.stringify(state.migrationDraft,null,2))}</pre>
        <div class="pwm-form" style="margin-top:8px"><label>Legacy expected JSON</label><textarea id="pwm-migration-expected"></textarea><label>Module actual JSON</label><textarea id="pwm-migration-actual"></textarea><label>Допуск</label><input id="pwm-migration-tolerance" type="number" min="0" step="any" value="0"></div>
        <div class="pwm-row-actions" style="margin-top:8px"><button class="pwm-btn pwm-btn--primary" data-action="migration-create-draft">Сравнить и создать черновик</button></div>
      </div></div>`:''}`;
  }
  async function loadInstances(){
    state.instances=await api('instances',{presetId:state.presetId});renderWorkspace();
  }
  document.addEventListener('change',async e=>{
    if(e.target.id==='pwm-version'){state.version=state.family.VERSIONS.find(v=>Number(v.ID)===Number(e.target.value));state.preview=null;state.previewDiff=null;renderModule();renderWorkspace();}
    if(e.target.id==='pwm-preset'){state.presetId=Number(e.target.value);state.preview=null;if(!state.migrationMode)await loadInstances();}
    if(e.target.closest('#pwm-ports,#pwm-roles')){state.preview=null;state.previewDiff=null;const apply=q('[data-action="apply"]');if(apply)apply.disabled=true;}
  });
  document.addEventListener('click',async e=>{
    const familyButton=e.target.closest('[data-family]');
    if(familyButton){state.migrationMode=false;state.family=state.catalog.find(f=>Number(f.ID)===Number(familyButton.dataset.family));state.version=state.family.VERSIONS[0]||null;state.preview=null;state.previewDiff=null;renderFamilies();renderModule();renderWorkspace();return;}
    const button=e.target.closest('[data-action]');if(!button)return;
    button.disabled=true;
    try{
      const action=button.dataset.action;
      if(action==='refresh')await load();
      if(action==='migration-open'){state.migrationMode=true;state.migrationAnalysis=null;state.migrationDraft=null;renderWorkspace();}
      if(action==='migration-close'){state.migrationMode=false;renderWorkspace();}
      if(action==='migration-analyze'){state.migrationAnalysis=await api('migration.analyze',{presetId:state.presetId});state.migrationDraft=null;renderWorkspace();}
      if(action==='migration-extract'){
        const review=JSON.parse(q('#pwm-migration-review').value);
        state.migrationDraft=await api('migration.extract',{legacySelection:{presetLegacyId:state.presetId,detailLegacyIds:state.migrationAnalysis.inventory.map(x=>x.legacyId),stageLegacyIds:state.migrationAnalysis.inventory.flatMap(x=>x.stages.map(s=>s.legacyId)),settingsLegacyIds:state.migrationAnalysis.sharedSettings.map(x=>x.settingsLegacyId)},review});
        renderWorkspace();notice('Portable draft валиден. Сначала выполните differential tests; публикация остаётся отдельным действием.');
      }
      if(action==='migration-create-draft'){
        const result=await api('migration.draft.create',{module:state.migrationDraft.module,expected:JSON.parse(q('#pwm-migration-expected').value),actual:JSON.parse(q('#pwm-migration-actual').value),absoluteTolerance:Number(q('#pwm-migration-tolerance').value||0)});
        await load();state.migrationMode=false;notice(`Черновик ${result.familyCode}@${result.version} создан после успешного differential test. Публикация не выполнялась.`);
      }
      if(action==='install-pilot'){await api('vertical.install');await load();notice('Этап, деталь и многосоставной фрагмент опубликованы.');}
      if(action==='load-instances')await loadInstances();
      if(action==='preview'){
        const instance=collectInstance();
        const result=await api('instance.preview',{versionId:Number(state.version.ID),instance,currentSnapshot:state.currentSnapshot?.SNAPSHOT,options:{snapshotId:'preview-'+Date.now(),presetRevision:state.editingInstance?Number(state.editingInstance.REVISION)+1:1,createdAt:new Date().toISOString(),resolvedBy:'admin-ui',resolverVersion:'1.0.0'}});
        state.preview=result.snapshot;state.previewDiff=result.diff||null;
        renderWorkspace();notice('Preview прошёл validate. Можно применить snapshot.');
      }
      if(action==='apply'){
        if(!state.preview)throw new Error('Сначала выполните Preview');
        const instance=collectInstance();if(!state.editingInstance)instance.instanceId='instance-'+crypto.randomUUID();
        const result=await api('instance.apply',{presetId:state.presetId,versionId:Number(state.version.ID),instance,options:{snapshotId:'snapshot-'+crypto.randomUUID(),presetRevision:state.editingInstance?Number(state.editingInstance.REVISION)+1:1,createdAt:new Date().toISOString(),resolvedBy:'admin-ui',resolverVersion:'1.0.0'},instanceRowId:state.editingInstance?.ID,expectedRevision:state.editingInstance?.REVISION});
        state.preview=null;state.previewDiff=null;state.editingInstance=null;state.currentSnapshot=null;await loadInstances();notice('Экземпляр применён. Snapshot '+result.snapshotHash);
      }
      if(action==='edit-cancel'){state.editingInstance=null;state.currentSnapshot=null;state.preview=null;state.previewDiff=null;renderWorkspace();}
      if(action==='edit-instance'){
        const instance=state.instances.find(x=>Number(x.ID)===Number(button.dataset.instance));
        const family=state.catalog.find(f=>f.CODE===instance.MODULE_FAMILY_CODE);
        if(!family)throw new Error('Семейство экземпляра не найдено');
        state.family=family;state.version=family.VERSIONS.find(v=>v.VERSION===instance.MODULE_VERSION)||family.VERSIONS[0];
        state.editingInstance=instance;const snapshots=await api('snapshots',{instanceId:Number(instance.ID)});
        state.currentSnapshot=snapshots.find(s=>Number(s.ID)===Number(instance.SNAPSHOT_ID))||null;
        state.preview=null;state.previewDiff=null;renderFamilies();renderModule();renderWorkspace();notice('Режим обновления: выберите точную версию, проверьте mapping и выполните Preview.');
      }
      if(action==='deprecate'||action==='archive'){
        await api('version.status',{versionId:Number(state.version.ID),status:action==='deprecate'?'deprecated':'archived',expectedRevision:Number(state.version.REVISION)});
        await load();
      }
      if(action==='history'){
        const snapshots=await api('snapshots',{instanceId:Number(button.dataset.instance)});
        const current=state.instances.find(x=>Number(x.ID)===Number(button.dataset.instance));
        q('#pwm-preview').innerHTML=`<div class="pwm-card"><div class="pwm-card__head">История snapshot</div><table class="pwm-table"><tbody>${snapshots.map(s=>`<tr><td>${esc(s.SNAPSHOT.familyId)}@${esc(s.SNAPSHOT.version)}<br><code>${esc(s.SNAPSHOT_HASH.slice(0,20))}…</code></td><td>rev ${esc(s.INSTANCE_REVISION)}</td><td>${Number(current.SNAPSHOT_ID)===Number(s.ID)?'<span class="pwm-badge pwm-badge--published">текущий</span>':`<button class="pwm-btn" data-action="rollback" data-instance="${current.ID}" data-snapshot="${s.ID}" data-revision="${current.REVISION}">Rollback</button>`}</td></tr>`).join('')}</tbody></table></div>`;
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
