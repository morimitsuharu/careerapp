<?php
declare(strict_types=1);

function publicWebUrl(string $url): array
{
    $p=parse_url($url);
    if(!$p || ($p['scheme']??'')!=='https' || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port']!==443) || !preg_match('/\A[a-z0-9.-]+\z/i',$p['host']??'') || strlen($url)>2000) throw new InvalidArgumentException('認証情報を含まない公開HTTPSページのURLを入力してください。');
    $host=strtolower($p['host']);
    if(!str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP) || preg_match('/\.(local|localhost|internal|test)$/',$host)) throw new InvalidArgumentException('公開サイトのURLを指定してください。');
    return $p;
}
function webResolve(string $base,string $href): string
{
    $href=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if($href===''||$href[0]==='#') return '';
    if(str_starts_with($href,'//')) return 'https:'.$href;
    if(preg_match('/^[a-z][a-z0-9+.-]*:/i',$href)) return $href;
    $p=parse_url($base);$origin='https://'.($p['host']??'');
    if($href[0]==='?') return $origin.($p['path']??'/').$href;
    $path=$href[0]==='/'?$href:preg_replace('~/[^/]*$~','/',($p['path']??'/')).$href;
    $parts=[];foreach(explode('/',$path) as $part){if($part==='..')array_pop($parts);elseif($part!=='.')$parts[]=$part;}
    return $origin.implode('/',$parts);
}
function fetchPublicWeb(string $url): array
{
    if(!function_exists('curl_init')) throw new RuntimeException('ページ取得に必要なPHP cURLが利用できません。本文貼り付けをご利用ください。');
    for($hop=0;$hop<4;$hop++){
        $p=publicWebUrl($url);$host=strtolower($p['host']);
        $ips=array_column(dns_get_record($host,DNS_A)?:[],'ip');
        if(!$ips) throw new RuntimeException('サイトの接続先を確認できませんでした。');
        foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('ローカル・非公開ネットワークには接続できません。');
        $body='';$headers=[];$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>["$host:443:".$ips[0]],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'ShioriCareerApp/1.0 (personal career research; https://github.com/morimitsuharu/careerapp)',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body,&$tooLarge){if(strlen($body)+strlen($chunk)>2000000){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}
        ]);
        $ok=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($tooLarge)throw new RuntimeException('ページが大きすぎるため取得できません。必要な本文を貼り付けてください。');
        if($ok===false)throw new RuntimeException('ページに接続できませんでした。URLの確認、または本文貼り付けをご利用ください。');
        if(in_array($code,[301,302,303,307,308],true)&&isset($headers['location'])){$url=webResolve($url,$headers['location']);continue;}
        if($code<200||$code>=300)throw new RuntimeException("ページを取得できませんでした（HTTP $code）。会員限定ページ等は本文を貼り付けてください。");
        return ['url'=>$url,'body'=>$body,'content_type'=>$headers['content-type']??''];
    }
    throw new RuntimeException('転送回数が多いため取得を中止しました。最終ページのURLを指定してください。');
}
function wikiJson(array $params): array
{
    $r=fetchPublicWeb('https://www.wikidata.org/w/api.php?'.http_build_query($params+['format'=>'json']));
    return json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
}
function searchCompanies(string $query): array
{
    $items=[];$needle=mb_strtolower(mb_convert_kana($query,'as'));
    foreach(snapshot()['companies'] as $c){
        if(mb_stripos(mb_convert_kana($c['name'],'as'),$needle)!==false)$items[]=['kind'=>'saved','id'=>(string)$c['id'],'name'=>$c['name'],'description'=>$c['industry'],'source'=>'登録済みの企業','url'=>$c['url']];
    }
    $warning='';
    try{
        $r=wikiJson(['action'=>'wbsearchentities','search'=>$query,'language'=>'ja','uselang'=>'ja','limit'=>15,'type'=>'item']);
        foreach($r['search']??[] as $item){
            $description=$item['description']??'';
            // Search may include products and people; keep organization-like descriptions.
            if(!preg_match('/企業|会社|法人|銀行|信用金庫|保険|メーカー|商社|organization|company|corporation|business|bank/i',$description))continue;
            $items[]=['kind'=>'wikidata','id'=>$item['id'],'name'=>$item['label'],'description'=>$description,'source'=>'Wikidataの企業候補（同名企業を確認）','url'=>'https://www.wikidata.org/wiki/'.$item['id']];
        }
    }catch(Throwable $e){$warning='外部候補を取得できませんでした。登録済み候補、または公式URLから入力できます。';}
    return ['items'=>array_slice($items,0,15),'warning'=>$warning];
}
function companyCandidate(string $id): array
{
    if(!preg_match('/\AQ[1-9][0-9]*\z/',$id))throw new InvalidArgumentException('企業候補を選び直してください。');
    $r=wikiJson(['action'=>'wbgetentities','ids'=>$id,'props'=>'labels|descriptions|claims','languages'=>'ja|en']);$e=$r['entities'][$id]??[];
    $name=$e['labels']['ja']['value']??$e['labels']['en']['value']??'';
    $description=$e['descriptions']['ja']['value']??$e['descriptions']['en']['value']??'';
    $urls=[];
    foreach($e['claims']['P856']??[] as $claim){
        if(($claim['rank']??'')==='deprecated')continue;
        $url=$claim['mainsnak']['datavalue']['value']??null;
        if(is_string($url)){try{publicWebUrl($url);$urls[]=$url;}catch(Throwable $e){}}
    }
    return ['fields'=>['name'=>mb_substr($name,0,200),'url'=>$urls[0]??'','notes'=>$description."\n候補情報の出典：https://www.wikidata.org/wiki/$id\n公式サイト・同名企業を確認してください。"],'urls'=>array_values(array_unique($urls)),'source_url'=>'https://www.wikidata.org/wiki/'.$id];
}
function parseWebDocument(string $html,string $url): array
{
    $encoding=mb_detect_encoding($html,['UTF-8','SJIS-win','EUC-JP','ISO-2022-JP'],true)?:'UTF-8';
    $html=mb_convert_encoding($html,'UTF-8',$encoding);
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($old);
    $xp=new DOMXPath($doc);$h1=$xp->query('//h1')->item(0);
    $title=trim($h1?->textContent??$xp->query('//title')->item(0)?->textContent??'');
    $description='';foreach($xp->query('//meta[@content]') as $node){if(in_array(strtolower($node->getAttribute('name')?:$node->getAttribute('property')),['description','og:description'],true)){$description=trim($node->getAttribute('content'));break;}}
    $jobs=[];$walk=function($value)use(&$walk,&$jobs){if(!is_array($value))return;if(in_array('JobPosting',(array)($value['@type']??[]),true))$jobs[]=$value;foreach($value as $child)if(is_array($child))$walk($child);};
    foreach($xp->query('//script[@type="application/ld+json"]') as $node)$walk(json_decode($node->textContent,true));
    $links=[];
    foreach($xp->query('//a[@href]') as $a){$label=trim(preg_replace('/\s+/u',' ',$a->textContent));$href=webResolve($url,$a->getAttribute('href'));if($label===''||!preg_match('/インターン|募集|新卒|採用|intern|recruit|career/i',$label.' '.$href))continue;try{publicWebUrl($href);}catch(Throwable $e){continue;}$links[$href]=['name'=>mb_substr($label,0,180),'url'=>$href];}
    foreach(iterator_to_array($xp->query('//script|//style|//nav|//footer|//noscript|//aside')) as $node)$node->parentNode?->removeChild($node);
    $main=$xp->query('//main')->item(0)??$xp->query('//article')->item(0)??$xp->query('//body')->item(0);
    $markup=$main?$doc->saveHTML($main):'';
    $text=html_entity_decode(strip_tags(preg_replace('~</(?:p|div|li|tr|h[1-6]|section|dt|dd)>|<br\s*/?>~i',"\n",$markup)),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('/[\t \x{00a0}　]+/u',' ',$text);$text=preg_replace('/\n\s*\n/u',"\n",$text);
    return ['title'=>mb_substr(preg_replace('/\s+/u',' ',$title),0,200),'description'=>$description,'text'=>trim($text),'jobs'=>$jobs,'links'=>array_slice(array_values($links),0,35)];
}
function labelledDates(string $text,string $label): array
{
    $lines=array_values(array_filter(array_map('trim',explode("\n",$text)),fn($s)=>$s!==''));$found=[];
    foreach($lines as $i=>$line){
        if(!preg_match($label,$line)||mb_strlen($line)>450)continue;
        if(preg_match('/開始/u',$line)&&preg_match('/締切|締め切り|応募期限/u',$line))continue;
        $snippet=$line;
        if(!preg_match('/20\d{2}\s*[年\/-]/u',$snippet)&&isset($lines[$i+1]))$snippet.=' '.$lines[$i+1];
        preg_match_all('/(20\d{2})\s*[年\/-]\s*(\d{1,2})\s*[月\/-]\s*(\d{1,2})\s*日?(?:\s*[（(][^）)]{1,5}[）)])?(?:\s*(\d{1,2})[:：](\d{2}))?/u',$snippet,$matches,PREG_SET_ORDER);
        foreach($matches as $m){$date=sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);if(isset($m[4])&&$m[4]!=='')$date.=sprintf('T%02d:%02d',(int)$m[4],(int)$m[5]);elseif(preg_match('/正午/u',$snippet))$date.='T12:00';if(validResearchDate($date))$found[$date]=mb_substr($snippet,0,450);}
    }
    ksort($found);return $found;
}
function webPreview(array $page,string $entity,string $url,bool $pasted=false): array
{
    $fields=['url'=>$url];$evidence=['url'=>'参照したページURL'];$warnings=[];
    $text=$page['text'];$title=$page['title'];$description=$page['description'];
    if($entity==='companies'){
        // A page title may include a slogan, so keep it explicitly reviewable.
        if($title!==''){$fields['name']=$title;$evidence['name']='ページの見出し（正式社名は確認してください）';}
    }else{
        if($title!==''){$fields['title']=$title;$evidence['title']='ページの見出し';}
        $fields['checked_at']=date('Y-m-d');$evidence['checked_at']=$pasted?'貼り付け本文の取り込み日（現在のHPは未取得）':'公開ページの取得日';
        $deadlines=labelledDates($text,'/締切|締め切り|応募期限/u');
        $openings=labelledDates($text,'/(?:募集|応募|エントリー|受付).{0,8}開始/u');
        $future=array_filter($deadlines,fn($v,$k)=>new DateTimeImmutable(strlen($k)===10?$k.'T23:59:59':$k)>=new DateTimeImmutable(),ARRAY_FILTER_USE_BOTH);
        $chosen=array_key_first($future?:$deadlines);
        if($chosen){$fields['deadline']=$chosen;$evidence['deadline']=$deadlines[$chosen];$rest=array_diff(array_keys($future),[$chosen]);if($rest){$fields['additional_deadlines']=implode(',',$rest);$evidence['additional_deadlines']='同じページに掲載された追加締切。別コースの日程が混在していないか確認してください。';}}
        if(count($openings)===1){$fields['opens_at']=array_key_first($openings);$evidence['opens_at']=reset($openings);}elseif(count($openings)>1)$warnings[]='募集開始日が複数あるため、自動選択していません。';
        if(count($page['jobs'])===1){
            $job=$page['jobs'][0];
            if(isset($job['title'])&&is_string($job['title'])){$fields['title']=mb_substr($job['title'],0,200);$evidence['title']='ページのJobPosting.title';}
            // datePosted is a publication date, not an application opening date.
            $until=$job['validThrough']??'';
            if(is_string($until)&&$until!=='')$warnings[]='求人データの掲載有効期限：'.mb_substr($until,0,60).'。応募締切と同一とは限らないため、この値からは締切を補完しません。';
            if(!$description&&isset($job['description'])&&is_string($job['description']))$description=html_entity_decode(strip_tags($job['description']),ENT_QUOTES|ENT_HTML5,'UTF-8');
        }
        $states=[];
        foreach(explode("\n",$text) as $line){if(mb_strlen($line)>150)continue;if(preg_match('/(?:募集|応募|受付|エントリー)(?:を|は|の)?(?:終了|締め切りました)|募集終了|受付終了/u',$line))$states['closed']=trim($line);if(preg_match('/募集中|応募受付中|エントリー受付中|応募を受け付けて|ご応募お待ち/u',$line))$states['open']=trim($line);}
        if(count($states)===1){$key=array_key_first($states);$fields['recruitment_state']=$key;$evidence['recruitment_state']=$states[$key];}else{$fields['recruitment_state']='unknown';$evidence['recruitment_state']=count($states)>1?'受付中と終了の表記が混在':'明確な受付表記を確認できず';}
        if(isset($fields['deadline'])&&new DateTimeImmutable(strlen($fields['deadline'])===10?$fields['deadline'].'T23:59:59':$fields['deadline'])<new DateTimeImmutable()){$fields['recruitment_state']='closed';$evidence['recruitment_state']='抽出した締切日が過去です。別年度・別コースではないか確認してください。';}
        $conditions=[];foreach(explode("\n",$text) as $line)if(preg_match('/応募資格|募集対象|応募条件|募集条件|学年不問|全学年|20\d{2}年.{0,15}(?:卒|入社)|(?:27|28|29|30)卒/u',$line))$conditions[]=trim($line);
        $fields['eligibility']='check';$evidence['eligibility']='卒年・その他の応募条件は手動確認';
        if($conditions){$fields['eligibility_notes']=mb_substr(implode("\n",array_slice($conditions,0,8)),0,2000);$evidence['eligibility_notes']='対象条件に言及した本文の抜粋（前後の文脈を確認）';}
        if(!$chosen&&!isset($fields['deadline']))$warnings[]='年を含む明確な締切を抽出できませんでした。推測の日付は設定しません。';
        if(count($deadlines)>1)$warnings[]='締切が複数あります。別募集・別コースの日程が混ざっていないか確認してください。';
    }
    $classificationText=$description!==''?$description:$title;
    $labels=[];
    foreach(['SaaS'=>'/SaaS/i','FinTech'=>'/FinTech|金融テクノロジー/iu','ゲーム'=>'/ゲーム/u','広告'=>'/広告/u','通信'=>'/通信/u','人材'=>'/人材|HR Tech/iu','EC'=>'/EC事業|eコマース|電子商取引/iu','データ分析'=>'/データ分析/u','マーケティング'=>'/マーケティング/u'] as $label=>$pattern) if(preg_match($pattern,$classificationText))$labels[]=$label;
    if($labels){$fields['tags']=implode(',',$labels);$evidence['tags']='ページの説明文にある語句から作成した候補';if($entity==='companies'){$fields['industry']=implode(' / ',$labels);$evidence['industry']='説明文の語句からの分類候補（公式の業種分類ではありません）';}}
    $summary=trim(preg_replace('/\s+/u',' ',$description));
    if($summary==='')$summary=mb_substr(trim(preg_replace('/\s+/u',' ',$text)),0,450);
    if($summary!==''){$fields['notes']=($pasted?'貼り付け本文からの概要':'ページから取得した概要')."\n".mb_substr($summary,0,600)."\n\n出典：$url\n取得日：".date('Y-m-d')."\n自動抽出した仮入力です。対象年度・募集条件を確認してください。";$evidence['notes']='ページの説明文・本文から作成した短い概要';}
    if($pasted)$warnings[]='貼り付け本文を解析しました。HPの現在の募集状況を直接確認した結果ではありません。';
    return ['fields'=>$fields,'evidence'=>$evidence,'warnings'=>$warnings,'links'=>$page['links'],'source_url'=>$url];
}
