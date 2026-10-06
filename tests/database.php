<?php
declare(strict_types=1);
// Writes are rolled back so this check leaves no records behind.
require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = db();
$pdo->beginTransaction();
try {
    $company = insertRecord('companies', validate('companies', ['name' => '接続確認用企業', 'industry' => 'IT', 'url' => '', 'notes' => 'テスト']));
    $opportunity = insertRecord('opportunities', validate('opportunities', ['company_id' => $company, 'title' => '接続確認用募集', 'deadline' => '2026-12-01T23:59', 'status' => 'writing', 'priority' => 'high', 'url' => '', 'notes' => 'テスト']));
    $document = insertRecord('documents', validate('documents', ['opportunity_id' => $opportunity, 'title' => '接続確認用ES', 'question' => '自己PR', 'body' => '日本語の保存確認です。', 'word_limit' => 400, 'tags' => 'テスト', 'status' => 'draft']));
    $pdo->prepare('DELETE FROM opportunities WHERE id = ?')->execute([$opportunity]);
    $statement = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
    $statement->execute([$document]);
    $saved = $statement->fetch();
    if ($saved['body'] !== '日本語の保存確認です。' || $saved['opportunity_id'] !== null) throw new RuntimeException('Data or relationship check failed');
    echo 'PASS: ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . " tables, Japanese text, validation, foreign keys (rolled back)\n";
} finally {
    $pdo->rollBack();
}
