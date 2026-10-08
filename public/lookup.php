<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/web_lookup.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new InvalidArgumentException('POSTで送信してください。');}
    if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){http_response_code(403);throw new InvalidArgumentException('ページを再読み込みしてください。');}
    $input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new InvalidArgumentException('入力形式が正しくありません。');
    foreach(['action','query','id','url','entity','text'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new InvalidArgumentException('入力は文字列で指定してください。');
    session_write_close();
    $action=$input['action']??'';
    if($action==='companies'){
        $query=trim($input['query']??'');
        if(mb_strlen($query)<2||mb_strlen($query)>100)throw new InvalidArgumentException('企業名は2〜100文字で検索してください。');
        $result=searchCompanies($query);
    }elseif($action==='company'){
        $result=companyCandidate($input['id']??'');
    }elseif(in_array($action,['preview','discover'],true)){
        $url=trim($input['url']??'');publicWebUrl($url);
        $entity=$input['entity']??'opportunities';
        if(!in_array($entity,['companies','opportunities'],true))throw new InvalidArgumentException('対応しない項目です。');
        $text=trim($input['text']??'');
        if(mb_strlen($text)>60000)throw new InvalidArgumentException('貼り付け本文は60,000文字以内にしてください。');
        if($text!=='' && $action==='preview'){
            $page=['title'=>'','description'=>'','text'=>$text,'jobs'=>[],'links'=>[]];
        }else{
            $response=fetchPublicWeb($url);$url=$response['url'];
            if(!preg_match('/html|text\/plain/i',$response['content_type']))throw new RuntimeException('このページ形式は読み取れません。募集本文を貼り付けてください。');
            $page=parseWebDocument($response['body'],$url);
        }
        if($action==='discover'){
            $links=$page['links'];$query=mb_strtolower(trim($input['query']??''));
            if($query!=='')usort($links,fn($a,$b)=>(int)(mb_stripos($b['name'],$query)!==false)<=>(int)(mb_stripos($a['name'],$query)!==false));
            $result=['items'=>$links,'source_url'=>$url,'warning'=>$links?'公式サイトのリンク候補です。目的の募集・年度を確認して選択してください。':'募集リンクを見つけられませんでした。採用ページのURLを直接指定してください。'];
        }else $result=webPreview($page,$entity,$url,$text!=='');
    }else throw new InvalidArgumentException('操作が正しくありません。');
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException|JsonException $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(502);error_log((string)$e);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'読み取りに失敗しました。公式URLまたは本文貼り付けをお試しください。'],JSON_UNESCAPED_UNICODE);}
