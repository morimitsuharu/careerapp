<?php
declare(strict_types=1);

// Explicit import only: ordinary page loads never re-add a deleted listing.
function importResearch(array $report): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare('SELECT name FROM app_migrations WHERE name = ?');
        $check->execute([$report['id']]);
        if ($check->fetchColumn()) {
            $pdo->rollBack();
            return ['already_imported' => true, 'added' => 0];
        }
        $pdo->prepare('INSERT INTO app_migrations (name, applied_at) VALUES (?, ?)')->execute([$report['id'], date('c')]);
        $companies = array_column($pdo->query('SELECT id, name FROM companies')->fetchAll(), 'id', 'name');
        $added = 0;
        $skipped = [];
        foreach ($report['opportunities'] as $row) {
            if (!isset($companies[$row['company']])) { $skipped[] = $row['company']; continue; }
            $row['company_id'] = $companies[$row['company']];
            $existing = $pdo->prepare('SELECT id FROM opportunities WHERE company_id = ? AND url = ? AND title = ?');
            $existing->execute([$row['company_id'], $row['url'], $row['title']]);
            if ($existing->fetchColumn()) continue;
            insertRecord('opportunities', validate('opportunities', $row));
            $added++;
        }
        $insert = $pdo->prepare('INSERT INTO company_research (company_id, checked_at, result, summary, source_url) VALUES (?, ?, ?, ?, ?)');
        foreach ($report['companies'] as $row) {
            if (!isset($companies[$row['company']])) continue;
            $id = $companies[$row['company']];
            $pdo->prepare('DELETE FROM company_research WHERE company_id = ?')->execute([$id]);
            $insert->execute([$id, $report['checked_at'], $row['result'], $row['summary'], $row['source_url']]);
        }
        $pdo->commit();
        return ['already_imported' => false, 'added' => $added, 'missing_companies' => array_values(array_unique($skipped))];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
