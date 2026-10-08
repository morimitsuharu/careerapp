<?php
// Explicit, read-only network check; not part of the offline test suite.
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/web_lookup.php';
$search=searchCompanies('任天堂');
if(!array_filter($search['items'],fn($c)=>$c['kind']==='wikidata'&&$c['id']==='Q8093'))throw new RuntimeException('Live company search unavailable: '.$search['warning']);
$c=companyCandidate('Q8093');
if(!$c['fields']['url'])throw new RuntimeException('No official URL candidate');
$url='https://www.cyberagent.co.jp/careers/students/event/detail/id=33828';
$page=fetchPublicWeb($url);$parsed=parseWebDocument($page['body'],$page['url']);$preview=webPreview($parsed,'opportunities',$page['url']);
if(!isset($preview['fields']['deadline']))throw new RuntimeException('Could not extract actual CA deadline');
echo json_encode(['company_candidates'=>count($search['items']),'selected_company'=>$c['fields']['name'],'homepage_candidate'=>$c['fields']['url'],'preview_fields'=>array_keys($preview['fields']),'deadline'=>$preview['fields']['deadline'],'additional_deadlines'=>$preview['fields']['additional_deadlines']??'','source'=>$preview['source_url']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
