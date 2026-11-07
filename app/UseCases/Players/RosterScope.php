<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * 名簿で見られる範囲(ADR-0013)。代表・役員・コーチは全員、保護者は自分に紐づく選手だけ。
 */
final class RosterScope
{
    public const string ALL = 'all';

    public const string OWN = 'own';

    private function __construct(
        public readonly string $name,
        private readonly Team $team,
        private readonly ?int $membershipId,
    ) {}

    public static function for(User $user, Team $team): self
    {
        if (Gate::forUser($user)->allows('viewAllPlayers', $team)) {
            return new self(self::ALL, $team, null);
        }

        $membership = TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->sole();

        return new self(self::OWN, $team, $membership->id);
    }

    /**
     * チームで絞り込み、保護者なら自分に紐づく選手だけにした問い合わせ(AC-000-01、AC-003-09)。
     *
     * @return Builder<Player>
     */
    public function query(): Builder
    {
        $query = Player::where('team_id', $this->team->id);
        if ($this->membershipId !== null) {
            $membershipId = $this->membershipId;
            $query->whereHas('guardians', fn (Builder $q) => $q->where('membership_id', $membershipId));
        }

        return $query;
    }
}
