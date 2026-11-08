<?php

namespace App\Models;

use App\Enums\Sport;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Sport $sport
 * @property int|null $created_by
 * @property Carbon|null $demo_expires_at デモチームなら削除する日時(ADR-0021)
 */
#[Fillable(['name', 'sport', 'created_by'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids;

    /** デモチームか(ADR-0021) */
    public function isDemo(): bool
    {
        return $this->demo_expires_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demo_expires_at' => 'datetime',
            'sport' => Sport::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this, TeamMembership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships')
            ->using(TeamMembership::class)
            ->as('membership')
            ->withPivot('id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
