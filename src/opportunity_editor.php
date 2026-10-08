<?php
declare(strict_types=1);
function saveOpportunity(int $id,array $values): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        if(($values['company_id']??'')==='new'){
            foreach(['new_company_name','new_company_url'] as $key)if(isset($values[$key])&&!is_string($values[$key]))throw new InvalidArgumentException('企業名・URLの入力形式が正しくありません。');
            $name=trim($values['new_company_name']??'');
            $company=validate('companies',['name'=>$name,'url'=>$values['new_company_url']??'']);
            $q=$pdo->prepare('SELECT id FROM companies WHERE name=? ORDER BY id');$q->execute([$name]);$cid=$q->fetchColumn();
            $values['company_id']=$cid?:insertRecord('companies',$company);
        }
        $data=validate('opportunities',$values);
        if($id){$sets=implode(',',array_map(fn($key)=>"$key = ?",array_keys($data)));$pdo->prepare("UPDATE opportunities SET $sets WHERE id=?")->execute([...array_values($data),$id]);}
        else insertRecord('opportunities',$data);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
