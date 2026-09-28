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
        'speed',
        'stats',
        'traits',
        'actions',
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
            'stats' => 'array',
            'traits' => 'array',
            'actions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'speed' => $this->speed,
            'stats' => $this->stats,
            'traits' => $this->traits,
            // Actions saved before limits existed have no uses/per; treat them as unlimited.
            'actions' => array_map(fn (array $action) => [
                'name' => $action['name'],
                'description' => $action['description'],
                'uses' => $action['uses'] ?? null,
                'per' => $action['per'] ?? null,
            ], $this->actions),
        ];
    }
}
