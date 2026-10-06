<?php
declare(strict_types=1);

function saveDocument(int $id, array $values): void
{
    $pdo=db();
    $pdo->beginTransaction();
    try {
        $existing=null;
        if($id){
            $q=$pdo->prepare('SELECT * FROM documents WHERE id=?');$q->execute([$id]);$existing=$q->fetch();
            if(!$existing) throw new InvalidArgumentException('ESが見つかりません。');
        }
        foreach(['new_company_name','new_opportunity_title','new_experience_title','new_experience_detail'] as $key) {
            if(isset($values[$key]) && !is_string($values[$key])) throw new InvalidArgumentException('追加項目は文章で入力してください。');
        }
        $note=$values['progress_note']??'';
        if(!is_string($note)||mb_strlen($note)>2000) throw new InvalidArgumentException('進捗メモは2000文字以内で入力してください。');
        $note=trim($note);
        if($existing && $existing['status']==='submitted'){
            foreach(FIELDS['documents'] as $field){
                if(array_key_exists($field,$values) && (string)$values[$field] !== (string)$existing[$field]) throw new InvalidArgumentException('提出済みの内容は保存されています。修正する場合は「複製して編集」を使ってください。');
            }
            if(!empty($values['new_opportunity_title'])||!empty($values['new_experience_title'])) throw new InvalidArgumentException('提出済みの関連項目は変更できません。');
            $data=$existing;
        }else{
            // Related records and the ES are committed together; failed saves leave no orphan records.
            if(($values['opportunity_id']??'')==='new'){
                $company=trim((string)($values['new_company_name']??''));
                if($company===''||mb_strlen($company)>200) throw new InvalidArgumentException('新しい応募先の企業名を1〜200文字で入力してください。');
                $q=$pdo->prepare('SELECT id FROM companies WHERE name=? ORDER BY id');$q->execute([$company]);$cid=$q->fetchColumn();
                if(!$cid) $cid=insertRecord('companies',validate('companies',['name'=>$company]));
                $values['opportunity_id']=insertRecord('opportunities',validate('opportunities',[
                    'company_id'=>$cid,'title'=>$values['new_opportunity_title']??'', 'status'=>'interested','priority'=>'medium'
                ]));
            }
            if(($values['experience_id']??'')==='new'){
                $values['experience_id']=insertRecord('experiences',validate('experiences',[
                    'title'=>$values['new_experience_title']??'', 'situation'=>$values['new_experience_detail']??''
                ]));
            }
            $data=validate('documents',$values);
            if($id){
                $sets=implode(',',array_map(fn($key)=>"$key = ?",array_keys($data)));
                $pdo->prepare("UPDATE documents SET $sets WHERE id = ?")->execute([...array_values($data),$id]);
            }else $id=insertRecord('documents',$data);
        }
        if(!$existing || $existing['status']!==$data['status'] || $note!==''){
            $pdo->prepare('INSERT INTO document_progress (document_id,from_status,to_status,note,created_at) VALUES (?,?,?,?,?)')->execute([$id,$existing['status']??'',$data['status'],$note,date('c')]);
        }
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
