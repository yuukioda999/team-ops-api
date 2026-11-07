<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Models\Concerns\HasEmailBlindIndex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * アカウント。パスワードは持たない(ADR-0008)。
 * メールアドレスと表示名は暗号化して保存し、照合には email_index を使う(ADR-0003)。
 *
 * @property int $id
 * @property string $email
 * @property string $email_index
 * @property string|null $display_name
 * @property Carbon|null $email_undeliverable_at お知らせのメールがアドレスの問題で届かなかった日時(ADR-0020)
 * @property Carbon|null $email_changed_at
 */
#[Fillable(['email', 'display_name'])]
#[Hidden(['email', 'email_index', 'display_name'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasEmailBlindIndex, HasFactory;

    /**
     * パスワードも remember_token も持たないため、無効にする。
     * 「ログイン状態を保持する」(Auth::login($user, true))は使わない。セッションの30日で足りる(ADR-0008)。
     */
    protected $rememberTokenName = '';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'display_name' => 'encrypted',
            'email_undeliverable_at' => 'datetime',
            'email_changed_at' => 'datetime',
        ];
    }

    public function hasCompletedRegistration(): bool
    {
        return $this->display_name !== null;
    }

    /**
     * @return BelongsToMany<Team, $this, TeamMembership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_memberships')
            ->using(TeamMembership::class)
            ->as('membership')
            ->withPivot('id')
            ->withTimestamps();
    }

    /**
     * このチームで持っている役割。所属していなければ空(ADR-0011)。
     * キャッシュしない。役割が変わったら次のリクエストから新しい役割で判定する(AC-004-10)。
     *
     * @return list<TeamRole>
     */
    public function rolesIn(Team $team): array
    {
        $membership = TeamMembership::where('team_id', $team->id)->where('user_id', $this->id)->first();

        return $membership?->roleList() ?? [];
    }

    /**
     * @return HasMany<TeamJoinRequest, $this>
     */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(TeamJoinRequest::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function createdTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'created_by');
    }
}
