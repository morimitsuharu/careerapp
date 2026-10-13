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

function seedHakuhodoDyOne(PDO $pdo): void
{
    $key = 'hakuhodody_one_20261013_v1';
    $check = $pdo->prepare('SELECT name FROM app_migrations WHERE name = ?');
    $check->execute([$key]);
    if ($check->fetchColumn()) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO app_migrations (name, applied_at) VALUES (?, ?)')->execute([$key, date('c')]);
        $companyName = '博報堂DY ONE';
        $companyUrl = 'https://www.hakuhodody-one.co.jp/';
        $find = $pdo->prepare('SELECT id, notes FROM companies WHERE name = ? OR url = ? ORDER BY id LIMIT 1');
        $find->execute([$companyName, $companyUrl]);
        $existingCompany = $find->fetch();
        $companyId = $existingCompany['id'] ?? null;
        $companyNotes = '博報堂DYグループのデジタルコア。DACとアイレップが統合して2024年に設立。データとテクノロジーを活用したマーケティングに注目。';
        if (!$companyId) {
            $company = validate('companies', [
                'name' => $companyName,
                'category' => '大手広告/デジタル',
                'industry' => 'デジタルマーケティング・広告・データ',
                'tags' => '広告,マーケ,企画,データ分析',
                'url' => $companyUrl,
                'secondary_url' => 'https://recruit.hakuhodody-one.co.jp/',
                'notes' => $companyNotes,
            ]);
            $companyId = insertRecord('companies', $company);
        } elseif (in_array($existingCompany['notes'], ['', '29卒の検討候補。分類・主な領域・注目タグは自分の検討メモ。募集の有無・対象卒年は各公式サイトで確認。'], true)) {
            $pdo->prepare('UPDATE companies SET notes = ? WHERE id = ?')->execute([$companyNotes, (int)$companyId]);
        }

        $internshipUrl = 'https://recruit.hakuhodody-one.co.jp/recruit/internship/';
        $find = $pdo->prepare('SELECT id FROM opportunities WHERE company_id = ? AND url = ? LIMIT 1');
        $find->execute([(int)$companyId, $internshipUrl]);
        if (!$find->fetchColumn()) {
            insertRecord('opportunities', validate('opportunities', [
                'company_id' => (string)$companyId,
                'title' => '実践型マーケティングインターンシップ（早期選考直結）',
                'deadline' => '2026-10-11T23:59',
                'status' => 'interested',
                'priority' => 'medium',
                'url' => $internshipUrl,
                'notes' => '3日間の対面プログラム。顧客課題を題材にグループで提案を作り、現場社員へ発表する。日程は2026/11/24〜26または12/15〜17、会場は赤坂本社。2026/10/13確認時点で応募締切済み。',
                'recruitment_state' => 'closed',
                'eligibility' => 'check',
                'eligibility_notes' => '公式参加条件は国内外の大学・大学院に在籍し、全日程に参加可能な方。29卒の明記はないため応募対象か確認が必要。',
                'checked_at' => '2026-10-13',
            ]));
        }

        $find = $pdo->prepare('SELECT company_id FROM company_research WHERE company_id = ?');
        $find->execute([(int)$companyId]);
        if (!$find->fetchColumn()) {
            $pdo->prepare('INSERT INTO company_research (company_id, checked_at, result, summary, source_url) VALUES (?, ?, ?, ?, ?)')->execute([
                (int)$companyId,
                '2026-10-13',
                '確認した募集は終了',
                '実践型マーケティングインターンの応募締切は2026/10/11 23:59。次回募集日程は未確認。',
                $internshipUrl,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
