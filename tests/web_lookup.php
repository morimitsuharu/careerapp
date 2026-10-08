<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');
require dirname(__DIR__).'/src/migrations.php';
require dirname(__DIR__).'/src/web_lookup.php';
function ensure(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
foreach(['http://example.com','https://127.0.0.1/','https://[::1]/','https://localhost/','https://db.internal/','https://a:b@example.com/','file:///etc/passwd','https://example.com:8000'] as $url){try{publicWebUrl($url);throw new RuntimeException('Unsafe URL accepted: '.$url);}catch(InvalidArgumentException $e){}}
ensure(webResolve('https://example.com/a/b','../recruit')==='https://example.com/recruit','Relative URL');
$html='<html><head><title>企業採用</title><meta name="description" content="ゲーム事業の企画を体験するインターンです。"></head><body><nav>受付終了</nav><main><h1>新規事業インターン</h1><p>募集中</p><p>応募資格：学年不問</p><h2>エントリー締切</h2><p>2099年10月26日（月）10:00</p><p>2次締切：2099年11月16日（月）10:00</p><p>募集開始：2099年9月1日</p><a href="/recruit/intern">インターン一覧</a></main></body></html>';
$page=parseWebDocument($html,'https://example.com/');$r=webPreview($page,'opportunities','https://example.com/');
ensure($r['fields']['deadline']==='2099-10-26T10:00','Explicit deadline');
ensure($r['fields']['additional_deadlines']==='2099-11-16T10:00','Multiple rounds');
ensure($r['fields']['opens_at']==='2099-09-01','Opening must stay date-only');
ensure($r['fields']['recruitment_state']==='open','Navigation must not change status');
ensure($r['fields']['eligibility']==='check','Do not infer full eligibility');
ensure($page['links'][0]['url']==='https://example.com/recruit/intern','Recruiting link');
$unknown=parseWebDocument('<main><h1>インターン</h1><p>開催日：2099年12月1日</p><p>締切：10月26日</p><p>募集開始は11月頃予定</p></main>','https://example.com/');
$r=webPreview($unknown,'opportunities','https://example.com/');ensure(!isset($r['fields']['deadline'])&&!isset($r['fields']['opens_at']),'Must not invent years or use event date');
$unknown['text']="受付終了\n募集中";$r=webPreview($unknown,'opportunities','https://example.com/');ensure($r['fields']['recruitment_state']==='unknown','Conflicting statuses');
$unknown['text']="応募締切：2020年1月1日\n募集中";$r=webPreview($unknown,'opportunities','https://example.com/');ensure($r['fields']['recruitment_state']==='closed','Expired deadline');
$unknown['text']='';$unknown['jobs']=[['@type'=>'JobPosting','title'=>'開発職','datePosted'=>'2099-10-01','validThrough'=>'2099-10-31']];$r=webPreview($unknown,'opportunities','https://example.com/');ensure(!isset($r['fields']['deadline'])&&!isset($r['fields']['opens_at']),'Publication dates are not application dates');
ensure(labelledDates("応募締切：2099年2月30日",'/締切/u')===[],'Invalid day');
ensure(labelledDates("募集開始：2099年1月1日 応募締切：2099年2月1日",'/締切/u')===[],'Ambiguous dates on one line');
$r=webPreview($unknown,'opportunities','https://example.com/',true);ensure(str_contains(implode(' ',$r['warnings']),'貼り付け'),'Pasted text provenance');
echo "PASS: public URL boundaries, HTML extraction, deadlines/rounds, no invented dates, ambiguous states, source evidence\n";
