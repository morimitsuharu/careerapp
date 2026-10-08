'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icons = {dashboard:'◫', opportunities:'▤', calendar:'▦', documents:'▧', experiences:'◇', companies:'▥'};
const pages = {dashboard:'ダッシュボード', opportunities:'募集・選考管理', calendar:'カレンダー', documents:'ESライブラリ', experiences:'経験・強み', companies:'企業ノート'};
const statusLabels = {interested:'気になる', planned:'応募予定', writing:'ES作成中', applied:'応募済み', screening:'書類通過', interview:'面接', accepted:'参加決定', closed:'終了'};
const docLabels = {draft:'下書き', reviewing:'確認・添削中', revising:'修正中', ready:'完成', submitted:'提出済み'};
const eligibilityLabels = {eligible:'29卒：卒年条件に含む', check:'29卒：要確認', ineligible:'29卒：対象外'};
const recruitmentLabels = {open:'確認時は受付中', upcoming:'募集開始予定', unknown:'受付状況未確認', closed:'受付終了'};
let eligibilityFilter = '';
const kindLabels = {opening:'募集開始', deadline:'締切', briefing:'説明会', interview:'面接', internship:'インターン', other:'その他'};
let state = {companies:[], opportunities:[], documents:[], experiences:[], events:[]};
let csrf = '', page = 'dashboard', query = '', statusFilter = '', mode = 'list', editor = null, pendingDelete = null;
const sortOrders = {opportunities:'desc', documents:'desc', experiences:'desc', companies:'desc'};
const sortOptions = {
  opportunities:[['deadline','締切が近い順']],
  documents:[['updated','最終更新：新しい順']],
  companies:[['name','企業名順']],
};
let month = new Date(new Date().getFullYear(), new Date().getMonth(), 1), selectedDay = '';
const app = $('#app');
const localDay = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const dateLabel = (value, time = false) => {
  if (!value) return '未公表・未設定';
  if (value.length === 7) return `${value.slice(0,4)}年${Number(value.slice(5))}月頃（日未公表）`;
  const label = `${Number(value.slice(5,7))}/${Number(value.slice(8,10))}`;
  return label + (time ? (value.length === 10 ? '（時刻未公表）' : ` ${value.slice(11,16)}`) : '');
};
const deadlineTime = value => new Date(value.length === 10 ? value + 'T23:59:59+09:00' : value + '+09:00');
const deadlineDates = item => [...new Set([item.deadline, ...(item.additional_deadlines || '').split(',').map(v=>v.trim())].filter(Boolean))].sort();
const nextDeadline = item => deadlineDates(item).find(v=>deadlineTime(v)>=new Date()) || deadlineDates(item).at(-1) || '';
function recruitmentInfo(item) {
  const source = siteURL(item.url);
  return `<div class="recruitment-info">${item.recruitment_state ? badge(item.recruitment_state,recruitmentLabels) : ''}${item.eligibility ? badge(item.eligibility,eligibilityLabels) : ''}${item.checked_at ? `<small>${escapeHTML(item.checked_at)} 確認</small>` : ''}${source ? `<a href="${escapeHTML(source.href)}" target="_blank" rel="noopener noreferrer">公式募集 ↗</a>` : ''}</div>`;
}
function companyResearch(item) {
  const r=item.research, url=siteURL(r?.source_url);
  if(!r) return '';
  return `<div class="research-note"><strong>${escapeHTML(r.result)}</strong><p>${escapeHTML(r.summary)}</p><small>${escapeHTML(r.checked_at)} 確認</small>${url?` · <a href="${escapeHTML(url.href)}" target="_blank" rel="noopener noreferrer">確認元 ↗</a>`:''}</div>`;
}

const company = id => state.companies.find(item => Number(item.id) === Number(id));
const opportunity = id => state.opportunities.find(item => Number(item.id) === Number(id));
const companyName = id => company(id)?.name || '企業未設定';
function siteURL(value) {
  try { const url = new URL(value); return ['https:','http:'].includes(url.protocol) && !url.username && !url.password ? url : null; } catch { return null; }
}
function companyIcon(item) {
  const name = item?.name || '企業未設定', url = siteURL(item?.url);
  const monogram = escapeHTML(Array.from(name).slice(0, /^[A-Za-z]/.test(name) ? 2 : 1).join(''));
  const favicon = /^\/assets\/company-icons\/[a-f0-9]+\.(png|ico|gif|jpg)$/.test(item?.favicon_path || '') ? `<img class="company-favicon" src="${escapeHTML(item.favicon_path)}" alt="" width="28" height="28" loading="lazy">` : '';
  if (!url) return `<span class="company-icon" title="公式サイトURL未登録">${monogram}</span>`;
  return `<a class="company-icon company-site-icon" href="${escapeHTML(url.href)}" target="_blank" rel="noopener noreferrer" aria-label="${escapeHTML(name)}の公式サイトを開く（新しいタブ）" title="${escapeHTML(name)}｜${escapeHTML(url.hostname)}"><span>${monogram}</span>${favicon}<span class="site-arrow" aria-hidden="true">↗</span></a>`;
}
function companySources(item) {
  const links = [[item.url, '公式サイト'], [item.secondary_url, '関連公式サイト'], [item.favicon_source_url, 'アイコン出典']].map(([value,label]) => {
    const url = siteURL(value);
    return url ? `<a class="source-link" href="${escapeHTML(url.href)}" target="_blank" rel="noopener noreferrer"><span>${label} ↗</span><small>${escapeHTML(url.hostname)}</small></a>` : '';
  }).filter(Boolean);
  return links.length ? `<div class="company-sources">${links.join('')}</div>` : '<p class="hint">公式サイトURLを登録すると、アイコンから開けます。</p>';
}
const active = item => !['closed','accepted'].includes(item.status);
const deadlineSort = (a,b) => nextDeadline(a).localeCompare(nextDeadline(b));
const tagCategories = [
  {key:'purpose', label:'応募・文章の用途', names:['志望動機','ガクチカ','自己PR','自己ＰＲ','自己紹介','長所','短所','強み','弱み','原体験']},
  {key:'skill', label:'強み・能力', names:['チームワーク','観察力','課題解決','リーダーシップ','ファシリテーション','ソフトスキル','意思決定','優先順位','役割発見','コミュニケーション','主体性','協調性','振り返り']},
  {key:'activity', label:'経験・活動', names:['チーム開発','開発','Web開発','プログラミング','ハッカソン','学業','授業','アルバイト','部活動','サークル','留学','ボランティア','個人開発','音楽ライブ']},
  {key:'interest', label:'興味・領域', names:['ゲーム','ゲーム企画','企画','広告','データ分析','可視化','経営学','新規事業','事業開発','BizDev','マーケ','マーケティング','エンタメ','コンテンツ','体験設計']},
];
const normalizeTag = value => value.normalize('NFKC').toLocaleLowerCase();
const tagCategory = tag => tagCategories.find(group=>group.names.some(name=>normalizeTag(name)===normalizeTag(tag))) || {key:'other',label:'その他・未分類'};
const tags = (value, colored = false) => String(value || '').split(/[,、]/).map(t => t.trim()).filter(Boolean).map(t => {
  const category=tagCategory(t);
  return `<span class="tag${colored?' tag-'+category.key:''}"${colored?` title="${escapeHTML(category.label)}"`:''}>${escapeHTML(t)}</span>`;
}).join('');
const tagLegend = () => `<div class="tag-legend" aria-label="2ページ共通のタグ色"><span>共通のタグ色</span>${[...tagCategories,{key:'other',label:'その他・未分類'}].map(c=>`<span class="tag tag-${c.key}">${c.label}</span>`).join('')}</div>`;

const badge = (status, labels = statusLabels) => `<span class="badge ${escapeHTML(status)}">${escapeHTML(labels[status] || status)}</span>`;
const matches = (...values) => values.join(' ').toLocaleLowerCase().includes(query.toLocaleLowerCase());
const editButton = (entity, id, text = '編集') => `<button class="text-button" data-action="edit" data-entity="${entity}" data-id="${id}">${text}</button>`;
const deleteButton = (entity, id) => `<button class="text-button muted" data-action="delete" data-entity="${entity}" data-id="${id}" aria-label="削除">削除</button>`;
function due(item) {
  const deadline = nextDeadline(item);
  if (!deadline) return '<span class="muted">締切未公表・未設定</span>';
  const diff = deadlineTime(deadline) - new Date();
  if (!active(item)) return `<span class="muted">${dateLabel(item.deadline)}</span>`;
  if (diff < 0) return '<span class="deadline overdue">締切を過ぎています</span>';
  if (deadline.length === 10) return `<span class="deadline">${dateLabel(deadline)}締切・時刻未公表</span>`;
  const days = Math.ceil(diff / 86400000);
  return `<span class="deadline ${days <= 3 ? 'urgent' : ''}">${days <= 1 ? '24時間以内' : `あと${days}日`}</span>`;
}
function toast(message) { const el = $('#toast'); el.textContent = message; el.classList.add('visible'); clearTimeout(toast.timer); toast.timer = setTimeout(() => el.classList.remove('visible'), 3500); }
async function request(payload) {
  const response = await fetch('/api.php', payload ? {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrf}, body:JSON.stringify(payload)} : {});
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || '通信に失敗しました。');
  if (result.csrf) csrf = result.csrf;
  state = result.data;
  return result;
}
function empty(title, text, entity) { return `<div class="empty"><span>◇</span><h3>${title}</h3><p>${text}</p>${entity ? `<button class="button primary" data-action="new" data-entity="${entity}">＋ 登録する</button>` : ''}</div>`; }
function heading(kicker, title, subtitle, entity, button) { return `<div class="page-heading"><div><p class="eyebrow">${kicker}</p><h1>${title}</h1><p class="subtitle">${subtitle}</p></div>${entity ? `<button class="button primary" data-action="new" data-entity="${entity}">＋ ${button || '新しく登録'}</button>` : ''}</div>`; }
function toolbar(extra = '') { return `<div class="toolbar"><label class="search"><span>⌕</span><input id="search" type="search" placeholder="キーワード・タグで検索" value="${escapeHTML(query)}" aria-label="キーワード・タグで検索"></label>${extra}</div>`; }
function sortControl(entity) {
  const options = [['desc','追加順：降順（新しい順）'],['asc','追加順：昇順（古い順）'],...(sortOptions[entity] || [])];
  return `<select id="sort-order" aria-label="並び替え">${options.map(([value,label])=>`<option value="${value}" ${sortOrders[entity]===value?'selected':''}>${label}</option>`).join('')}</select>`;
}
function sortItems(items, entity) {
  const order = sortOrders[entity], newestFirst = (a,b) => Number(b.id)-Number(a.id);
  if (order === 'asc') return items.sort((a,b) => -newestFirst(a,b));
  if (order === 'name') return items.sort((a,b) => a.name.localeCompare(b.name,'ja') || newestFirst(a,b));
  if (order === 'updated') return items.sort((a,b) => {
    const updatedA = Date.parse(a.updated_at), updatedB = Date.parse(b.updated_at);
    return (Number.isFinite(updatedB) ? updatedB : -Infinity) - (Number.isFinite(updatedA) ? updatedA : -Infinity) || newestFirst(a,b);
  });
  if (order === 'deadline') {
    const now = Date.now();
    const deadline = item => {
      const value = nextDeadline(item), time = value ? deadlineTime(value).getTime() : NaN;
      return Number.isFinite(time) ? time : null;
    };
    return items.sort((a,b) => {
      const timeA = deadline(a), timeB = deadline(b);
      const groupA = timeA === null ? 2 : timeA >= now ? 0 : 1;
      const groupB = timeB === null ? 2 : timeB >= now ? 0 : 1;
      return groupA - groupB || (groupA === 0 ? timeA - timeB : groupA === 1 ? timeB - timeA : 0) || newestFirst(a,b);
    });
  }
  return items.sort(newestFirst);
}
function opportunityRow(item) {
  const docs = state.documents.filter(d => Number(d.opportunity_id) === Number(item.id));
  return `<tr><td><div class="company-cell">${companyIcon(company(item.company_id))}<div><strong>${escapeHTML(companyName(item.company_id))}</strong><button class="row-title" data-action="edit" data-entity="opportunities" data-id="${item.id}">${escapeHTML(item.title)}</button>${recruitmentInfo(item)}</div></div></td><td>${badge(item.status)}</td><td><div>${dateLabel(nextDeadline(item), true)}</div>${due(item)}</td><td><span class="priority ${item.priority}">●</span> ${{high:'高',medium:'中',low:'低'}[item.priority]}</td><td>${docs.length ? `${docs.filter(d => ['ready','submitted'].includes(d.status)).length} / ${docs.length} 完成` : '<span class="muted">未登録</span>'}</td><td>${editButton('opportunities',item.id,'開く ↗')}</td></tr>`;
}
function opportunityTable(items) { return `<div class="table-scroll"><table><thead><tr><th>企業・募集</th><th>選考ステータス</th><th>応募締切</th><th>優先度</th><th>ES</th><th></th></tr></thead><tbody>${items.map(opportunityRow).join('')}</tbody></table></div>`; }
function allEvents() {
  const generated = state.opportunities.filter(active).flatMap(o => {
    const title = `${companyName(o.company_id)} / ${o.title}${o.eligibility==='ineligible'?'（29卒対象外）':''}`;
    const events = deadlineDates(o).map(date=>({id:o.id,title,date,kind:'deadline',entity:'opportunities'}));
    if (o.opens_at && o.opens_at.length >= 10) events.push({id:o.id,title,date:o.opens_at,kind:'opening',entity:'opportunities'});
    return events;
  });
  return [...state.events.map(e=>({...e,date:e.starts_at,entity:'events'})),...generated].sort((a,b)=>a.date.localeCompare(b.date));
}
function monthOpenings() {
  const key=localDay(month).slice(0,7), items=state.opportunities.filter(o=>active(o)&&o.opens_at===key);
  return items.length?`<section class="panel month-openings"><h2>今月の募集開始予定 <small>日付未公表</small></h2>${items.map(o=>`<div><button class="row-title" data-action="edit" data-entity="opportunities" data-id="${o.id}">${escapeHTML(companyName(o.company_id))} / ${escapeHTML(o.title)}</button><p>${dateLabel(o.opens_at)} · ${escapeHTML(o.eligibility_notes)}</p>${recruitmentInfo(o)}</div>`).join('')}</section>`:'';
}
function eventRows(events) { return events.map(e => `<button class="event-row" data-action="edit" data-entity="${e.entity}" data-id="${e.id}"><span class="event-date">${Number(e.date.slice(8,10))}<small>${Number(e.date.slice(5,7))}月</small></span><span class="event-line ${e.kind}"></span><span class="event-info"><strong>${escapeHTML(e.title)}</strong><small>${kindLabels[e.kind]} · ${dateLabel(e.date,true)}</small></span><span>↗</span></button>`).join(''); }
function renderDashboard() {
  const upcoming = state.opportunities.filter(o => active(o) && nextDeadline(o) && deadlineTime(nextDeadline(o)) >= new Date()).sort(deadlineSort);
  const overdue = state.opportunities.filter(o => active(o) && nextDeadline(o) && deadlineTime(nextDeadline(o)) < new Date());
  const week = upcoming.filter(o => deadlineTime(nextDeadline(o)) - new Date() <= 7*86400000);
  const draft = state.documents.filter(d => ['draft','reviewing','revising'].includes(d.status));
  const events = allEvents().filter(e => new Date(e.date) >= new Date()).slice(0,4);
  return heading('MY CAREER, MY PACE', '次の一歩を、ここから。', '締切も、自分の可能性も。ひとつずつ整理していこう。', 'opportunities', '募集を登録') +
    `<div class="overview-note"><span class="note-icon">✳</span><div><strong>${week.length ? `今週は ${week.length} 件の応募締切があります` : '今日の小さな一歩を、大切に。'}</strong><p>${week.length ? '気になる募集のESを確認して、余裕を持って準備しよう。' : '気になる企業や、心に残った経験から記録してみよう。'}${overdue.length ? ` 締切を過ぎた募集が ${overdue.length} 件あります。` : ''}</p></div><a href="#calendar">カレンダーを見る →</a></div>` +
    `<div class="stats"><a href="#opportunities" class="stat"><span>進行中の募集 <span>↗</span></span><strong>${state.opportunities.filter(active).length}<small>件</small></strong><p>気になる企業との、新しい出会い</p></a><a href="#calendar" class="stat"><span>7日以内の締切 <span class="stat-dot amber"></span></span><strong>${week.length}<small>件</small></strong><p>余裕を持って、次の準備を</p></a><a href="#documents" class="stat"><span>作成中のES <span>↗</span></span><strong>${draft.length}<small>件</small></strong><p>あなたの想いを、言葉にする</p></a><a href="#experiences" class="stat"><span>ストックした経験 <span>↗</span></span><strong>${state.experiences.length}<small>件</small></strong><p>積み重ねてきた、自分だけの強み</p></a></div>` +
    `<div class="dashboard-grid"><section class="panel deadline-panel"><div class="section-heading"><h2>締切が近い募集 <span class="count">${upcoming.length}</span></h2><a href="#opportunities">すべて見る →</a></div>${upcoming.length ? upcoming.slice(0,4).map(o => `<div class="deadline-card"><div class="deadline-card-top">${companyIcon(company(o.company_id))}<span>${escapeHTML(companyName(o.company_id))}</span>${due(o)}</div><button class="card-title" data-action="edit" data-entity="opportunities" data-id="${o.id}">${escapeHTML(o.title)}</button>${recruitmentInfo(o)}<div class="deadline-card-bottom">${badge(o.status)}<span>${dateLabel(nextDeadline(o),true)} 締切</span></div></div>`).join('') : empty('最初の募集を登録しよう','企業・インターンの気になる情報を、ひとつに。','opportunities')}</section><section class="panel schedule-panel"><div class="section-heading"><h2>これからの予定</h2><a href="#calendar">↗</a></div>${events.length ? eventRows(events) : '<p class="small-empty">予定はまだありません。<br>説明会や面接を登録できます。</p>'}<button class="button full subtle" data-action="new" data-entity="events">＋ 予定を追加</button><div class="memo"><span>NOTE TO SELF</span><p>うまく話せなかったことも、<br>次に活かせる大切な経験。</p><a href="#experiences">経験を振り返る →</a></div></section></div>` +
    `<section class="panel recent"><div class="section-heading"><h2>最近のES</h2><a href="#documents">ライブラリへ →</a></div>${state.documents.length ? `<div class="document-mini-list">${[...state.documents].sort((a,b) => b.updated_at.localeCompare(a.updated_at)).slice(0,3).map(d => `<button class="document-mini" data-action="edit" data-entity="documents" data-id="${d.id}"><span class="document-symbol">▧</span><span><strong>${escapeHTML(d.title)}</strong><small>${escapeHTML(companyName(opportunity(d.opportunity_id)?.company_id))} · ${Array.from(d.body).length} / ${d.word_limit}文字</small></span>${badge(d.status,docLabels)}</button>`).join('')}</div>` : '<p class="small-empty">自分の言葉を、ここにストック。ESライブラリから保存できます。</p>'}</section>` +
    (!Object.values(state).some(rows => rows.length) ? '<div class="sample-note"><span>使い方をイメージしたいときは、架空の企業・ESの記入例を追加できます。</span><button class="text-button" data-action="sample">サンプルを試す →</button></div>' : '');
}
function renderOpportunities() {
  const items = sortItems(state.opportunities.filter(o => matches(o.title, companyName(o.company_id), o.notes) && (!statusFilter || o.status === statusFilter) && (!eligibilityFilter || o.eligibility === eligibilityFilter)), 'opportunities');
  return heading('OPPORTUNITIES','出会いを、次のステップへ。','公式情報を2026/10/6に確認。受付状況は確認時点のものです。卒年以外の条件は募集詳細へ。','opportunities','募集を登録') + toolbar(`<select id="eligibility-filter" aria-label="29卒の応募条件で絞り込み"><option value="">すべての対象卒年</option>${Object.entries(eligibilityLabels).map(([v,l])=>`<option value="${v}" ${eligibilityFilter===v?'selected':''}>${l}</option>`).join('')}</select><select id="status-filter" aria-label="選考状況で絞り込み"><option value="">すべてのステータス</option>${Object.entries(statusLabels).map(([v,l]) => `<option value="${v}" ${statusFilter===v?'selected':''}>${l}</option>`).join('')}</select>${sortControl('opportunities')}<div class="segmented"><button data-action="list" class="${mode==='list'?'selected':''}">一覧</button><button data-action="board" class="${mode==='board'?'selected':''}">ボード</button></div>`) +
    (mode === 'board' ? `<p class="hint">カード内の選択欄からステータスを変更できます。</p><div class="board">${Object.entries(statusLabels).map(([key,label]) => `<section class="board-column"><h3>${label}<span>${items.filter(o=>o.status===key).length}</span></h3>${items.filter(o=>o.status===key).map(o=>`<article class="board-card"><div class="company-cell">${companyIcon(company(o.company_id))}<small>${escapeHTML(companyName(o.company_id))}</small></div><button class="card-title" data-action="edit" data-entity="opportunities" data-id="${o.id}">${escapeHTML(o.title)}</button>${recruitmentInfo(o)}${due(o)}<select data-status-id="${o.id}" aria-label="${escapeHTML(o.title)}のステータス">${Object.entries(statusLabels).map(([v,l])=>`<option value="${v}" ${v===key?'selected':''}>${l}</option>`).join('')}</select></article>`).join('')}</section>`).join('')}</div>` : `<section class="panel">${items.length ? opportunityTable(items) : empty('募集が見つかりません','新しく登録するか、検索条件を変えてみてください。','opportunities')}</section>`);
}
function renderCalendar() {
  const start = new Date(month.getFullYear(),month.getMonth(),1), offset = start.getDay();
  const today = localDay(new Date()), events = allEvents();
  const days = Array.from({length:42},(_,i)=>{const date=new Date(month.getFullYear(),month.getMonth(),i-offset+1), key=localDay(date), dayEvents=events.filter(e=>e.date.startsWith(key));return `<div class="calendar-day ${date.getMonth()!==month.getMonth()?'outside':''} ${key===today?'is-today':''} ${selectedDay===key?'is-selected':''}"><button class="day-number" data-action="day" data-day="${key}" aria-label="${key}の予定">${date.getDate()}</button>${dayEvents.slice(0,3).map(e=>`<button class="calendar-event ${e.kind}" data-action="edit" data-entity="${e.entity}" data-id="${e.id}" title="${escapeHTML(e.title)}">${escapeHTML(e.title)}</button>`).join('')}${dayEvents.length>3?`<button class="more-events" data-action="day" data-day="${key}">ほか${dayEvents.length-3}件</button>`:''}</div>`;}).join('');
  const shown = events.filter(e => selectedDay ? e.date.startsWith(selectedDay) : e.date.startsWith(localDay(month).slice(0,7)));
  return heading('CALENDAR','大事な日を、見逃さない。','公表済みの締切・募集開始を表示。日未定の開始予定は月単位で表示します。','events','予定を追加') + monthOpenings() + `<section class="panel calendar-panel"><div class="calendar-toolbar"><h2>${month.getFullYear()}<small>年</small> ${month.getMonth()+1}<small>月</small></h2><div class="legend"><span class="opening">● 募集開始</span><span class="deadline">● 締切</span><span class="briefing">● 説明会</span><span class="interview">● 面接</span><span class="internship">● インターン</span></div><div class="calendar-controls"><button class="button" data-action="today">今月</button><button class="icon-button" data-action="prev-month" aria-label="前の月">‹</button><button class="icon-button" data-action="next-month" aria-label="次の月">›</button></div></div><div class="calendar-scroll"><div class="calendar-grid">${['日','月','火','水','木','金','土'].map(d=>`<div class="weekday">${d}</div>`).join('')}${days}</div></div></section><section class="panel agenda"><div class="section-heading"><h2>${selectedDay ? escapeHTML(selectedDay) : '今月'}の予定</h2>${selectedDay?'<button class="text-button" data-action="clear-day">月全体を表示</button>':''}</div>${shown.length?eventRows(shown):'<p class="small-empty">予定はありません。右上から追加できます。</p>'}</section>`;
}
function documentSource(item) {
  return item.has_source_file && Number.isInteger(Number(item.id)) && Number(item.id)>0 ? `<div class="document-source"><a href="/document-file.php?id=${Number(item.id)}">↓ 原本Word：${escapeHTML(item.source_name || '添付ファイル')}</a><small>取り込み時の原本。フォームの編集内容は原本には反映されません。</small></div>` : '';
}
function renderDocuments() {
  const items=sortItems(state.documents.filter(d=>matches(d.title,d.question,d.body,d.tags,companyName(opportunity(d.opportunity_id)?.company_id))), 'documents');
  return heading('ES LIBRARY','あなたの言葉を、育てよう。','過去のESを検索して、次の応募につなげる。提出済みの文章はそのまま保存。','documents','ESを書く') + toolbar(sortControl('documents')) + tagLegend() + (items.length?`<div class="cards">${items.map(d=>`<article class="panel content-card"><div class="card-top"><span class="document-symbol">▧</span><button type="button" class="progress-open" data-action="edit" data-entity="documents" data-id="${d.id}" aria-label="ESの進捗を編集">${badge(d.status,docLabels)}<small>進捗を編集 ↗</small></button></div><small class="muted">${escapeHTML(opportunity(d.opportunity_id)?companyName(opportunity(d.opportunity_id).company_id):'共通のES')}</small><h2>${escapeHTML(d.title)}</h2><p class="excerpt">${escapeHTML(d.body || '本文を追加して、あなたの経験を言葉にしましょう。')}</p>${documentSource(d)}<div class="tags">${tags(d.tags,true)}</div><div class="card-bottom"><span class="${Array.from(d.body).length>d.word_limit?'over-limit':''}">${Array.from(d.body).length} / ${d.word_limit} 文字</span>${editButton('documents',d.id,d.status==='submitted'?'内容を見る ↗':'編集する ↗')}</div></article>`).join('')}</div>`:empty('ESをストックしよう','ガクチカ、自己PR、志望動機。タグで探しやすく整理できます。','documents'));
}
function renderExperiences() {
  const items=sortItems(state.experiences.filter(e=>matches(e.title,e.period,e.situation,e.action,e.result,e.learning,e.tags)), 'experiences');
  return heading('MY EXPERIENCES','経験のなかに、強みがある。','背景・行動・結果・学びに分けて、ESの素材を整理しよう。','experiences','経験を記録') + toolbar(sortControl('experiences')) + tagLegend() + (items.length?`<div class="cards">${items.map(e=>`<article class="panel content-card"><div class="card-top"><span class="experience-symbol">◇</span><small class="muted">${escapeHTML(e.period)}</small></div><h2>${escapeHTML(e.title)}</h2><p class="excerpt">${escapeHTML(e.learning || e.situation)}</p><div class="tags">${tags(e.tags,true)}</div><div class="card-bottom"><span>${state.documents.filter(d=>Number(d.experience_id)===Number(e.id)).length} 件のESに関連</span>${editButton('experiences',e.id,'振り返る ↗')}</div></article>`).join('')}</div>`:empty('あなたの経験を残そう','開発、学業、アルバイト。日々の気づきから始められます。','experiences'));
}
function renderCompanies() {
  const items=sortItems(state.companies.filter(c=>matches(c.name,c.category,c.industry,c.tags,c.notes)), 'companies');
  return heading('COMPANY NOTES','気になる企業を、深く知る。',`${state.companies.length} 社の企業ノート。公式サイトを確認しながら、自分の視点を残そう。`,'companies','企業を登録') +
    '<p class="company-guide">社名アイコンから公式サイトへ ↗　分類・領域・注目タグは自分用の検討メモです。2026/10/6時点の募集調査を下に表示。未確認は未募集を意味しません。自動更新ではありません。</p>' + toolbar(sortControl('companies')) +
    (items.length?`<div class="cards">${items.map(c=>`<article class="panel content-card"><div class="card-top">${companyIcon(c)}<span class="badge">${escapeHTML(c.category || '分類未設定')}</span></div><h2>${escapeHTML(c.name)}</h2><p class="company-industry">${escapeHTML(c.industry)}</p><div class="tags" aria-label="注目タグ">${tags(c.tags)}</div>${companySources(c)}${companyResearch(c)}<p class="excerpt company-memo">${escapeHTML(c.notes || '企業研究のメモを追加しましょう。')}</p><div class="card-bottom"><span>${state.opportunities.filter(o=>Number(o.company_id)===Number(c.id)).length} 件の募集</span><div class="company-actions">${editButton('companies',c.id,'編集')}${deleteButton('companies',c.id)}</div></div></article>`).join('')}</div>`:empty('企業が見つかりません','新しく企業を登録するか、検索条件を変えてみてください。','companies'));
}
function render() {
  $('#nav').innerHTML=Object.entries(pages).map(([key,label])=>`<a href="#${key}" class="${page===key?'active':''}" ${page===key?'aria-current="page"':''}><span>${icons[key]}</span>${label}${key==='opportunities'?`<small>${state.opportunities.length}</small>`:''}</a>`).join('');
  $('#breadcrumb').textContent=pages[page];
  $('#today').textContent=new Date().toLocaleDateString('ja-JP',{year:'numeric',month:'long',day:'numeric',weekday:'short'});
  app.innerHTML=({dashboard:renderDashboard, opportunities:renderOpportunities, calendar:renderCalendar, documents:renderDocuments, experiences:renderExperiences, companies:renderCompanies})[page]();
}

const field = (name,label,value='',type='text',extra='') => `<label class="field">${label}<input name="${name}" type="${type}" value="${escapeHTML(value)}" ${extra}></label>`;
const area = (name,label,value='',extra='') => `<label class="field">${label}<textarea name="${name}" rows="4" ${extra}>${escapeHTML(value)}</textarea></label>`;
const select = (name,label,value,options,blank=false) => `<label class="field">${label}<select name="${name}">${blank?'<option value="">指定しない</option>':''}${options.map(([v,l])=>`<option value="${v}" ${String(v)===String(value)?'selected':''}>${escapeHTML(l)}</option>`).join('')}</select></label>`;
function documentRelations(value) {
  return select('opportunity_id','応募先の募集',value.opportunity_id,[...state.opportunities.map(o=>[o.id,`${companyName(o.company_id)} / ${o.title}`]),['new','＋ 自由記述で応募先を追加']],true)+
    `<div id="inline-opportunity" class="inline-add" hidden>${field('new_company_name','企業名 *','','text','maxlength="200" list="company-names" disabled')}
    <datalist id="company-names">${state.companies.map(c=>`<option value="${escapeHTML(c.name)}"></option>`).join('')}</datalist>
    ${field('new_opportunity_title','募集名 *','','text','maxlength="200" placeholder="例：企画職インターン／本選考" disabled')}<p class="hint">ESの保存時に応募先を登録します。同名の企業がある場合は、その企業に募集を追加します。</p></div>`+
    select('experience_id','素材にした経験',value.experience_id,[...state.experiences.map(e=>[e.id,e.title]),['new','＋ 自由記述で経験を追加']],true)+
    `<div id="inline-experience" class="inline-add" hidden>${field('new_experience_title','経験のタイトル *','','text','maxlength="200" placeholder="例：アルバイトで接客を改善" disabled')}${area('new_experience_detail','経験の内容（任意）','','disabled')}<p class="hint">ESの保存時に「経験・強み」にも追加します。内容は「背景・課題」に保存され、後から詳しく編集できます。</p></div>`;
}
function updateInlineRelations() {
  for (const [name,selector] of [['opportunity_id','#inline-opportunity'],['experience_id','#inline-experience']]) {
    const input=$(`[name="${name}"]`), box=$(selector);
    if(!input||!box) continue;
    const enabled=input.value==='new';box.hidden=!enabled;
    box.querySelectorAll('input,textarea').forEach(el=>{el.disabled=!enabled;el.required=enabled&&el.tagName==='INPUT';});
  }
}
function documentProgress(value,locked) {
  const current=value.status||'draft', log=value.progress_log||[];
  return `<section class="es-progress" aria-label="ESの進捗"><h3>ESの進捗を更新</h3><p class="hint">段階を選んで「保存する」で反映します。変更日時とメモを履歴に残します。</p>
    <div class="progress-steps">${Object.entries(docLabels).map(([key,label],i)=>`<label class="progress-step"><input type="radio" name="status" value="${key}" ${current===key?'checked':''} ${locked?'disabled':''}><span>${i+1}. ${label}</span></label>`).join('')}</div>
    ${locked?'<input type="hidden" name="status" value="submitted"><p class="hint">提出済みの本文は保持されます。修正は複製して行い、追加の進捗メモはここで保存できます。</p>':''}
    ${area('progress_note','今回の進捗メモ（任意）','','maxlength="2000" placeholder="例：先輩の添削を反映。次は志望動機の結論を確認する"')}
    <details class="progress-history" ${log.length?'open':''}><summary>進捗ログ（${log.length}件）</summary>${log.length?`<ol>${log.map(e=>`<li><time>${escapeHTML(new Date(e.created_at).toLocaleString('ja-JP',{timeZone:'Asia/Tokyo'}))}</time><strong>${e.from_status&&e.from_status!==e.to_status?escapeHTML(docLabels[e.from_status])+' → ':''}${escapeHTML(docLabels[e.to_status]||e.to_status)}</strong>${e.note?`<p>${escapeHTML(e.note)}</p>`:''}</li>`).join('')}</ol>`:'<p class="hint">この画面で進捗を変更するか、メモを保存すると記録されます。</p>'}</details></section>`;
}
function openEditor(entity,id=0,duplicate=false) {
  const item=state[entity].find(row=>Number(row.id)===Number(id))||{};
  const value={...item};
  if(duplicate){value.title=`${value.title}（コピー）`.slice(0,200);value.status='draft';}
  editor={entity,id:duplicate?0:Number(id)};
  const locked=entity==='documents'&&value.status==='submitted';
  const labels={companies:'企業ノート',opportunities:'募集',documents:'ES',experiences:'経験',events:'予定'};
  $('#dialog-title').textContent=`${labels[entity]}${id&&!duplicate?(locked?'の内容':'を編集'):'を登録'}`;
  let html='';
  if(entity==='companies') html=field('name','企業名 *',value.name,'text','required maxlength="200" placeholder="例：株式会社○○"')+field('category','大分類（自分用）',value.category,'text','maxlength="200" placeholder="例：メガベンチャー"')+field('industry','主な領域・業界（自分用）',value.industry,'text','maxlength="200"')+field('tags','注目タグ（カンマ区切り）',value.tags,'text','maxlength="2000" placeholder="企画,マーケ,新規事業"')+field('url','公式サイトURL（アイコンのリンク先）',value.url,'url','placeholder="https://"')+field('secondary_url','関連公式サイトURL（任意・グループ会社など）',value.secondary_url,'url','maxlength="2000" placeholder="https://"')+area('notes','企業研究・気になるポイント',value.notes)+companySources(value);
  if(entity==='opportunities') html=select('company_id','企業 *',value.company_id||(!state.companies.length?'new':''),[...state.companies.map(c=>[c.id,c.name]),['new','＋ 新しい企業を追加']])+`<div id="opportunity-new-company" class="inline-add" hidden>${field('new_company_name','企業名 *','','text','maxlength="200" disabled')}${field('new_company_url','企業の公式URL（任意）','','url','disabled')}</div>`+field('title','募集名 *',value.title,'text','required maxlength="200" placeholder="例：企画職 3daysインターン"')+`<div class="field-pair">${field('deadline','応募締切（日本時間）',value.deadline,value.deadline?.length===10?'date':'datetime-local')}${select('priority','優先度',value.priority||'medium',[['high','高'],['medium','中'],['low','低']])}</div>`+`<label class="hint"><input type="checkbox" id="deadline-date-only" ${value.deadline?.length===10?'checked':''}> 締切時刻は未公表（日付だけ保存）</label>`+field('additional_deadlines','追加の締切（カンマ区切り・日本時間）',value.additional_deadlines,'text','placeholder="2026-11-16T10:00,2026-12-07T10:00"')+field('opens_at','募集開始予定（日・月だけでも可）',value.opens_at,'text','placeholder="2026-11 または 2026-11-01 または 2026-11-01T10:00"')+select('recruitment_state','確認時の募集状況',value.recruitment_state,Object.entries(recruitmentLabels),true)+select('eligibility','29卒の卒年条件',value.eligibility,Object.entries(eligibilityLabels),true)+area('eligibility_notes','応募資格・条件',value.eligibility_notes,'maxlength="2000"')+field('checked_at','公式情報の確認日',value.checked_at,'date')+select('status','選考ステータス',value.status||'interested',Object.entries(statusLabels))+field('url','募集URL',value.url,'url','placeholder="https://"')+area('notes','募集要項・求める人物像・メモ',value.notes)+(id?`<div class="linked-info">関連ES：${state.documents.filter(d=>Number(d.opportunity_id)===Number(id)).length} 件（ESライブラリから編集できます）</div>`:'');
  if(entity==='documents') html=field('title','タイトル *',value.title,'text','required maxlength="200" placeholder="例：学生時代に力を入れたこと"')+documentRelations(value)+'<div id="experience-reference"></div>'+area('question','設問',value.question)+area('body','ES本文',value.body,'class="es-body"')+'<div id="character-count" class="character-count"></div>'+`<div class="field-pair">${field('word_limit','文字数上限 *',value.word_limit||1000,'number','min="1" max="10000" required')}</div>`+field('tags','タグ（カンマ区切り）',value.tags,'text','placeholder="チームワーク,ガクチカ"')+'<p class="hint">文字数の初期上限は1,000文字です。超過しても保存できるため、応募時にご自身で調整してください。文字数は改行・空白を含みます。「提出済み」で保存すると本文の編集をロックします。実際の応募送信は行いません。</p>';
  if(entity==='documents') html=documentSource(value)+html;
  if(entity==='experiences') html=field('title','経験のタイトル *',value.title,'text','required maxlength="200" placeholder="例：夏のハッカソン"')+field('period','時期・期間',value.period,'text','maxlength="200" placeholder="例：大学2年・夏"')+area('situation','背景・課題 — どんな状況だった？',value.situation)+area('action','行動 — 自分が工夫したことは？',value.action)+area('result','結果 — 何が変わった？',value.result)+area('learning','学び・強み — 次に活かせることは？',value.learning)+field('tags','タグ（カンマ区切り）',value.tags,'text','placeholder="課題解決,リーダーシップ,開発"');
  if(entity==='events') html=field('title','予定名 *',value.title,'text','required maxlength="200"')+`<div class="field-pair">${field('starts_at','日時（日本時間） *',value.starts_at||`${selectedDay||localDay(new Date())}T10:00`,'datetime-local','required')}${select('kind','種類',value.kind||'interview',Object.entries(kindLabels).filter(([key])=>!['deadline','opening'].includes(key)))}</div>`+select('opportunity_id','関連する募集',value.opportunity_id,state.opportunities.map(o=>[o.id,`${companyName(o.company_id)} / ${o.title}`]),true)+area('notes','場所・参加URL・準備すること',value.notes);
  if(locked) html=`<p class="locked-note">✓ 提出済みの内容を保存しています。修正したいときは複製して編集できます。</p><fieldset disabled>${html}</fieldset><button type="button" class="button" data-action="duplicate" data-id="${id}">複製して編集</button>`;
  if(['companies','opportunities'].includes(entity)&&typeof lookupMarkup==='function')html=lookupMarkup(entity,value)+html;
  if(entity==='documents') html=documentProgress(duplicate?{...value,progress_log:[]}:value,locked)+html;
  if(value.url && /^https?:\/\//.test(value.url)) html+=`<a class="external-link" href="${escapeHTML(value.url)}" target="_blank" rel="noopener noreferrer">登録したサイトを開く ↗</a>`;
  if(id&&!duplicate) html+=`<div class="editor-delete">${deleteButton(entity,id)}</div>`;
  $('#editor-fields').innerHTML=html;
  $('#form-error').textContent='';
  $('#save-button').hidden=false;
  $('#save-button').disabled=false;
  $('#editor').showModal();
  $('#editor').scrollTop=0;
  updateCount(); updateReference(); updateInlineRelations();
  if(typeof setupLookup==='function')setupLookup();
}
function updateCount(){const body=$('[name="body"]'),counter=$('#character-count');if(body&&counter){const count=Array.from(body.value).length,limit=Number($('[name="word_limit"]').value);counter.textContent=`${count} / ${limit}文字${count>limit?' — 上限を超えています':''}`;counter.classList.toggle('over-limit',count>limit);}}
function updateReference(){const el=$('#experience-reference'),input=$('[name="experience_id"]');if(!el||!input)return;const e=state.experiences.find(e=>Number(e.id)===Number(input.value));el.innerHTML=e?`<details class="reference"><summary>素材の経験を確認する：${escapeHTML(e.title)}</summary>${[['背景・課題',e.situation],['行動',e.action],['結果',e.result],['学び',e.learning]].map(([l,v])=>`<strong>${l}</strong><p>${escapeHTML(v)}</p>`).join('')}</details>`:'';}
$('#editor-form').addEventListener('submit',async event=>{event.preventDefault();const button=$('#save-button');if(button.disabled)return;button.disabled=true;try{await request({action:'save',...editor,values:Object.fromEntries(new FormData(event.target))});$('#editor').close();render();toast('保存しました');}catch(error){$('#form-error').textContent=error.message;}finally{button.disabled=false;}});
document.addEventListener('input',event=>{if(event.target.id==='search'){query=event.target.value;const position=event.target.selectionStart;render();$('#search').focus();try{$('#search').setSelectionRange(position,position);}catch{}}if(['body','word_limit'].includes(event.target.name))updateCount();});
document.addEventListener('error',event=>{if(event.target.classList?.contains('company-favicon'))event.target.hidden=true;},true);
document.addEventListener('change',async event=>{if(event.target.id==='eligibility-filter'){eligibilityFilter=event.target.value;render();}if(event.target.id==='sort-order'){sortOrders[page]=event.target.value;render();}if(event.target.id==='deadline-date-only'){const input=$('[name="deadline"]'),value=input.value;input.type=event.target.checked?'date':'datetime-local';input.value=event.target.checked?value.slice(0,10):value.length===10?value+'T00:00':value;}if(event.target.id==='status-filter'){statusFilter=event.target.value;render();}if(['opportunity_id','experience_id'].includes(event.target.name))updateInlineRelations();if(event.target.name==='experience_id')updateReference();if(event.target.dataset.statusId){const item=opportunity(event.target.dataset.statusId);event.target.disabled=true;try{await request({action:'save',entity:'opportunities',id:item.id,values:{...item,status:event.target.value}});toast('ステータスを更新しました');}catch(error){toast(error.message);}render();}});
document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-action]');if(!button)return;
  const {action,entity,id,day}=button.dataset;
  if(action==='new')openEditor(entity);
  if(action==='edit')openEditor(entity,id);
  if(action==='close')$('#editor').close();
  if(action==='duplicate')openEditor('documents',id,true);
  if(action==='delete'){pendingDelete={entity,id:Number(id)};$('#confirm-description').textContent=entity==='opportunities'?'募集を削除します。関連するES・予定は残りますが、この募集とのひもづけは解除されます。':'この操作は取り消せません。削除前に必要な内容を書き出してください。';$('#confirm-dialog').showModal();}
  if(['list','board'].includes(action)){mode=action;render();}
  if(['prev-month','next-month','today'].includes(action)){month=action==='today'?new Date(new Date().getFullYear(),new Date().getMonth(),1):new Date(month.getFullYear(),month.getMonth()+(action==='next-month'?1:-1),1);selectedDay='';render();}
  if(action==='day'){selectedDay=day;render();}
  if(action==='clear-day'){selectedDay='';render();}
  if(action==='sample'){button.disabled=true;try{await request({action:'sample'});render();toast('架空のサンプルデータを追加しました');}catch(error){toast(error.message);button.disabled=false;}}
  if(action==='export'){const blob=new Blob([JSON.stringify({version:1,exported_at:new Date().toISOString(),data:state},null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=`shiori-${localDay(new Date())}.json`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);toast('データを書き出しました');}
});
$('#cancel-delete').onclick=()=>$('#confirm-dialog').close();
$('#confirm-delete').onclick=async()=>{const button=$('#confirm-delete');button.disabled=true;try{await request({action:'delete',...pendingDelete});$('#confirm-dialog').close();$('#editor').close();render();toast('削除しました');}catch(error){$('#confirm-dialog').close();toast(error.message);}finally{button.disabled=false;}};
function navigate(){page=Object.hasOwn(pages,location.hash.slice(1))?location.hash.slice(1):'dashboard';query='';statusFilter='';eligibilityFilter='';render();}
window.addEventListener('hashchange',navigate);
request().then(navigate).catch(error=>{app.innerHTML=`<div class="empty"><h1>読み込めませんでした</h1><p>${escapeHTML(error.message)}</p><a class="button" href="/">再読み込み</a></div>`;});
