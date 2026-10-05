<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Encounter extends Model
{
    /** @use HasFactory<\Database\Factories\EncounterFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        // Only ever set from EncounterPayload, which checks the user runs that campaign.
        'campaign_id',
        'round',
        'active_index',
        'combatants',
        'log',
    ];

    /**
     * Matches the column default, so a new encounter has an empty history before being reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'log' => '[]',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'active_index' => 'integer',
            'combatants' => 'array',
            'log' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The shape the tracker page works with (see resources/js/types/tracker.ts).
     *
     * @return array<string, mixed>
     */
    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'campaignId' => $this->campaign_id,
            'round' => $this->round,
            'activeIndex' => $this->active_index,
            'combatants' => $this->combatants,
            'log' => $this->log ?? [],
        ];
    }
}
