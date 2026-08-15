# キュージョブで配信対象が広がる不具合のデバッグ記録

## 対象の不具合

キャンペーン配信APIは、`marketing_opt_in = true` の購読者だけをロードしてからジョブをdispatchします。HTTP応答もdispatch直前のログも対象IDは同意済みの1人だけです。しかし、バグ状態ではジョブ実行後に同意していない購読者の配信記録も作成されます。

| 項目 | 期待値 | バグ状態での実際値 |
| --- | --- | --- |
| HTTP応答 | `202 Accepted` | `202 Accepted` |
| dispatch直前の対象ID | `[1]` | `[1]` |
| ジョブ実行時の対象ID | `[1]` | `[1, 2]` |
| 配信記録 | 購読者ID `1` だけ | 購読者ID `1` と `2` |

## 再現条件

バグを含むコミットは [`657f9d8`](https://github.com/tonbiattack/laravel-queued-relationship-debug-lab/commit/657f9d8) です。PHP 8.3以上とComposerを用意した状態で、次のコマンドを実行します。

```bash
git checkout 657f9d8
composer install
php artisan test --filter=CampaignDeliveryTest
```

失敗はHTTP応答ではなく、最終状態を検証するアサーションで発生します。

```text
Failed asserting that a row in the table [campaign_deliveries] does not match the attributes {
    "campaign_id": 1,
    "subscriber_id": 2
}.
Found similar results: [
    {"campaign_id": 1, "subscriber_id": 1},
    {"campaign_id": 1, "subscriber_id": 2}
].
```

## 観測と切り分け

失敗を「APIが不正な対象を返した」「入力の絞り込みが失敗した」「ジョブ境界で状態が変わった」の三つに分けました。入力、HTTP出力、永続化後の状態を別々に確認すると、問題はジョブ境界の後に限定できます。

| 確認対象 | 観測結果 | 判断 |
| --- | --- | --- |
| APIのリレーションロード | `where('marketing_opt_in', true)` を指定している | dispatch直前は対象が絞られている |
| HTTP応答 | `queued_subscriber_ids` は `[1]`、ステータスは `202` | APIの表面的な応答は契約どおり |
| dispatch直前ログ | `subscriber_ids:[1]` | キュー投入前のコレクションは正しい |
| ジョブ内ログ | `subscriber_ids:[1,2]` | 実行時に対象集合が広がっている |
| 配信記録テーブル | 購読者ID `1` と `2` の行がある | HTTP成功だけでは副作用を保証できない |
| ジョブの引数 | `public Campaign $campaign` | Eloquentモデルを非同期境界へ渡している |
| Laravel公式仕様 | モデルは識別子でシリアライズされ、ロード済みリレーションは再取得される。事前の制約は再適用されない | フレームワーク仕様と観測結果が一致する |

バグ状態で採取したログは次のとおりです。

```text
[2026-08-15 10:55:30] PROD.INFO: campaign delivery dispatched {"campaign_id":1,"subscriber_ids":[1]}
[2026-08-15 10:55:30] PROD.INFO: campaign delivery job started {"campaign_id":1,"subscriber_ids":[1,2]}
```

デバッガーを使う場合は、`CampaignDispatchController::store` の `DeliverCampaign::dispatch` の直前と、バグコミットの `DeliverCampaign::handle` の先頭にブレークポイントを置きます。前者で `$campaign->subscribers->pluck('id')` が `[1]`、後者で同じプロパティの値が `[1, 2]` へ変わることを確認できます。このサンプルではログと回帰テストで同じ差分を永続化後まで確認しているため、IDE固有の設定がなくても再現できます。

## 原因

バグコミットでは、ジョブが `Campaign` モデルを受け取り、`handle` の中で `$this->campaign->subscribers` を読み直していました。Laravelのキューでは、ジョブへ渡したEloquentモデルは識別子を中心にシリアライズされ、ジョブ実行時にモデルとロード済みリレーションが再取得されます。ロード時に適用した `marketing_opt_in = true` の制約は、再取得時には引き継がれません。[1]

したがって、問題はリレーション定義そのものでも、`where` の記述でもありません。同期キューではなく、モデルをまたいで非同期ジョブへ渡すという境界の設計が直接原因です。`202 Accepted` はジョブ投入の成功を表すだけで、配信先が正しいことの証拠ではありません。

## 修正

修正コミットは [`2134ddd`](https://github.com/tonbiattack/laravel-queued-relationship-debug-lab/commit/2134ddd) です。ジョブの引数を `Campaign` モデルから、`campaignId` とdispatch時に確定した `subscriberIds` へ変更しました。ジョブ内ではこのID集合へ `whereKey` を適用して購読者を再取得します。

```php
DeliverCampaign::dispatch($campaign->id, $subscriberIds);
```

```php
$subscriberIds = Subscriber::query()
    ->where('campaign_id', $this->campaignId)
    ->whereKey($this->subscriberIds)
    ->pluck('id')
    ->all();
```

この修正は「dispatchした時点で確定した対象に配信する」という契約を、キューのペイロードに明示します。Eloquentリレーションの復元方法に、対象集合の意味を委ねません。

## 回帰確認

修正後は、同じテストがHTTP応答、同意済み購読者への配信記録、未同意購読者への配信記録がないことを検証します。変更対象だけでなく、対象外を保持するアサーションを残すことが再発防止の中心です。

```bash
git checkout main
php artisan test --filter=CampaignDeliveryTest
php artisan test
vendor/bin/pint --test
```

確認済みの結果は、`CampaignDeliveryTest` が4アサーションで成功、全テストが成功、Pintが34ファイルで成功です。

## 設計上の制約

本サンプルはdispatch時点の対象IDをスナップショットとして扱います。ジョブが待機している間に購読を解除しても、ID集合に含まれる限りは配信対象です。実行時の同意状態を優先するプロダクトでは、`Subscriber` の再取得クエリに `where('marketing_opt_in', true)` を追加し、その振る舞いを別の回帰テストで明示してください。

## References

[1] [Laravel 13.x Queues — Queued Relationships](https://laravel.com/docs/13.x/queues#queued-relationships)
