# LaravelキュージョブでEloquentリレーションの制約が失われる不具合の再現

## はじめに

このリポジトリは、キャンペーン配信ジョブにEloquentモデルをそのまま渡した結果、dispatch時に絞り込んだ購読者リレーションの条件がジョブ実行時に失われ、配信同意していない購読者にも配信記録が作られる不具合を再現します。Laravel 12、SQLite、同期キューを用いるため、外部のキューサーバーを用意せずに不具合と修正を確認できます。

記事の下書きは、[Qiita用リポジトリ](https://github.com/tonbiattack/qiita)の `private/01_執筆中/06_テスト・デバッグ/デバッグ/` にあります。本リポジトリは非公開です。

| 項目 | 内容 |
| --- | --- |
| 技術スタック | PHP 8.3、Laravel 12、SQLite、PHPUnit |
| HTTP境界 | `POST /api/campaigns/{campaign}/deliveries` |
| 期待する契約 | `marketing_opt_in = true` の購読者だけに配信記録を作る |
| バグコミット | [`657f9d8`](https://github.com/tonbiattack/laravel-queued-relationship-debug-lab/commit/657f9d8) |
| 修正コミット | [`2134ddd`](https://github.com/tonbiattack/laravel-queued-relationship-debug-lab/commit/2134ddd) |

## 不具合の概要

HTTP応答は `202 Accepted` で、dispatch直前のログには同意済み購読者だけが記録されます。しかし、ジョブはEloquentモデルの復元時にリレーションを再取得するため、事前に指定した `marketing_opt_in = true` の制約を保持しません。ジョブ内で `$campaign->subscribers` を読むと、同意していない購読者も含まれます。

| 観測点 | バグ状態 | 修正後 |
| --- | --- | --- |
| HTTP応答 | `202 Accepted` | `202 Accepted` |
| dispatch直前の対象ID | `[1]` | `[1]` |
| ジョブ内の対象ID | `[1, 2]` | `[1]` |
| 配信記録 | 同意済み・未同意の両方 | 同意済みだけ |

## セットアップ

PHP 8.3以上とComposerが必要です。テストはインメモリSQLiteを使用するため、通常は追加のデータベース設定を必要としません。

```bash
git clone https://github.com/tonbiattack/laravel-queued-relationship-debug-lab.git
cd laravel-queued-relationship-debug-lab
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

## バグを再現する

バグコミットでは、HTTP応答と同意済み購読者の配信記録は期待どおりです。一方で、未同意購読者の配信記録が存在しないことを検証するアサーションが失敗します。

```bash
git checkout 657f9d8
composer install
php artisan test --filter=CampaignDeliveryTest
```

期待する失敗は次の形式です。

```text
Failed asserting that a row in the table [campaign_deliveries] does not match
"campaign_id": 1,
"subscriber_id": 2
```

## 修正を確認する

修正では、ジョブの引数を `Campaign` モデルから `campaignId` と配信時点で確定した `subscriberIds` へ変更します。ジョブは受け取ったID集合に限定して購読者を再取得するため、リレーションの事前ロード条件へ依存しません。

```bash
git checkout main
php artisan test --filter=CampaignDeliveryTest
php artisan test
vendor/bin/pint --test
```

## 調査記録

観測したログ、原因の切り分け、修正、回帰テストの範囲、制約は [docs/debugging-record.md](docs/debugging-record.md) に記録しています。Laravelのキューにおけるモデルとリレーションの復元規則は、[公式キュードキュメント](https://laravel.com/docs/13.x/queues#queued-relationships)を参照してください。

## 制約

このサンプルの契約は「dispatch時に確定した対象へ送る」ことです。そのため、ジョブ実行までに購読状態が変更されても、ID集合に含まれる購読者は対象となります。ジョブ実行時点の同意状態を優先する要件では、ジョブ内の再取得クエリへ `marketing_opt_in = true` を加え、仕様とテストを変更してください。
