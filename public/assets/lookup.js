'use strict';
let lookupVersion=0, lookupController=null, lookupTimer=null, lookupCandidates=[], lookupLinks=[], lookupProposals=null;
const lookupFieldLabels={name:'企業名',title:'募集名',url:'参照URL',industry:'領域・業界（候補）',tags:'タグ（候補）',notes:'概要・メモ',deadline:'応募締切（日本時間）',additional_deadlines:'追加締切',opens_at:'募集開始予定',recruitment_state:'募集状況',eligibility:'29卒の対象条件',eligibility_notes:'応募条件の抜粋',checked_at:'情報確認日'};
function lookupMarkup(entity,value) {
  const companyItem=entity==='companies'?value:company(value.company_id);
  return `<section class="lookup-panel" aria-label="公式情報から入力"><h3>企業を探して、公式情報から仮入力</h3>
    <p class="hint">企業候補は登録済み企業とWikidataから検索。募集情報は選んだページから読み取ります。候補・年度を確認して取り込んでください。</p>
    <div class="lookup-search"><label class="field">企業名で検索<input id="lookup-query" value="${escapeHTML(companyItem?.name||'')}" maxlength="100" placeholder="例：任天堂、トヨタ、サイバーエージェント" autocomplete="off"></label><button type="button" class="button" data-lookup="companies">企業候補を検索</button></div>
    <div id="lookup-candidates" class="lookup-results"></div>
    ${entity==='opportunities'?`<label class="field">採用サイトURL（募集候補を探す入口）<input id="lookup-root" type="url" value="${escapeHTML(companyItem?.research?.source_url||companyItem?.url||'')}" placeholder="https://"></label><button type="button" class="button" data-lookup="discover">入力した募集名で候補を探す</button><div id="lookup-links" class="lookup-results"></div>`:''}
    <label class="field">${entity==='companies'?'企業の公式ページURL':'取り込みたい募集の詳細ページURL'}<input id="lookup-url" type="url" value="${escapeHTML(value.url||'')}" placeholder="https://"></label>
    <details class="lookup-paste"><summary>ページを読み取れない場合：本文を貼り付ける</summary><label class="field">募集・企業紹介の本文<textarea id="lookup-text" rows="5" maxlength="60000" placeholder="コピーした本文を貼り付けてください。ここに入力がある場合は、HP取得の代わりに貼り付け本文を解析します。"></textarea></label></details>
    <div class="lookup-actions"><button type="button" class="button" data-lookup="preview">情報を読み取り・仮入力を作成</button><a id="lookup-web-search" class="text-button" target="_blank" rel="noopener noreferrer">Googleで公式ページを探す ↗</a></div>
    <p id="lookup-message" class="hint" role="status" aria-live="polite"></p><div id="lookup-preview"></div></section>`;
}
function setupLookup() {
  lookupVersion++;lookupController?.abort();clearTimeout(lookupTimer);lookupCandidates=[];lookupLinks=[];lookupProposals=null;
  if(editor?.entity==='opportunities'){
    const selected=company(document.querySelector('[name="company_id"]')?.value);
    const root=document.querySelector('#lookup-root'),q=document.querySelector('#lookup-query');
    if(selected&&root&&!root.value){root.value=selected.research?.source_url||selected.url||'';if(q)q.value=selected.name;}
  }
  refreshLookupSearch();toggleNewOpportunityCompany();
}
function refreshLookupSearch() {
  const link=document.querySelector('#lookup-web-search');if(!link)return;
  const name=document.querySelector('#lookup-query')?.value||'', title=editor?.entity==='opportunities'?document.querySelector('[name="title"]')?.value||'インターン':'';
  link.href='https://www.google.com/search?q='+encodeURIComponent(`${name} ${title} 公式`);
}
function toggleNewOpportunityCompany() {
  if(editor?.entity!=='opportunities')return;
  const selector=document.querySelector('[name="company_id"]'),box=document.querySelector('#opportunity-new-company');
  if(!selector||!box)return;
  const active=selector.value==='new';box.hidden=!active;
  box.querySelectorAll('input').forEach(el=>{el.disabled=!active;el.required=active&&el.name==='new_company_name';});
}
function lookupSet(name,value) {
  const input=document.querySelector(`[name="${name}"]`);if(!input)return;
  if(name==='deadline'){
    input.type=value.length===10?'date':'datetime-local';
    const check=document.querySelector('#deadline-date-only');if(check)check.checked=value.length===10;
  }
  input.value=value;
}
async function lookupRequest(payload) {
  lookupController?.abort();lookupController=new AbortController();const version=lookupVersion;
  const response=await fetch('/lookup.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload),signal:lookupController.signal});
  const result=await response.json();
  if(version!==lookupVersion||!document.querySelector('#lookup-message'))throw new DOMException('古いリクエスト','AbortError');
  if(!response.ok)throw new Error(result.error||'取得に失敗しました。');
  return result;
}
function lookupMessage(text) {const el=document.querySelector('#lookup-message');if(el)el.textContent=text;}
function renderLookupPreview(result) {
  lookupProposals=result;
  const allowed=editor.entity==='companies'?['name','url','industry','tags','notes']:['title','url','deadline','additional_deadlines','opens_at','recruitment_state','eligibility','eligibility_notes','checked_at','notes','tags'];
  const rows=Object.entries(result.fields||{}).filter(([key,value])=>allowed.includes(key)&&typeof value==='string'&&value!==''&&document.querySelector(`[name="${key}"]`));
  result.before=Object.fromEntries(rows.map(([key])=>[key,document.querySelector(`[name="${key}"]`).value]));
  const source=siteURL(result.source_url);
  document.querySelector('#lookup-preview').innerHTML=`<div class="lookup-proposal"><h4>取り込む項目を選択</h4><p class="hint">入力済みの項目は最初は未選択です。選択すると置き換わります。未確認の日付は補完しません。</p>${source?`<a class="source-link" href="${escapeHTML(source.href)}" target="_blank" rel="noopener noreferrer">参照ページを確認 ↗ ${escapeHTML(source.hostname)}</a>`:''}
    ${(result.warnings||[]).map(w=>`<p class="lookup-warning">${escapeHTML(w)}</p>`).join('')}
    ${rows.map(([key,value])=>{const display=key==='recruitment_state'?recruitmentLabels[value]:key==='eligibility'?eligibilityLabels[value]:value;const hasValue=result.before[key]!=='';return `<label class="lookup-proposal-row"><input type="checkbox" data-lookup-field="${key}" ${!hasValue?'checked':''}><span><strong>${escapeHTML(lookupFieldLabels[key]||key)}${hasValue?'（入力済み）':''}</strong><span class="lookup-value">${escapeHTML(display||value)}</span><small>根拠：${escapeHTML(result.evidence?.[key]||'候補情報')}</small></span></label>`;}).join('')}
    <button type="button" class="button primary" data-lookup="apply">選択した項目をフォームに反映</button><p class="hint">まだ保存されません。下のフォームで修正し、最後に「保存する」を押してください。</p></div>`;
  lookupMessage(rows.length?'仮入力を作成しました。内容と参照元を確認してください。':'入力できる情報が見つかりませんでした。本文貼り付けも利用できます。');
}
async function performLookup(action,button) {
  const version=lookupVersion;button.disabled=true;lookupMessage('確認しています…');
  try{
    if(action==='companies'){
      const query=document.querySelector('#lookup-query').value.trim();
      if(query.length<2){lookupMessage('企業名を2文字以上入力してください。');return;}
      const result=await lookupRequest({action:'companies',query});lookupCandidates=result.items;
      document.querySelector('#lookup-candidates').innerHTML=lookupCandidates.map((c,i)=>`<button type="button" class="lookup-result" data-lookup="choose-company" data-lookup-index="${i}"><strong>${escapeHTML(c.name)}</strong><span>${escapeHTML(c.description)}</span><small>${escapeHTML(c.source)}${c.kind==='saved'&&editor.entity==='companies'?' · 開いて編集':''}</small></button>`).join('');
      lookupMessage(result.warning||`${lookupCandidates.length}件の候補。企業名と説明を確認して選んでください。`);
    }else if(action==='choose-company'){
      const c=lookupCandidates[Number(button.dataset.lookupIndex)];if(!c)return;
      if(c.kind==='saved'&&editor.entity==='companies'){openEditor('companies',Number(c.id));lookupMessage('登録済みの企業を開きました。既存の内容を編集できます。');return;}
      const result=c.kind==='saved'?{fields:{name:c.name,url:c.url,notes:''}}:await lookupRequest({action:'company',id:c.id});
      const fields=result.fields;
      if(editor.entity==='companies'){
        lookupSet('name',fields.name);if(!document.querySelector('[name="notes"]').value)lookupSet('notes',fields.notes||'');lookupSet('url',fields.url||'');
        document.querySelector('#lookup-url').value=fields.url||'';
      }else{
        const existing=state.companies.find(x=>x.name===fields.name);
        lookupSet('company_id',existing?String(existing.id):'new');toggleNewOpportunityCompany();
        if(!existing){lookupSet('new_company_name',fields.name);lookupSet('new_company_url',fields.url||'');}
        document.querySelector('#lookup-root').value=existing?.research?.source_url||fields.url||'';
      }
      document.querySelector('#lookup-query').value=fields.name;refreshLookupSearch();
      lookupMessage(fields.url?'企業候補を反映しました。公式URLと同名企業を確認し、ページを読み取ってください。':'企業名を反映しました。公式URLが見つからなかったため、URLを入力してください。');
    }else if(action==='discover'){
      const title=document.querySelector('[name="title"]').value;
      const result=await lookupRequest({action:'discover',url:document.querySelector('#lookup-root').value,query:title,entity:'opportunities'});
      const saved=state.opportunities.filter(o=>Number(o.company_id)===Number(document.querySelector('[name="company_id"]').value)&&(!title||o.title.includes(title))&&siteURL(o.url)).map(o=>({name:'登録済み：'+o.title,url:o.url}));
      lookupLinks=[...saved,...result.items].filter((l,i,all)=>all.findIndex(x=>x.url===l.url)===i);
      document.querySelector('#lookup-links').innerHTML=lookupLinks.map((l,i)=>`<div class="lookup-link-row"><button type="button" class="lookup-result" data-lookup="choose-link" data-lookup-index="${i}"><strong>${escapeHTML(l.name)}</strong><small>${escapeHTML(siteURL(l.url)?.hostname||'')}</small></button><a href="${escapeHTML(l.url)}" target="_blank" rel="noopener noreferrer" aria-label="${escapeHTML(l.name)}を別タブで確認">↗</a></div>`).join('');
      lookupMessage(result.warning);
    }else if(action==='choose-link'){
      const link=lookupLinks[Number(button.dataset.lookupIndex)];if(!link)return;
      document.querySelector('#lookup-url').value=link.url;document.querySelector('#lookup-root').value=link.url;
      document.querySelector('#lookup-text').value='';
      const result=await lookupRequest({action:'preview',url:link.url,entity:editor.entity});renderLookupPreview(result);
    }else if(action==='preview'){
      const result=await lookupRequest({action:'preview',entity:editor.entity,url:document.querySelector('#lookup-url').value,text:document.querySelector('#lookup-text').value});renderLookupPreview(result);
    }else if(action==='apply'){
      if(!lookupProposals)return;let count=0,skipped=0;
      document.querySelectorAll('[data-lookup-field]:checked').forEach(box=>{
        const key=box.dataset.lookupField, input=document.querySelector(`[name="${key}"]`);
        if(!input||input.value!==lookupProposals.before[key]){skipped++;return;}
        lookupSet(key,lookupProposals.fields[key]);count++;
      });
      if(count){
        const notes=document.querySelector('[name="notes"]');
        const reference=`参照元：${lookupProposals.source_url}（取り込み日：${localDay(new Date())}）`;
        if(notes&&!notes.value.includes(reference))notes.value=(notes.value?notes.value+'\n\n':'')+reference;
      }
      document.querySelector('#lookup-preview').innerHTML='';lookupProposals=null;
      lookupMessage(`${count}項目を仮入力しました。手動で確認・修正してから保存してください。${skipped?' 途中で編集した項目は保持しました。':''}`);
    }
  }catch(error){if(error.name!=='AbortError'&&version===lookupVersion)lookupMessage(error.message);}
  finally{button.disabled=false;}
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-lookup]');if(button)performLookup(button.dataset.lookup,button);});
document.addEventListener('input',event=>{
  if(event.target.id==='lookup-query'){
    lookupVersion++;lookupController?.abort();lookupCandidates=[];document.querySelector('#lookup-candidates').innerHTML='';
    refreshLookupSearch();clearTimeout(lookupTimer);
    if(event.target.value.trim().length>=2)lookupTimer=setTimeout(()=>{const button=document.querySelector('[data-lookup="companies"]');if(button)performLookup('companies',button);},650);
  }
  if(['name','new_company_name'].includes(event.target.name)&&document.querySelector('#lookup-query')){
    document.querySelector('#lookup-query').value=event.target.value;
  }
  if(['name','title','new_company_name'].includes(event.target.name))refreshLookupSearch();
});
document.addEventListener('change',event=>{
  if(event.target.name==='company_id'&&editor?.entity==='opportunities'){
    toggleNewOpportunityCompany();const c=company(event.target.value);
    if(c){document.querySelector('#lookup-query').value=c.name;document.querySelector('#lookup-root').value=c.research?.source_url||c.url||'';refreshLookupSearch();}
  }
});

document.addEventListener('keydown',event=>{
  const actions={'lookup-query':'companies','lookup-root':'discover','lookup-url':'preview'};
  if(event.key==='Enter'&&!event.isComposing&&actions[event.target.id]){
    event.preventDefault();const action=actions[event.target.id],button=document.querySelector(`[data-lookup="${action}"]`);
    if(button&&!button.disabled)performLookup(action,button);
  }
});
