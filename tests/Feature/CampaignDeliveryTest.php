<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_opted_in_subscribers_only_receive_the_campaign(): void
    {
        $campaign = Campaign::query()->create([
            'name' => 'August product update',
        ]);

        $optedInSubscriber = $campaign->subscribers()->create([
            'email' => 'opted-in@example.test',
            'marketing_opt_in' => true,
        ]);

        $optedOutSubscriber = $campaign->subscribers()->create([
            'email' => 'opted-out@example.test',
            'marketing_opt_in' => false,
        ]);

        $response = $this->postJson("/api/campaigns/{$campaign->id}/deliveries");

        $response
            ->assertAccepted()
            ->assertJson([
                'campaign_id' => $campaign->id,
                'queued_subscriber_ids' => [$optedInSubscriber->id],
            ]);

        $this->assertDatabaseHas('campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'subscriber_id' => $optedInSubscriber->id,
        ]);

        $this->assertDatabaseMissing('campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'subscriber_id' => $optedOutSubscriber->id,
        ]);
    }
}
