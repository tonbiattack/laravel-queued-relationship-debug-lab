<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverCampaign;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CampaignDispatchController extends Controller
{
    public function store(Campaign $campaign): JsonResponse
    {
        $campaign->load([
            'subscribers' => fn ($query) => $query->where('marketing_opt_in', true),
        ]);

        $subscriberIds = $campaign->subscribers->pluck('id')->all();

        Log::info('campaign delivery dispatched', [
            'campaign_id' => $campaign->id,
            'subscriber_ids' => $subscriberIds,
        ]);

        DeliverCampaign::dispatch($campaign->id, $subscriberIds);

        return response()->json([
            'campaign_id' => $campaign->id,
            'queued_subscriber_ids' => $subscriberIds,
        ], 202);
    }
}
