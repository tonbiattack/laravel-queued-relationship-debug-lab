<?php

namespace App\Jobs;

use App\Models\CampaignDelivery;
use App\Models\Subscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeliverCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $campaignId,
        public array $subscriberIds,
    ) {}

    public function handle(): void
    {
        $subscriberIds = Subscriber::query()
            ->where('campaign_id', $this->campaignId)
            ->whereKey($this->subscriberIds)
            ->pluck('id')
            ->all();

        Log::info('campaign delivery job started', [
            'campaign_id' => $this->campaignId,
            'subscriber_ids' => $subscriberIds,
        ]);

        foreach ($subscriberIds as $subscriberId) {
            CampaignDelivery::query()->updateOrCreate(
                [
                    'campaign_id' => $this->campaignId,
                    'subscriber_id' => $subscriberId,
                ],
                [
                    'delivered_at' => now(),
                ],
            );
        }
    }
}
