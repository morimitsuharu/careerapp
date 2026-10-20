<?php
declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=:memory:');
putenv('APP_SKIP_INITIAL_COMPANIES=1');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/opportunity_editor.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$companyId = insertRecord('companies', validate('companies', ['name' => 'テスト企業']));
$values = [
    'company_id' => (string)$companyId,
    'title' => '複数回募集のインターン',
    'deadline' => '2026-10-14T10:00',
    'additional_deadlines' => '2026-11-04T10:00,2026-11-25T10:00',
    'status' => 'planned',
    'priority' => 'medium',
];
saveOpportunity(0, $values);
$id = (int)$pdo->query('SELECT id FROM opportunities ORDER BY id DESC LIMIT 1')->fetchColumn();
$logs = fn() => $pdo->query('SELECT * FROM opportunity_progress ORDER BY id')->fetchAll();
check(count($logs()) === 1 && $logs()[0]['to_status'] === 'planned', 'Initial status was not logged');

saveOpportunity($id, [...$values, 'status' => 'applied', 'progress_at' => '2026-10-13', 'progress_note' => '一次募集で応募']);
check(count($logs()) === 2, 'Status change was not logged');
check($logs()[1]['from_status'] === 'planned' && $logs()[1]['to_status'] === 'applied', 'Status transition is incorrect');
check($logs()[1]['occurred_at'] === '2026-10-13' && $logs()[1]['note'] === '一次募集で応募', 'Progress date or note was lost');

saveOpportunity($id, [...$values, 'status' => 'applied']);
check(count($logs()) === 2, 'Unchanged save should not add a log');
saveOpportunity($id, [...$values, 'status' => 'applied', 'progress_at' => '2026-10-20T14:00', 'progress_note' => '選考案内を確認']);
check(count($logs()) === 3, 'Note-only progress was not logged');
check(count(snapshot()['opportunities'][0]['progress_log']) === 3, 'Snapshot does not include progress');

try {
    saveOpportunity($id, [...$values, 'status' => 'screening', 'progress_at' => '2026-02-30']);
    throw new RuntimeException('Invalid date was accepted');
} catch (InvalidArgumentException) {
    check(count($logs()) === 3, 'Invalid progress added a log');
    check($pdo->query("SELECT status FROM opportunities WHERE id = $id")->fetchColumn() === 'applied', 'Invalid progress changed the status');
}

$pdo->prepare('DELETE FROM opportunities WHERE id = ?')->execute([$id]);
check(count($logs()) === 0, 'Progress logs were not removed with the opportunity');
echo "PASS: opportunity progress history, validation, snapshot, cascade\n";
