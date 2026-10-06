<?php
declare(strict_types=1);
$path = tempnam(sys_get_temp_dir(), 'shiori-research-');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $path);
putenv('APP_SKIP_INITIAL_COMPANIES=0');
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    require dirname(__DIR__) . '/src/bootstrap.php';
    require dirname(__DIR__) . '/src/research.php';
    $report = json_decode(file_get_contents(dirname(__DIR__) . '/database/research-2026-10-06.json'), true, 512, JSON_THROW_ON_ERROR);
    $data = snapshot();
    check(count($data['companies']) === 23 && !$data['opportunities'], 'Must use explicit import');
    foreach ($data['companies'] as $c) check(is_file(dirname(__DIR__) . '/public' . $c['favicon_path']) && $c['favicon_source_url'] !== '', 'Missing favicon provenance');
    $result = importResearch($report);
    check($result['added'] === 15 && !$result['missing_companies'], 'Import count');
    $data = snapshot();
    check(count(array_filter(array_column($data['companies'], 'research'))) === 23, 'Missing company review');
    $november = array_values(array_filter($data['opportunities'], fn($o)=>$o['opens_at'] === '2026-11'));
    check(count($november) === 1 && $november[0]['deadline'] === '', 'Invented date for month-only announcement');
    $sansan = array_values(array_filter($data['opportunities'], fn($o)=>$o['title'] === 'Sansan R&D Internship'))[0];
    check($sansan['deadline'] === '2026-12-31', 'Invented deadline time');
    $id = $data['opportunities'][0]['id'];
    db()->prepare('UPDATE opportunities SET notes = ? WHERE id = ?')->execute(['自分の研究', $id]);
    check(importResearch($report)['already_imported'], 'Import must be idempotent');
    check(db()->query("SELECT notes FROM opportunities WHERE id = $id")->fetchColumn() === '自分の研究', 'User edit overwritten');
    db()->prepare('DELETE FROM opportunities WHERE id = ?')->execute([$id]);
    importResearch($report);
    check(count(snapshot()['opportunities']) === 14, 'Deleted record restored');
    foreach (['2026-13', '2026-02-30', '2026-11-01T25:00'] as $invalid) check(!validResearchDate($invalid, true), 'Invalid date accepted');
    // A broken import must roll back both records and its migration marker.
    $broken=$report; $broken['id']='invalid_import'; $broken['opportunities'][0]['title']='rollback candidate'; $broken['opportunities'][1]['title']='invalid candidate'; $broken['opportunities'][1]['deadline']='2026-02-30';
    try { importResearch($broken); throw new RuntimeException('Invalid import accepted'); } catch (InvalidArgumentException $expected) {}
    check(count(snapshot()['opportunities']) === 14, 'Partial import persisted');
    check(!db()->query("SELECT name FROM app_migrations WHERE name = 'invalid_import'")->fetchColumn(), 'Failed import marker persisted');
    echo "PASS: official import, 23 favicon sources and reviews, date precision, preserved edits, no duplicates/restoration, transactional rollback\n";
} finally { unlink($path); }
