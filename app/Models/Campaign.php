<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campaign extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignFactory> */
    use HasFactory;

    // What players see of enemy HP.
    public const ENEMY_HP = ['bands', 'exact', 'hidden'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'enemy_hp',
    ];

    /**
     * Never sent to players: only the DM sees (and shares) the invite link.
     *
     * @var list<string>
     */
    protected $hidden = [
        'invite_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->invite_token ??= self::newInviteToken();
        });
    }

    public static function newInviteToken(): string
    {
        return Str::random(40);
    }

    /** The DM. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Players who've joined, with the character each has claimed. */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('character_id')->withTimestamps();
    }

    /** The party: player characters the DM made for this campaign. */
    public function party(): HasMany
    {
        return $this->hasMany(Creature::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function isRunBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    public function hasPlayer(?User $user): bool
    {
        return $user !== null && $this->players()->whereKey($user->id)->exists();
    }
}
