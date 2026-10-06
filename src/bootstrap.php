<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
require_once __DIR__ . '/migrations.php';

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $driver = getenv('DB_DRIVER') ?: 'sqlite';
    if ($driver === 'mysql') {
        $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_DATABASE') ?: 'careerapp') . ';charset=utf8mb4', getenv('DB_USER') ?: 'careerapp', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $path = getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/careerapp.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $id = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (id $id, name VARCHAR(200) NOT NULL, industry VARCHAR(200) NOT NULL DEFAULT '', url TEXT NOT NULL, notes TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS opportunities (id $id, company_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL, deadline VARCHAR(16) NOT NULL DEFAULT '', status VARCHAR(30) NOT NULL DEFAULT 'interested', priority VARCHAR(10) NOT NULL DEFAULT 'medium', url TEXT NOT NULL, notes TEXT NOT NULL, FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS experiences (id $id, title VARCHAR(200) NOT NULL, period VARCHAR(200) NOT NULL DEFAULT '', situation TEXT NOT NULL, action TEXT NOT NULL, result TEXT NOT NULL, learning TEXT NOT NULL, tags TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (id $id, opportunity_id INTEGER NULL, experience_id INTEGER NULL, title VARCHAR(200) NOT NULL, question TEXT NOT NULL, body TEXT NOT NULL, word_limit INTEGER NOT NULL DEFAULT 1000, tags TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', updated_at VARCHAR(30) NOT NULL, FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL, FOREIGN KEY (experience_id) REFERENCES experiences(id) ON DELETE SET NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (id $id, opportunity_id INTEGER NULL, title VARCHAR(200) NOT NULL, starts_at VARCHAR(16) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'interview', notes TEXT NOT NULL, FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL)");
    migrateCompanies($pdo);
    migrateResearch($pdo);
    migrateDocumentSources($pdo);
    return $pdo;
}

const STATUSES = ['interested', 'planned', 'writing', 'applied', 'screening', 'interview', 'accepted', 'closed'];
const FIELDS = [
    'companies' => ['name', 'category', 'industry', 'tags', 'url', 'secondary_url', 'notes'],
    'opportunities' => ['company_id', 'title', 'deadline', 'status', 'priority', 'url', 'notes', 'opens_at', 'recruitment_state', 'eligibility', 'eligibility_notes', 'checked_at', 'additional_deadlines'],
    'documents' => ['opportunity_id', 'experience_id', 'title', 'question', 'body', 'word_limit', 'tags', 'status'],
    'experiences' => ['title', 'period', 'situation', 'action', 'result', 'learning', 'tags'],
    'events' => ['opportunity_id', 'title', 'starts_at', 'kind', 'notes'],
];

function validate(string $entity, array $input): array
{
    $data = [];
    foreach (FIELDS[$entity] as $field) {
        if (isset($input[$field]) && !is_scalar($input[$field])) throw new InvalidArgumentException('入力形式が正しくありません。');
        $value = trim((string)($input[$field] ?? ''));
        if (mb_strlen($value) > 50000) throw new InvalidArgumentException('入力が長すぎます。');
        $data[$field] = $value;
    }
    $name = $entity === 'companies' ? 'name' : 'title';
    if (!$data[$name] || mb_strlen($data[$name]) > 200) throw new InvalidArgumentException('タイトル・企業名は1〜200文字で入力してください。');
    foreach (['industry', 'period', 'category'] as $field) {
        if (isset($data[$field]) && mb_strlen($data[$field]) > 200) throw new InvalidArgumentException('業界・期間は200文字以内で入力してください。');
    }
    foreach (['company_id', 'opportunity_id', 'experience_id'] as $key) {
        if (!array_key_exists($key, $data)) continue;
        if ($data[$key] === '' && $key !== 'company_id') { $data[$key] = null; continue; }
        if (!ctype_digit($data[$key]) || (int)$data[$key] < 1) throw new InvalidArgumentException('関連する項目を選択してください。');
        $table = ['company_id' => 'companies', 'opportunity_id' => 'opportunities', 'experience_id' => 'experiences'][$key];
        $statement = db()->prepare("SELECT id FROM $table WHERE id = ?");
        $statement->execute([(int)$data[$key]]);
        if (!$statement->fetch()) throw new InvalidArgumentException('関連する項目が見つかりません。');
        $data[$key] = (int)$data[$key];
    }
    foreach (['deadline', 'starts_at', 'opens_at', 'checked_at'] as $key) {
        if (!isset($data[$key])) continue;
        if ($data[$key] === '' && $key !== 'starts_at') continue;
        if (!validResearchDate($data[$key], $key === 'opens_at', $key === 'checked_at')) throw new InvalidArgumentException('日時を正しく入力してください。');
    }
    foreach (['url', 'secondary_url'] as $field) {
        if (isset($data[$field]) && $data[$field] !== '' && (!filter_var($data[$field], FILTER_VALIDATE_URL) || !in_array(parse_url($data[$field], PHP_URL_SCHEME), ['https', 'http'], true) || parse_url($data[$field], PHP_URL_USER) !== null || parse_url($data[$field], PHP_URL_PASS) !== null)) throw new InvalidArgumentException('URLは認証情報を含まないhttps://またはhttp://から入力してください。');
    }
    if ($entity === 'companies' && (mb_strlen($data['tags']) > 2000 || mb_strlen($data['secondary_url']) > 2000)) throw new InvalidArgumentException('注目タグ・関連公式サイトURLは2000文字以内で入力してください。');
    if ($entity === 'opportunities' && (!in_array($data['status'], STATUSES, true) || !in_array($data['priority'], ['high', 'medium', 'low'], true))) throw new InvalidArgumentException('選考状況・優先度が正しくありません。');
    if ($entity === 'opportunities') {
        if (!in_array($data['recruitment_state'], ['', 'open', 'upcoming', 'unknown', 'closed'], true) || !in_array($data['eligibility'], ['', 'eligible', 'check', 'ineligible'], true)) throw new InvalidArgumentException('募集状況・対象卒年が正しくありません。');
        if (mb_strlen($data['additional_deadlines']) > 2000 || mb_strlen($data['eligibility_notes']) > 2000) throw new InvalidArgumentException('応募条件・追加締切は2000文字以内で入力してください。');
        foreach (array_filter(array_map('trim', explode(',', $data['additional_deadlines']))) as $date) {
            if (!validResearchDate($date)) throw new InvalidArgumentException('追加締切の日付を確認してください。');
        }
    }
    if ($entity === 'documents') {
        if (!in_array($data['status'], ['draft', 'reviewing', 'revising', 'ready', 'submitted'], true)) throw new InvalidArgumentException('ESの状態が正しくありません。');
        if (!ctype_digit($data['word_limit']) || (int)$data['word_limit'] < 1 || (int)$data['word_limit'] > 10000) throw new InvalidArgumentException('文字数上限は1〜10000で入力してください。');
        $data['word_limit'] = (int)$data['word_limit'];
        $data['updated_at'] = date('c');
    }
    if ($entity === 'events' && !in_array($data['kind'], ['interview', 'briefing', 'internship', 'other'], true)) throw new InvalidArgumentException('予定の種類が正しくありません。');
    return $data;
}

function insertRecord(string $entity, array $data): int
{
    $keys = array_keys($data);
    $sql = 'INSERT INTO ' . $entity . ' (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    db()->prepare($sql)->execute(array_values($data));
    return (int)db()->lastInsertId();
}

function snapshot(): array
{
    $data = [];
    foreach (FIELDS as $entity => $_) {
        $order = $entity === 'companies' ? 'ASC' : 'DESC';
        $data[$entity] = db()->query("SELECT * FROM $entity ORDER BY id $order")->fetchAll();
    }
    $icons = json_decode(file_get_contents(dirname(__DIR__) . '/public/assets/company-icons/manifest.json'), true);
    $research = db()->query('SELECT * FROM company_research')->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($data['companies'] as &$row) {
        $icon = $icons[parse_url($row['url'], PHP_URL_HOST) ?? ''] ?? [];
        $row['favicon_path'] = $icon['path'] ?? '';
        $row['favicon_source_url'] = $icon['source_url'] ?? '';
        $row['research'] = $research[$row['id']] ?? null;
    }
    unset($row);
    $progress = [];
    foreach (db()->query('SELECT * FROM document_progress ORDER BY id DESC')->fetchAll() as $entry) $progress[$entry['document_id']][] = $entry;
    foreach ($data['documents'] as &$row) {
        $row['progress_log'] = $progress[$row['id']] ?? [];
        $row['has_source_file'] = $row['source_file'] !== '';
        unset($row['source_file']);
    }
    unset($row);
    return $data;
}
