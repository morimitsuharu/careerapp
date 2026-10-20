<?php
declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=:memory:');
putenv('APP_SKIP_INITIAL_COMPANIES=1');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/document_editor.php';

$company = insertRecord('companies', validate('companies', ['name' => '確認用企業']));
$camp = insertRecord('opportunities', validate('opportunities', ['company_id' => $company, 'title' => 'Camp', 'status' => 'applied', 'priority' => 'medium']));
$longTerm = insertRecord('opportunities', validate('opportunities', ['company_id' => $company, 'title' => '長期インターン', 'status' => 'applied', 'priority' => 'medium']));
$experience = insertRecord('experiences', validate('experiences', ['title' => 'チーム開発']));
$id = insertRecord('documents', validate('documents', [
    'opportunity_id' => $camp,
    'experience_id' => $experience,
    'title' => '提出済みES',
    'question' => '元の設問',
    'body' => '提出した本文',
    'word_limit' => 1000,
    'tags' => '企画',
    'status' => 'submitted',
]));

saveDocument($id, ['opportunity_id' => (string)$longTerm, 'tags' => '企画,チーム開発', 'status' => 'submitted']);
$row = db()->query('SELECT * FROM documents WHERE id = ' . $id)->fetch();
if ($row['opportunity_id'] != $longTerm || $row['tags'] !== '企画,チーム開発') throw new RuntimeException('応募先・タグの変更が保存されませんでした。');
if ($row['title'] !== '提出済みES' || $row['question'] !== '元の設問' || $row['body'] !== '提出した本文' || $row['experience_id'] != $experience || $row['status'] !== 'submitted') {
    throw new RuntimeException('提出済みの内容が変更されました。');
}

foreach ([['body' => '改変した本文'], ['opportunity_id' => '9999']] as $invalid) {
    try {
        saveDocument($id, $invalid);
        throw new RuntimeException('不正な変更が受け入れられました。');
    } catch (InvalidArgumentException) {
        // Submitted text and missing application records must stay protected.
    }
}
$row = db()->query('SELECT body, opportunity_id FROM documents WHERE id = ' . $id)->fetch();
if ($row['body'] !== '提出した本文' || $row['opportunity_id'] != $longTerm) throw new RuntimeException('失敗した保存でデータが変わりました。');
echo "PASS: 提出済みESの応募先・タグのみ更新し、本文と設問を保持\n";
