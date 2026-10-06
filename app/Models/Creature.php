<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Creature extends Model
{
    /** @use HasFactory<\Database\Factories\CreatureFactory> */
    use HasFactory;

    public const KINDS = ['monster', 'npc', 'player'];

    // When a limited action's uses come back: at the start of its turn, each round, or not until
    // the encounter is reset ("encounter" and "day" behave the same inside one fight).
    public const LIMIT_PERIODS = ['turn', 'round', 'encounter', 'day'];

    // Cooldowns: a number of rounds, or dice for it ("1d4", "2d4+1").
    public const COOLDOWN_PATTERN = '/^(\d{1,3}|\d{0,2}d\d{1,3}([+-]\d{1,3})?)$/i';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'name',
        'summary',
        'rating',
        'hp',
        'ac',
        'initiative_bonus',
        'speed',
        'stats',
        'traits',
        'actions',
        'resources',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hp' => 'integer',
            'ac' => 'integer',
            'initiative_bonus' => 'integer',
            'stats' => 'array',
            'traits' => 'array',
            'actions' => 'array',
            'resources' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The campaign whose party this player character is in. Set by the campaign pages, not mass assignment. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Built-in SRD creatures, plus the given user's own creatures.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('user_id')
            ->when($user, fn (Builder $query) => $query->orWhere('user_id', $user->id)));
    }

    public function isSrd(): bool
    {
        return $this->user_id === null;
    }

    /**
     * The shape the frontend works with (see resources/js/types/tracker.ts).
     *
     * @return array<string, mixed>
     */
    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->isSrd() ? 'srd' : 'homebrew',
            'name' => $this->name,
            'summary' => $this->summary,
            'rating' => $this->rating,
            'hp' => $this->hp,
            'ac' => $this->ac,
            // Null: use DEX (or roll a plain d20 when there's no DEX).
            'initiativeBonus' => $this->initiative_bonus,
            'speed' => $this->speed,
            'stats' => $this->stats,
            'traits' => $this->traits,
            // Actions saved before a kind of limit existed don't have its keys; missing means no such limit.
            'actions' => array_map(fn (array $action) => [
                'name' => $action['name'],
                'description' => $action['description'],
                'uses' => $action['uses'] ?? null,
                'per' => $action['per'] ?? null,
                'recharge' => $action['recharge'] ?? null,
                'cooldown' => $action['cooldown'] ?? null,
                'resource' => $action['resource'] ?? null,
                'cost' => $action['cost'] ?? null,
            ], $this->actions),
            'resources' => $this->resources ?? [],
        ];
    }
}
