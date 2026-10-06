<?php
declare(strict_types=1);

function migrateDocumentSources(PDO $pdo): void
{
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_progress (id $id, document_id INTEGER NOT NULL, from_status VARCHAR(20) NOT NULL, to_status VARCHAR(20) NOT NULL, note TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE)");
    $columns = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? $pdo->query('SHOW COLUMNS FROM documents')->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    foreach (['source_file', 'source_name'] as $name) {
        if (!in_array($name, $columns, true)) $pdo->exec("ALTER TABLE documents ADD COLUMN $name VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

function validResearchDate(string $value, bool $allowMonth = false, bool $dateOnly = false): bool
{
    $formats = $dateOnly ? ['Y-m-d'] : ['Y-m-d', 'Y-m-d\TH:i'];
    if ($allowMonth) $formats[] = 'Y-m';
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) return true;
    }
    return false;
}

function migrateResearch(PDO $pdo): void
{
    $columns = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? $pdo->query('SHOW COLUMNS FROM opportunities')->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query('PRAGMA table_info(opportunities)')->fetchAll(), 'name');
    foreach (['opens_at'=>16, 'recruitment_state'=>20, 'eligibility'=>20, 'eligibility_notes'=>2000, 'checked_at'=>10, 'additional_deadlines'=>2000] as $name=>$size) {
        if (!in_array($name, $columns, true)) $pdo->exec("ALTER TABLE opportunities ADD COLUMN $name VARCHAR($size) NOT NULL DEFAULT ''");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_research (company_id INTEGER PRIMARY KEY, checked_at VARCHAR(10) NOT NULL, result VARCHAR(100) NOT NULL, summary TEXT NOT NULL, source_url TEXT NOT NULL, FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE)");
}

function migrateCompanies(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $columns = $mysql
        ? $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query('PRAGMA table_info(companies)')->fetchAll(), 'name');
    foreach (['category' => "VARCHAR(200) NOT NULL DEFAULT ''", 'tags' => "VARCHAR(2000) NOT NULL DEFAULT ''", 'secondary_url' => "VARCHAR(2000) NOT NULL DEFAULT ''"] as $name => $definition) {
        if (!in_array($name, $columns, true)) $pdo->exec("ALTER TABLE companies ADD COLUMN $name $definition");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (name VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL)');
    if (getenv('APP_SKIP_INITIAL_COMPANIES') !== '1') seedInitialCompanies($pdo);
}

function seedInitialCompanies(PDO $pdo): void
{
    $key = 'initial_companies_2029_v1';
    $check = $pdo->prepare('SELECT name FROM app_migrations WHERE name = ?');
    $check->execute([$key]);
    if ($check->fetchColumn()) return;

    $pdo->beginTransaction();
    try {
        // Claim inside the same transaction: reruns never restore deleted companies.
        $claim = $pdo->prepare('INSERT INTO app_migrations (name, applied_at) VALUES (?, ?)');
        $claim->execute([$key, date('c')]);
        $find = $pdo->prepare('SELECT * FROM companies WHERE name = ?');
        $insert = $pdo->prepare('INSERT INTO companies (name, category, industry, tags, url, secondary_url, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (require dirname(__DIR__) . '/database/companies.php' as $row) {
            $find->execute([$row[0]]);
            if ($existing = $find->fetch()) {
                // Fill missing metadata only; preserve names, notes and populated fields.
                foreach (array_combine(['name', 'category', 'industry', 'tags', 'url', 'secondary_url'], $row) as $field => $value) {
                    if (trim((string)$existing[$field]) === '' && $value !== '') {
                        $pdo->prepare("UPDATE companies SET $field = ? WHERE id = ?")->execute([$value, $existing['id']]);
                    }
                }
                continue;
            }
            $insert->execute([...$row, '29卒の検討候補。分類・主な領域・注目タグは自分の検討メモ。募集の有無・対象卒年は各公式サイトで確認。']);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        // Another initializer may have committed while this one was waiting.
        if ($error instanceof PDOException && in_array((string)$error->getCode(), ['23000', '23505'], true)) {
            $check->execute([$key]);
            if ($check->fetchColumn()) return;
        }
        throw $error;
    }
}
