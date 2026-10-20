// Render-logic checks; these do not replace visual browser QA.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const elements = new Map();
const listeners = new Map();
const document = {
  querySelector(selector) {
    if (selector.startsWith('[name=')) return null;
    if (!elements.has(selector)) elements.set(selector, { innerHTML: '', textContent: '', value: '', classList: { toggle() {} }, addEventListener() {}, showModal() {}, scrollTop: 0, focus() {}, setSelectionRange() {} });
    return elements.get(selector);
  },
  addEventListener(type, listener) { listeners.set(type, [...(listeners.get(type) || []), listener]); },
};
const source = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
const lookupSource = readFileSync(new URL('../public/assets/lookup.js', import.meta.url), 'utf8');
const setup = lookupSource + '\n' + source.slice(0, source.lastIndexOf('request().then(navigate)'));
const context = vm.createContext({ document, listeners, window: { addEventListener() {} }, location: { hash: '' }, URL, console, setTimeout, clearTimeout, assert });
vm.runInContext(setup + `
assert.equal(escapeHTML('<script>"&'), '&lt;script&gt;&quot;&amp;');
const searchInput={id:'search',value:'さ',selectionStart:1};
for(const listener of listeners.get('compositionstart')||[])listener({target:searchInput});
for(const listener of listeners.get('input')||[])listener({target:searchInput,isComposing:true});
assert.equal(query,'','Search must not rerender during IME composition');
for(const listener of listeners.get('compositionend')||[])listener({target:searchInput});
assert.equal(query,'さ','Search should update after IME composition ends');
const lookupInput= document.querySelector('#lookup-query');
lookupInput.value='';
const companyNameInput={name:'name',value:'さ'};
for(const listener of listeners.get('compositionstart')||[])listener({target:companyNameInput});
for(const listener of listeners.get('input')||[])listener({target:companyNameInput,isComposing:true});
assert.equal(lookupInput.value,'','Company-name sync must wait during IME composition');
for(const listener of listeners.get('compositionend')||[])listener({target:companyNameInput});
assert.equal(lookupInput.value,'さ','Company-name sync should run after IME composition ends');
query='';
for (const renderer of [renderDashboard, renderOpportunities, renderCalendar, renderDocuments, renderExperiences, renderCompanies]) {
  assert.ok(renderer().length > 100);
}
state.companies = [{id:1,name:'<script>alert(1)</script>',industry:'IT',notes:'企業',url:''}];
state.opportunities = [{id:1,company_id:1,title:'テスト募集',deadline:'2028-02-29T23:59',status:'writing',priority:'high',notes:'',url:''}];
state.experiences = [{id:1,title:'開発経験',period:'夏',situation:'背景',action:'行動',result:'結果',learning:'学び',tags:'開発,チームワーク'}];
state.documents = [{id:1,title:'ガクチカ',opportunity_id:1,experience_id:1,question:'設問',body:'<script>😀',word_limit:400,tags:'開発',status:'submitted',updated_at:'2026-10-06T12:00:00+09:00'}];
assert.ok(renderDocuments().includes('応募先：&lt;script&gt;alert(1)&lt;/script&gt; / テスト募集'));
assert.ok(renderDocuments().includes('tag tag-application'));
state.opportunities[0].title='募集名を更新';
assert.ok(applicationTag(state.documents[0]).includes('募集名を更新'));
state.opportunities[0].title='テスト募集';
query='テスト募集'; assert.ok(renderDocuments().includes('ガクチカ')); query='';
assert.equal(allEvents().length, 1);
state.opportunities[0].status='closed';
assert.equal(allEvents().length, 0);
state.opportunities[0].status='writing';
month = new Date(2028,1,1);
assert.ok(renderCalendar().includes('data-day="2028-02-29"'));
assert.equal((renderCalendar().match(/class="calendar-day /g)||[]).length,42);
for (const renderer of [renderDashboard, renderOpportunities, renderCalendar, renderDocuments, renderExperiences, renderCompanies]) {
  assert.ok(!renderer().includes('<script>'));
}
query='存在しない検索語'; assert.ok(renderDocuments().includes('ESをストックしよう')); query='';
openEditor('documents',1); assert.equal(document.querySelector('#save-button').hidden,false);
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('<fieldset disabled>'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('応募先・タグの整理'));
assert.ok(document.querySelector('#editor-fields').innerHTML.indexOf('name="opportunity_id"') > document.querySelector('#editor-fields').innerHTML.indexOf('</fieldset>'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="progress_note"'));
openEditor('documents',1,true); assert.equal(editor.id,0); assert.equal(document.querySelector('#save-button').hidden,false);
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('（コピー）'));
state.companies[0].url='https://example.com/';
state.companies[0].secondary_url='https://group.example.com/';
state.companies[0].category='検討分類'; state.companies[0].tags='BizDev';
assert.ok(companyIcon(state.companies[0]).includes('href="https://example.com/"'));
assert.ok(companyIcon(state.companies[0]).includes('rel="noopener noreferrer"'));
assert.ok(companySources(state.companies[0]).includes('group.example.com'));
assert.ok(!companyIcon({name:'悪意あるURL',url:'javascript:alert(1)'}).includes('href='));
assert.equal(siteURL('https://official.example@evil.example/'),null);
assert.ok(!companyIcon({name:'URL未登録'}).includes('href='));
assert.ok(opportunityRow(state.opportunities[0]).includes('company-site-icon'));
mode='board'; assert.ok(renderOpportunities().includes('company-site-icon')); mode='list';
query='BizDev'; assert.ok(renderCompanies().includes('company-site-icon')); query='';
assert.ok(renderCompanies().includes('data-action="delete"'));
openEditor('companies',1); assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="secondary_url"'));
state.companies[0].favicon_path='/assets/company-icons/d723ed87436ec3e5.png';
assert.ok(companyIcon(state.companies[0]).includes('<img'));
assert.ok(!companyIcon({...state.companies[0],favicon_path:'https://evil.example/pixel'}).includes('<img'));
state.opportunities[0].opens_at='2026-11';
state.opportunities[0].additional_deadlines='2028-03-10T10:00,2028-02-29T23:59';
state.opportunities[0].eligibility='ineligible';
assert.equal(allEvents().length,2);
assert.ok(allEvents().every(e=>e.title.includes('29卒対象外')));
month=new Date(2026,10,1);
assert.ok(renderCalendar().includes('2026年11月頃（日未公表）'));
assert.ok(!allEvents().some(e=>e.kind==='opening'));
state.opportunities[0].opens_at='2026-11-12';
assert.ok(allEvents().some(e=>e.kind==='opening'&&e.date==='2026-11-12'));
state.opportunities[0].status='applied';
assert.equal(allEvents().length,0,'Applied internship must not publish later recruitment rounds');
assert.ok(!renderCalendar().includes('2026年11月頃（日未公表）'));
assert.ok(renderDashboard().includes('締切が近い募集 <span class="count">0'),'Applied internship must leave deadline dashboard');
assert.ok(opportunityRow(state.opportunities[0]).includes('応募済み・追加募集の通知なし'));
assert.ok(opportunityProgress({status:'screening',progress_log:[{from_status:'applied',to_status:'screening',occurred_at:'2026-10-20T14:00',note:'<script>bad</script>'}]}).includes('&lt;script&gt;'));
assert.ok(opportunityProgressPreview({progress_log:[{occurred_at:'2026-10-20T14:00',note:'<script>bad</script>'}]}).includes('&lt;script&gt;'));
state.events=[{id:1,opportunity_id:1,title:'面接の予定',starts_at:'2026-11-13',kind:'interview',notes:''}];
assert.equal(allEvents().length,1,'Manually entered selection events must remain visible');
state.events=[];
state.opportunities[0].status='writing';
assert.equal(dateLabel('2026-12-31',true),'12/31（時刻未公表）');
assert.equal(dateLabel('2026-10-26T10:00',true),'10/26 10:00');
assert.equal(deadlineTime('2026-12-31').toISOString(),'2026-12-31T14:59:59.000Z');
eligibilityFilter='eligible';assert.ok(renderOpportunities().includes('このタブに募集はありません'));eligibilityFilter='';
openEditor('opportunities');
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('<select name="company_id" required><option value="" selected>企業を選択してください（例：サイバーエージェント）</option>'));
openEditor('opportunities',1);assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="opens_at"'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('<option value="1" selected>&lt;script&gt;alert(1)&lt;/script&gt;</option>'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="progress_at"'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="progress_note"'));
openEditor('documents');
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('自由記述で応募先を追加'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('自由記述で経験を追加'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('確認・添削中'));
assert.ok(documentProgress({status:'reviewing',progress_log:[{from_status:'draft',to_status:'reviewing',created_at:'2026-10-06T10:00:00+09:00',note:'<script>bad</script>'}]},false).includes('&lt;script&gt;'));
openEditor('companies',1);assert.ok(document.querySelector('#editor-fields').innerHTML.includes('lookup-query'));
openEditor('opportunities',1);assert.ok(document.querySelector('#editor-fields').innerHTML.includes('opportunity-new-company'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('lookup-root'));
assert.ok(!lookupMarkup('companies',{name:'<script>',url:'https://example.com'}).includes('<script>'));
state.companies.push({...state.companies[0],id:2,name:'企業2'});
state.opportunities.push({...state.opportunities[0],id:2,company_id:2,title:'募集2'});
state.documents.push({...state.documents[0],id:2,title:'ES2'});
state.experiences.push({...state.experiences[0],id:2,title:'経験2'});
companyView='orbit';
assert.ok(renderCompanies().includes('company-orbit-stage'));
assert.equal((renderCompanies().match(/class="company-orbit-card /g)||[]).length,2);
assert.ok(renderCompanies().includes('data-action="company-orbit-focus"'));
assert.ok(renderCompanies().includes('ノートを開く'));
assert.equal(companyOrbitClass(0),'company-orbit-offset-p0');
assert.equal(companyOrbitClass(-3),'company-orbit-offset-hidden-m');
const orbitSample=Array.from({length:7},(_,i)=>({...state.companies[0],id:100+i,name:'企業'+i}));
const orbitMarkup=companyOrbit(orbitSample);
assert.equal((orbitMarkup.match(/class="company-orbit-card /g)||[]).length,7);
assert.equal((orbitMarkup.match(/company-orbit-offset-hidden-/g)||[]).length,2);
companyView='cards';
const sortedPages = [
  ['opportunities',renderOpportunities,'募集2','テスト募集'],
  ['documents',renderDocuments,'ES2','ガクチカ'],
  ['experiences',renderExperiences,'経験2','開発経験'],
  ['companies',renderCompanies,'企業2','&lt;script&gt;alert(1)&lt;/script&gt;'],
];
for (const [entity,renderer,newer,older] of sortedPages) {
  assert.ok(renderer().indexOf(newer) < renderer().indexOf(older));
  sortOrders[entity]='asc';
  assert.ok(renderer().indexOf(older) < renderer().indexOf(newer));
  sortOrders[entity]='desc';
}
mode='board';
sortOrders.opportunities='asc';
assert.ok(renderOpportunities().indexOf('テスト募集') < renderOpportunities().indexOf('募集2'));
state.opportunities[0].deadline='2099-02-01';
state.opportunities[0].additional_deadlines='';
state.opportunities[1].deadline='2099-01-01';
state.opportunities[1].additional_deadlines='';
state.opportunities.push({...state.opportunities[0],id:3,title:'過去締切',deadline:'2020-01-01'});
state.opportunities.push({...state.opportunities[0],id:4,title:'締切未設定',deadline:''});
sortOrders.opportunities='deadline';
assert.deepEqual(Array.from(sortItems([...state.opportunities],'opportunities'),item=>item.id),[2,1,3,4]);
assert.ok(renderOpportunities().indexOf('募集2') < renderOpportunities().indexOf('テスト募集'));
state.opportunities[0].additional_deadlines='2098-01-01';
assert.deepEqual(Array.from(sortItems([...state.opportunities],'opportunities'),item=>item.id),[1,2,3,4]);
state.documents[0].updated_at='2026-11-01T00:00:00+09:00';
state.documents[1].updated_at='2026-10-01T00:00:00+09:00';
sortOrders.documents='updated';
assert.ok(renderDocuments().indexOf('ガクチカ') < renderDocuments().indexOf('ES2'));
state.companies[0].name='マネーフォワード';
state.companies[1].name='サイバーエージェント';
sortOrders.companies='name';
assert.ok(renderCompanies().indexOf('サイバーエージェント') < renderCompanies().indexOf('マネーフォワード'));
assert.ok(sortControl('opportunities').includes('締切が近い順'));
assert.ok(sortControl('documents').includes('最終更新：新しい順'));
assert.ok(sortControl('companies').includes('企業名順'));
state.events = [{id:1,opportunity_id:1,title:'Web適性検査の締切',starts_at:'2026-10-25',kind:'deadline',notes:''}];
openEditor('events',1);
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="starts_at" type="date"'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('id="event-date-only" checked'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('<option value="deadline" selected>締切</option>'));
state.documents = [
  {id:10,title:'購買体験 Q1',body:'ランニングシューズを友人と販売員に相談して選んだ。'.repeat(4),tags:'ランニングシューズ,購買体験',experience_id:20,word_limit:1000,status:'submitted'},
  {id:11,title:'購買体験 Q2',body:'ランニングシューズを友人と販売員に相談して選んだ。'.repeat(3),tags:'ランニングシューズ,購買体験',experience_id:21,word_limit:1000,status:'submitted'},
  {id:12,title:'別の話題',body:'音楽ライブの演出について考えた。',tags:'音楽ライブ',experience_id:null,word_limit:1000,status:'submitted'},
];
state.experiences = [
  {id:20,title:'購入時の選択',situation:'ランニングシューズの購入',action:'友人に相談',result:'選び直した',learning:'納得した',tags:'ランニングシューズ,購買体験'},
  {id:21,title:'情報の接点',situation:'ランニングシューズの購入',action:'販売員に相談',result:'選び直した',learning:'接点の重要性',tags:'ランニングシューズ,購買体験'},
];
const similarDocs = renderDocuments(), similarExperiences = renderExperiences();
assert.ok(similarDocs.includes('似た内容 2件'));
assert.ok(similarDocs.includes('data-entity="documents" data-id="11"'));
assert.ok(similarExperiences.includes('似た内容'));
assert.ok(!similarityIndex().get('documents:10').some(match=>match.entity==='experiences'&&match.item.id===20));
state.documents[1].title='<img src=x onerror=alert(1)>';
assert.ok(!renderDocuments().includes('<img src=x'));
console.log('PASS: all page renderers, HTML escaping, leap-year calendar, deadline filtering, search, submitted ES duplication');
`, context);
