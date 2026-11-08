<?php

namespace App\UseCases\Demo;

use App\Enums\DeliveryStatus;
use App\Enums\JoinRequestStatus;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\Sport;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Player;
use App\Models\PlayerGuardian;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Demo\DemoData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 訪問者専用のデモチームを、架空のデータから作る(AC-007-02、ADR-0021)。
 * 代表・役員・コーチ2・保護者20、選手20、承認待ちの申請2、送信済みのお知らせ2と予約中のお知らせ1。
 * 存在するデモチームが500以上なら作らない(AC-007-10)。
 */
final class CreateDemoTeam
{
    public const int MAX_TEAMS = 500;

    public const int LIFETIME_HOURS = 24;

    /**
     * @return array{team: Team, users: array<string, User>} users は役割(representative / officer / coach / parent)ごとの、ログインに使うユーザー
     */
    public function __invoke(): array
    {
        $active = Team::whereNotNull('demo_expires_at')->count();
        if ($active >= self::MAX_TEAMS) {
            Log::error('Demo teams are full; refused to create another', ['active' => $active]);
            throw new DemoTeamsFull;
        }

        $result = DB::transaction(fn (): array => $this->build());
        Log::info('Demo team created', ['active' => $active + 1]);

        return $result;
    }

    /**
     * @return array{team: Team, users: array<string, User>}
     */
    private function build(): array
    {
        $now = now();
        $team = Team::create(['name' => DemoData::TEAM_NAME, 'sport' => Sport::Soccer]);
        $team->forceFill(['demo_expires_at' => $now->copy()->addHours(self::LIFETIME_HOURS)])->save();
        $newUser = function (string $localPart, string $name) use ($team): User {
            $user = new User(['email' => DemoData::email($localPart, $team->id), 'display_name' => $name]);
            $user->demo_team_id = $team->id;
            $user->save();

            return $user;
        };
        $join = function (User $user, TeamRole $role) use ($team): TeamMembership {
            $team->members()->attach($user->id);
            $membership = TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->sole();
            $membership->grant([$role]);

            return $membership;
        };

        $login = [];
        $staff = [];
        foreach (DemoData::STAFF as [$role, $name, $localPart]) {
            $user = $newUser($localPart, $name);
            $join($user, TeamRole::from($role));
            $staff[] = $user;
            $login[$role] ??= $user;
        }
        $rep = $staff[0]; // DemoData::STAFF の先頭が代表
        $team->forceFill(['created_by' => $rep->id])->save();

        $parents = [];
        foreach (DemoData::PARENT_NAMES as $i => $name) {
            $user = $newUser('parent'.($i + 1), $name);
            $membership = $join($user, TeamRole::Parent);
            $parents[] = $user;
            [$playerName, $grade, $number] = DemoData::PLAYERS[$i];
            $player = Player::create(['team_id' => $team->id, 'display_name' => $playerName, 'grade' => $grade, 'number' => $number]);
            PlayerGuardian::create(['player_id' => $player->id, 'membership_id' => $membership->id]);
        }
        $login['parent'] = $parents[0];

        foreach (DemoData::APPLICANTS as [$name, $localPart, $message]) {
            $user = $newUser($localPart, $name);
            TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $user->id, 'message' => $message, 'status' => JoinRequestStatus::Pending, 'pending_user_id' => $user->id]);
        }

        $everyone = [...$staff, ...$parents];
        foreach (DemoData::PUBLISHED_NOTICES as [$title, $body, $hoursAgo, $readParents, $failedParents]) {
            $publishedAt = $now->copy()->subHours($hoursAgo);
            $notice = Notice::create([
                'team_id' => $team->id, 'author_user_id' => $rep->id, 'title' => $title, 'body' => $body,
                'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Published,
                'published_at' => $publishedAt, 'recipient_count' => count($everyone),
            ]);
            $rows = [];
            foreach ($everyone as $user) {
                $parentNo = array_search($user, $parents, true);
                $failed = $parentNo !== false && in_array($parentNo + 1, $failedParents, true);
                $read = $user === $rep || ($parentNo !== false && $parentNo < $readParents);
                $rows[] = [
                    'notice_id' => $notice->id, 'user_id' => $user->id,
                    'read_at' => $read ? $publishedAt->copy()->addMinutes(10 + ($parentNo === false ? 0 : (int) $parentNo * 7)) : null,
                    'delivery_status' => $failed ? DeliveryStatus::Failed->value : DeliveryStatus::Delivered->value,
                    'delivery_error' => $failed ? 'provider_rejected' : null,
                    'delivery_round' => 0, 'delivery_status_at' => $publishedAt, 'created_at' => $publishedAt, 'updated_at' => $publishedAt,
                ];
            }
            NoticeRecipient::insert($rows);
        }

        [$title, $body, $hoursLater] = DemoData::SCHEDULED_NOTICE;
        Notice::create([
            'team_id' => $team->id, 'author_user_id' => $rep->id, 'title' => $title, 'body' => $body,
            'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Scheduled,
            'scheduled_at' => $now->copy()->addHours($hoursLater), 'recipient_count' => 0,
        ]);

        return ['team' => $team, 'users' => $login];
    }
}
