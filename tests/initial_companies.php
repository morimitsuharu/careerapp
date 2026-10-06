<?php
declare(strict_types=1);
// Isolated legacy DB verifies an actual upgrade, not only fresh creation.
$path = tempnam(sys_get_temp_dir(), 'shiori-seed-');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $path);
putenv('APP_SKIP_INITIAL_COMPANIES=0');
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $legacy = new PDO('sqlite:' . $path);
    $legacy->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(200) NOT NULL, industry VARCHAR(200) NOT NULL DEFAULT '', url TEXT NOT NULL, notes TEXT NOT NULL)");
    $legacy->exec("INSERT INTO companies (name, industry, url, notes) VALUES ('サイバーエージェント', '自分の分類', '', '大事な企業研究'), ('自分で追加した企業', '', '', '残す')");
    require dirname(__DIR__) . '/src/bootstrap.php';
    $data = snapshot();
    check(count($data['companies']) === 24, 'Expected 23 initial companies plus one existing company');
    check(count(array_unique(array_column($data['companies'], 'name'))) === 24, 'Duplicate companies');
    check($data['companies'][0]['notes'] === '大事な企業研究' && $data['companies'][0]['industry'] === '自分の分類', 'Existing data overwritten');
    check($data['companies'][0]['url'] === 'https://www.cyberagent.co.jp/', 'Missing URL not filled');
    $expected = require dirname(__DIR__) . '/database/companies.php';
    foreach ($expected as [$name, $category, $industry, $tags, $url, $secondary]) {
        $row = array_values(array_filter($data['companies'], fn($item) => $item['name'] === $name))[0];
        check($row['category'] === $category && $row['tags'] === $tags && $row['url'] === $url && $row['secondary_url'] === $secondary, 'Incorrect seed data: ' . $name);
    }
    check(!$data['opportunities'] && !$data['documents'] && !$data['events'], 'Invented application records');
    db()->exec("DELETE FROM companies WHERE name = 'DeNA'");
    $fresh = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    migrateCompanies($fresh);
    check((int)$fresh->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 23, 'Deleted company restored');
    check((int)$fresh->query("SELECT COUNT(*) FROM companies WHERE name = 'DeNA'")->fetchColumn() === 0, 'Deleted seed reappeared');
    check((int)$fresh->query('SELECT COUNT(*) FROM app_migrations')->fetchColumn() === 1, 'Migration duplicated');
    echo "PASS: legacy schema upgrade, 23-company seed, preserved edits, source URLs, no duplicate or restored company, no invented applications\n";
} finally { unlink($path); }
