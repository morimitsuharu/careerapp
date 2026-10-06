<?php
declare(strict_types=1);

function seedSample(): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (FIELDS as $entity => $_) {
            if ($pdo->query("SELECT COUNT(*) FROM $entity")->fetchColumn()) throw new InvalidArgumentException('サンプルはデータが空のときだけ追加できます。');
        }
        $companies = [
            ['name' => 'サンプル・クリエイティブ', 'industry' => 'IT・広告', 'url' => '', 'notes' => '【架空の企業】新しいサービスを企画する仕事に関心。'],
            ['name' => 'サンプル・フィンテック', 'industry' => '金融・SaaS', 'url' => '', 'notes' => '【架空の企業】ユーザーに近い距離でプロダクトを改善したい。'],
            ['name' => 'サンプル・グローバル', 'industry' => 'コンサルティング', 'url' => '', 'notes' => '【架空の企業】海外事業やチームの雰囲気を知りたい。'],
        ];
        $ids = array_map(fn($row) => insertRecord('companies', $row), $companies);
        $opps = [];
        foreach (['新規事業 3days インターン', 'プロダクト企画 インターン', 'グローバルビジネス 1day'] as $i => $title) {
            $opps[] = insertRecord('opportunities', ['company_id' => $ids[$i], 'title' => $title, 'deadline' => date('Y-m-d', strtotime('+' . (3 + $i * 5) . ' days')) . 'T23:59', 'status' => ['writing', 'applied', 'interested'][$i], 'priority' => ['high', 'medium', 'low'][$i], 'url' => '', 'notes' => 'サンプルの募集です。実際の募集情報ではありません。']);
        }
        $experience = insertRecord('experiences', ['title' => 'ハッカソンでのチーム開発', 'period' => '大学2年・夏（記入例）', 'situation' => '議論が一部のメンバーに偏り、アイデアが出にくい状況だった。', 'action' => '各自が考えを書き出してから、順番に共有する時間を設けた。', 'result' => '全員のアイデアを組み合わせてプロトタイプを完成させた。', 'learning' => 'チームが力を発揮できる環境を整える大切さを学んだ。', 'tags' => 'チームワーク,課題解決,開発']);
        insertRecord('documents', ['opportunity_id' => $opps[0], 'experience_id' => $experience, 'title' => '学生時代に力を入れたこと', 'question' => '周囲を巻き込み、課題を解決した経験を教えてください。', 'body' => '【記入例】ハッカソンで、メンバー全員が意見を出せる環境づくりに取り組みました。議論が一部に偏っていたため、個人で考える時間と順番に発表する時間を設けました。', 'word_limit' => 400, 'tags' => 'チームワーク,ガクチカ', 'status' => 'draft', 'updated_at' => date('c')]);
        insertRecord('events', ['opportunity_id' => $opps[1], 'title' => 'オンライン会社説明会', 'starts_at' => date('Y-m-d', strtotime('+1 day')) . 'T14:00', 'kind' => 'briefing', 'notes' => 'サンプル予定：質問を3つ用意する。']);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
