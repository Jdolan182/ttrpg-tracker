<?php

namespace App\Events;

use App\Models\Campaign;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells everyone watching a campaign that its fight changed. Only a ping: viewers then fetch the
 * player view over HTTP, so the fight itself never goes over the socket (and its size doesn't
 * matter). Sent straight away rather than queued, since it's only useful while it's fresh.
 */
class CampaignCombatChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $campaignId) {}

    public static function channelName(int $campaignId): string
    {
        return "campaign.{$campaignId}";
    }

    public static function for(Campaign $campaign): void
    {
        // A broadcast failure (e.g. Reverb not running) shouldn't fail the DM's update: viewers
        // also poll, so they catch up anyway.
        try {
            self::dispatch($campaign->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->campaignId))];
    }

    public function broadcastAs(): string
    {
        return 'combat.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
