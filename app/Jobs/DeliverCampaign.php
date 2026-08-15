<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeliverCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Campaign $campaign,
    ) {
    }

    public function handle(): void
    {
        $subscriberIds = $this->campaign->subscribers->pluck('id')->all();

        Log::info('campaign delivery job started', [
            'campaign_id' => $this->campaign->id,
            'subscriber_ids' => $subscriberIds,
        ]);

        foreach ($subscriberIds as $subscriberId) {
            CampaignDelivery::query()->updateOrCreate(
                [
                    'campaign_id' => $this->campaign->id,
                    'subscriber_id' => $subscriberId,
                ],
                [
                    'delivered_at' => now(),
                ],
            );
        }
    }
}
