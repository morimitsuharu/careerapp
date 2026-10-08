<?php
require dirname(__DIR__) . '/src/bootstrap.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>しおり — 就活ワークスペース</title>
  <meta name="description" content="企業、締切、ES、自分の経験をひとつにつなぐ、あなたの就活ワークスペース。">
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>"><script src="/assets/lookup.js" defer></script><script src="/assets/app.js" defer></script>
</head>
<body>
  <aside class="sidebar">
    <a class="brand" href="#dashboard"><span class="brand-mark">栞</span><span>しおり<small>CAREER WORKSPACE</small></span></a>
    <div class="workspace"><span class="avatar">Me</span><div>自分のワークスペース<small>一歩ずつ、自分らしく。</small></div></div>
    <p class="nav-label">WORKSPACE</p>
    <nav id="nav" aria-label="メインメニュー"></nav>
    <div class="sidebar-bottom"><div class="plant">✳</div><strong>経験は、未来のヒント。</strong><p>小さな気づきも、<br>あなたの言葉で残そう。</p><button class="text-button" data-action="new" data-entity="experiences">経験を記録する <span>↗</span></button></div>
    <button class="export-button" data-action="export">↓ データを書き出す <span>JSON</span></button>
    <p class="local-note">個人用 · このサーバーに保存</p>
  </aside>
  <div class="shell">
    <header class="topbar"><span class="breadcrumb">ワークスペース <span>/</span> <b id="breadcrumb">ダッシュボード</b></span><span class="today" id="today"></span></header>
    <main id="app" tabindex="-1"><div class="loading">ワークスペースを読み込んでいます…</div></main>
    <footer>しおり <span>—</span> あなたの次の一歩を、ここから。</footer>
  </div>
  <dialog id="editor" aria-labelledby="dialog-title"><form id="editor-form"><div class="dialog-head"><div><p class="eyebrow">YOUR WORKSPACE</p><h2 id="dialog-title"></h2></div><button type="button" class="icon-button" data-action="close" aria-label="閉じる">×</button></div><div id="editor-fields"></div><p id="form-error" class="form-error" role="alert"></p><div class="dialog-actions"><button type="button" class="button" data-action="close">キャンセル</button><button class="button primary" type="submit" id="save-button">保存する</button></div></form></dialog>
  <dialog id="confirm-dialog" aria-labelledby="confirm-title"><div class="dialog-head"><h2 id="confirm-title">この項目を削除しますか？</h2></div><p id="confirm-description"></p><div class="dialog-actions"><button class="button" id="cancel-delete">キャンセル</button><button class="button danger" id="confirm-delete">削除する</button></div></dialog>
  <div id="toast" role="status" aria-live="polite"></div>
</body></html>
