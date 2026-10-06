# しおり — 就活ワークスペース

企業・インターンの募集と、自分のES・経験をつなぐ個人用アプリのMVPです。
PHP + PDO + Vanilla JavaScript + CSSで構成しています。外部ライブラリのインストールは不要です。

## すぐ試す（SQLite）

PHP 8.2以上と `pdo_sqlite`、`mbstring` が必要です。

```sh
cd careerapp
php -S 127.0.0.1:8000 -t public
```

ブラウザで http://127.0.0.1:8000 を開きます。初回に `storage/careerapp.sqlite` が自動作成されます。データはブラウザではなくDBに保存されます。

初回起動時に29卒の検討候補23社を登録します。既存DBにも一度だけ追加されます。企業は後から追加・編集・削除でき、削除しても次回起動時に復活しません。既存の同名企業は空欄の情報だけ補い、記入済みのメモや分類を保持します。募集の調査データは、下記の明示的な取り込みコマンドで追加します。通常の起動では募集を再追加しません。

企業カード、募集一覧、選考ボード、ダッシュボードの社名アイコンから登録した公式サイトを開けます。23社のアイコンは公式サイトが指定するファビコンをローカル保存して表示します。取得元URLは「アイコン出典」から確認できます。未取得・読み込み失敗時は社名の文字に戻ります。企業カードにはリンク先ドメインと関連公式サイトも表示します。初期URLの出典は [database/SOURCES.md](database/SOURCES.md)、初期データは [database/companies.php](database/companies.php) にあります。

大分類・主な領域・注目タグはユーザー提供の検討メモです。現在の企業規模・採用条件・29卒募集の存在を保証する情報ではありません。自動監視機能はまだありません。

架空のサンプルデータの機能も残していますが、DBが完全に空のときのみ利用できます。開発・テスト時に23社の自動追加を止めるには環境変数 `APP_SKIP_INITIAL_COMPANIES=1` を指定します。

## 2026/10/6確認のインターン情報

15件の募集・募集開始予定と23社の確認結果を [database/research-2026-10-06.json](database/research-2026-10-06.json) に収録しています。根拠URL一覧と調査範囲は [database/RESEARCH.md](database/RESEARCH.md) に記録しています。起動中のMySQLには取り込み済みです。

別のDBで使う場合は、一度だけ取り込みます。

```sh
# SQLite
php scripts/import_research.php
# Docker / MySQL
 docker compose exec -T app php scripts/import_research.php
```

同じ調査セットの再実行は何も追加せず、削除済み募集の復活や自分で編集したメモの上書きを防ぎます。企業を削除・改名している場合、元の社名が見つからない募集はスキップします。

募集には確認日・公式URL・29卒の卒年条件と詳細を表示。受付状況は確認時点の記録で、自動監視ではありません。「卒年条件に含む」は他の応募要件を満たす保証ではありません。受付終了や情報不足の企業も企業ノートに調査結果を残しています。

カレンダーには複数回の締切、日付が公表された募集開始を表示します。開始が「11月頃」の場合は11月上部に日付未公表として表示し、11月1日などの架空の日付は設定しません。締切の時刻未公表も日付だけ保存します。開催予定を自分の参加確定予定として自動登録することはありません。

ファビコンは `public/assets/company-icons/manifest.json` に取得元・日時・ローカルパスを記録しています。未取得の登録企業のアイコンは `python3 scripts/fetch_favicons.py` で取得できます（ネットワークが必要）。追加企業は未取得時に文字アイコンを表示します。

## PHP + MySQL + Docker

Docker Desktopを起動して実行します。

```sh
docker compose up --build -d
```

同じ http://127.0.0.1:8000 で利用できます。PHP単体で起動していた場合は、先にそのサーバーを `Ctrl+C` で停止してください。SQLiteとMySQLは別々の保存先で、自動移行はしません。

MySQLのデータは `career_data` ボリュームに残ります。通常の停止は `docker compose down`。`docker compose down -v` はDBを削除するので注意してください。

任意で `.env.example` を `.env` にコピーし、開発用DBパスワードを変更できます。PHP単体では `.env` は読み込まず、環境変数を直接指定します。

## 実装した機能

- ダッシュボード：進行中の募集、7日以内の締切、作成中のES、直近の予定
- 企業ノート：23社の初期データ、企業名・大分類・領域・注目タグ・公式URL・関連URL・研究メモの登録、編集、削除、検索
- 募集・選考管理：企業へのひもづけ、締切時刻、優先度、募集URL、一覧・ボード、状況の変更
- カレンダー：月移動、当月に戻る、日別予定、募集締切の自動表示、説明会・面接などの予定登録
- ESライブラリ：設問・本文、文字数カウント、タグ検索、募集・経験とのひもづけ。ES編集中に応募先・経験を自由記述で追加でき、ESと同時に保存
- ES進捗：編集画面上部で下書き → 確認・添削中 → 修正中 → 完成 → 提出済みを選択。保存時に変更日時・段階・任意のメモを記録。提出前は前の段階にも戻せます。提出後は本文を保持してメモを追記でき、修正は複製して行います。過去の変更日時は推測で補完せず、この機能での更新から記録
- 提出済みESの編集ロックと複製：元の本文を残したまま新しい下書きを作成（削除は可能）
- 経験・強み：時期、背景・課題、行動、結果、学び、タグ。ES編集中に経験を参照可能
- 全データのJSON書き出し
- スマートフォン向けレイアウト、入力チェック、CSRF対策、HTMLエスケープ

文字数は改行・空白を含むUnicodeコードポイント数です。企業のフォームによって数え方が異なる場合は、応募画面を優先してください。日時は日本時間の想定です。

## ファイル構成

```text
public/
  index.php           共通レイアウト
  api.php             JSON API（取得・保存・削除）
  assets/app.js       各画面、フォーム、カレンダー
  assets/style.css    レスポンシブUI
src/
  bootstrap.php       DB接続・テーブル作成・入力チェック
  sample.php          任意のサンプルデータ
storage/              SQLite保存先（Git管理対象外）
tests/smoke.py         一時DBで実行するHTTP結合テスト
Dockerfile
compose.yaml
```

テーブルの関係は `companies → opportunities → documents / events` と `experiences → documents`。企業に募集がある場合は企業の削除を防止します。募集・経験を削除してもES本文は残り、関連だけを解除します。

## 検証

```sh
python3 tests/smoke.py
php tests/initial_companies.php
php tests/research.php
node tests/ui.mjs
node --check public/assets/app.js
php -l src/bootstrap.php
php -l src/sample.php
php -l public/api.php
php -l public/index.php
docker compose config --quiet
# Docker起動後のMySQL確認（書き込みはロールバックします）
docker compose exec -T app php tests/database.php
```

結合テストはPython標準ライブラリで、一時ディレクトリ内のSQLite DBと別ポートのPHPサーバーを使用します。実際の保存データには触れません。

## この土台の範囲と次の開発

現状は **ローカルで1人が使う開発版** です。ログイン・利用者ごとのデータ分離は未実装なので、この状態でインターネットに公開しないでください。PHP組み込みサーバーも開発用です。Dockerのポートはlocalhostのみにバインドしています。

次の段階で追加する候補：

1. ログインとユーザー別のアクセス制御、本番用Webサーバー
2. DBマイグレーションの拡充、タグの独立テーブル化
3. 通知、ESの履歴・差分、JSON取り込み・復元
4. 外部カレンダー連携、募集要項の抽出やES素材提案

現時点の「提出済み」は手動の記録であり、企業への送信機能ではありません。締切通知、AI、PDF添付、外部サービス連携は含んでいません。JSON書き出しは参照・バックアップ用で、取り込みUIは未実装です。

SQLiteの復元可能なバックアップには、サーバーを停止した上で `storage/careerapp.sqlite` をコピーしてください。
