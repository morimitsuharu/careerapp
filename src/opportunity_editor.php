<?php
declare(strict_types=1);

function saveOpportunity(int $id, array $values): void
{
    foreach (['progress_note', 'progress_at'] as $field) {
        if (isset($values[$field]) && !is_string($values[$field])) throw new InvalidArgumentException('進捗の入力形式が正しくありません。');
    }
    $note = trim($values['progress_note'] ?? '');
    $occurredAt = trim($values['progress_at'] ?? '');
    if (mb_strlen($note) > 2000) throw new InvalidArgumentException('進捗メモは2000文字以内で入力してください。');
    if ($occurredAt !== '' && !validResearchDate($occurredAt)) throw new InvalidArgumentException('進捗日時を正しく入力してください。');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $previous = null;
        if ($id) {
            $query = $pdo->prepare('SELECT status FROM opportunities WHERE id = ?');
            $query->execute([$id]);
            $previous = $query->fetchColumn();
            if ($previous === false) throw new InvalidArgumentException('募集が見つかりません。');
        }
        if (($values['company_id'] ?? '') === 'new') {
            foreach (['new_company_name', 'new_company_url'] as $field) {
                if (isset($values[$field]) && !is_string($values[$field])) throw new InvalidArgumentException('企業名・URLの入力形式が正しくありません。');
            }
            $name = trim($values['new_company_name'] ?? '');
            $company = validate('companies', ['name' => $name, 'url' => $values['new_company_url'] ?? '']);
            $query = $pdo->prepare('SELECT id FROM companies WHERE name = ? ORDER BY id');
            $query->execute([$name]);
            $companyId = $query->fetchColumn();
            $values['company_id'] = $companyId ?: insertRecord('companies', $company);
        }
        $data = validate('opportunities', $values);
        if ($id) {
            $sets = implode(',', array_map(fn($key) => "$key = ?", array_keys($data)));
            $pdo->prepare("UPDATE opportunities SET $sets WHERE id = ?")->execute([...array_values($data), $id]);
        } else {
            $id = insertRecord('opportunities', $data);
        }
        if ($previous !== $data['status'] || $note !== '' || $occurredAt !== '') {
            $pdo->prepare('INSERT INTO opportunity_progress (opportunity_id, from_status, to_status, note, occurred_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $previous ?? '', $data['status'], $note, $occurredAt ?: date('c'), date('c')]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
