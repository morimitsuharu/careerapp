<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['data' => snapshot(), 'csrf' => $_SESSION['csrf']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('対応していない操作です。'); }
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); throw new RuntimeException('ページを再読み込みしてから操作してください。'); }
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('入力形式が正しくありません。');
    $entity = $input['entity'] ?? '';
    $action = $input['action'] ?? '';
    if ($action === 'sample') {
        require dirname(__DIR__) . '/src/sample.php';
        seedSample();
    } else {
        if (!is_string($entity) || !isset(FIELDS[$entity])) throw new InvalidArgumentException('対象が正しくありません。');
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id === false || $id < 0) throw new InvalidArgumentException('IDが正しくありません。');
        $existing = null;
        if ($id) {
            $statement = db()->prepare("SELECT * FROM $entity WHERE id = ?");
            $statement->execute([$id]);
            $existing = $statement->fetch();
            if (!$existing) { http_response_code(404); throw new RuntimeException('項目が見つかりません。'); }
        }
        if ($action === 'delete') {
            if (!$id) throw new InvalidArgumentException('削除対象がありません。');
            if ($entity === 'companies') {
                $statement = db()->prepare('SELECT COUNT(*) FROM opportunities WHERE company_id = ?');
                $statement->execute([$id]);
                if ($statement->fetchColumn()) throw new InvalidArgumentException('募集が登録されています。先に関連する募集を削除してください。');
            }
            db()->prepare("DELETE FROM $entity WHERE id = ?")->execute([$id]);
        } elseif ($action === 'save') {
            if (!is_array($input['values'] ?? null)) throw new InvalidArgumentException('入力形式が正しくありません。');
            if ($entity === 'documents') {
                require_once dirname(__DIR__) . '/src/document_editor.php';
                saveDocument($id, $input['values']);
            } else {
            $data = validate($entity, $input['values']);
            if ($id) {
                $sets = implode(',', array_map(fn($key) => "$key = ?", array_keys($data)));
                db()->prepare("UPDATE $entity SET $sets WHERE id = ?")->execute([...array_values($data), $id]);
            } else insertRecord($entity, $data);
            }
        } else throw new InvalidArgumentException('操作が正しくありません。');
    }
    echo json_encode(['data' => snapshot()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    error_log((string)$e);
    echo json_encode(['error' => http_response_code() >= 500 ? '保存・読み込みに失敗しました。サーバーの接続状態を確認してください。' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
