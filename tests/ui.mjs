// Render-logic checks; these do not replace visual browser QA.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const elements = new Map();
const document = {
  querySelector(selector) {
    if (selector.startsWith('[name=')) return null;
    if (!elements.has(selector)) elements.set(selector, { innerHTML: '', textContent: '', classList: { toggle() {} }, addEventListener() {}, showModal() {}, scrollTop: 0 });
    return elements.get(selector);
  },
  addEventListener() {},
};
const source = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
const setup = source.slice(0, source.lastIndexOf('request().then(navigate)'));
const context = vm.createContext({ document, window: { addEventListener() {} }, location: { hash: '' }, URL, console, setTimeout, clearTimeout, assert });
vm.runInContext(setup + `
assert.equal(escapeHTML('<script>"&'), '&lt;script&gt;&quot;&amp;');
for (const renderer of [renderDashboard, renderOpportunities, renderCalendar, renderDocuments, renderExperiences, renderCompanies]) {
  assert.ok(renderer().length > 100);
}
state.companies = [{id:1,name:'<script>alert(1)</script>',industry:'IT',notes:'企業',url:''}];
state.opportunities = [{id:1,company_id:1,title:'テスト募集',deadline:'2028-02-29T23:59',status:'writing',priority:'high',notes:'',url:''}];
state.experiences = [{id:1,title:'開発経験',period:'夏',situation:'背景',action:'行動',result:'結果',learning:'学び',tags:'開発,チームワーク'}];
state.documents = [{id:1,title:'ガクチカ',opportunity_id:1,experience_id:1,question:'設問',body:'<script>😀',word_limit:400,tags:'開発',status:'submitted',updated_at:'2026-10-06T12:00:00+09:00'}];
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
assert.equal(dateLabel('2026-12-31',true),'12/31（時刻未公表）');
assert.equal(dateLabel('2026-10-26T10:00',true),'10/26 10:00');
assert.equal(deadlineTime('2026-12-31').toISOString(),'2026-12-31T14:59:59.000Z');
eligibilityFilter='eligible';assert.ok(renderOpportunities().includes('募集が見つかりません'));eligibilityFilter='';
openEditor('opportunities',1);assert.ok(document.querySelector('#editor-fields').innerHTML.includes('name="opens_at"'));
openEditor('documents');
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('自由記述で応募先を追加'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('自由記述で経験を追加'));
assert.ok(document.querySelector('#editor-fields').innerHTML.includes('確認・添削中'));
assert.ok(documentProgress({status:'reviewing',progress_log:[{from_status:'draft',to_status:'reviewing',created_at:'2026-10-06T10:00:00+09:00',note:'<script>bad</script>'}]},false).includes('&lt;script&gt;'));
console.log('PASS: all page renderers, HTML escaping, leap-year calendar, deadline filtering, search, submitted ES duplication');
`, context);
