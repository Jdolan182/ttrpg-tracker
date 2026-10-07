<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * New accounts verify their email before saving anything (routes behind `verified`); until then
 * they can use the tracker and compendium like a guest, and their settings.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * How stat blocks show stats: "18 (+4)", "+4 (18)", or just "18".
     */
    public const STAT_DISPLAYS = ['score_modifier', 'modifier_score', 'score'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'stat_display',
    ];

    /**
     * Matches the column default, so a freshly created user has it before being reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stat_display' => 'score_modifier',
        'plan' => 'free',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * `plan` isn't fillable either: only `php artisan plan:set` (and later, billing) changes it.
     * It's hidden because subscriptions aren't live; the app shows usage against limits instead.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'plan',
        // Bookkeeping for the admin page's totals (App\Support\Visits); nothing on screen uses it.
        'last_active_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function creatures(): HasMany
    {
        return $this->hasMany(Creature::class);
    }

    /** Campaigns this user runs as the DM. */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** Other people's campaigns this user has joined as a player. */
    public function joinedCampaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot('character_id')->withTimestamps();
    }
}
