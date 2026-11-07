<?php

namespace Tests\Feature\Members;

use App\Enums\Grade;
use App\Enums\JoinRequestStatus;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Policies\TeamPolicy;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\PermissionContext;
use Tests\Support\PermissionMatrix;
use Tests\TestCase;

/**
 * AC-004-01・02:権限表のすべての操作 × すべての役割(と役割の組み合わせ)で API を呼ぶ(ADR-0016)。
 * AC-004-02(コーチ兼保護者がお知らせを作成できる)は、coach+parent の組み合わせで確かめる。
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        $roleSets = [
            ['representative'], ['officer'], ['coach'], ['parent'],
            // 役割の和(持っている役割のどれかが○なら成功)
            ['coach', 'parent'], ['officer', 'parent'], ['officer', 'coach'], ['representative', 'parent'],
        ];
        foreach (array_keys(PermissionMatrix::operations()) as $operation) {
            foreach ($roleSets as $roles) {
                yield $operation.' / '.implode('+', $roles) => [$operation, $roles];
            }
        }
    }

    /**
     * @param  list<string>  $roleValues
     */
    #[DataProvider('cases')]
    public function test_AC_004_01_AC_004_02_only_the_marked_roles_can_do_each_operation(string $operation, array $roleValues): void
    {
        $roles = array_map(fn (string $v) => TeamRole::from($v), $roleValues);
        $spec = PermissionMatrix::operations()[$operation];

        $representative = User::factory()->create(['display_name' => '代表']);
        $team = Team::factory()->withRepresentative()->create(['created_by' => $representative->id]);
        $target = TeamFactory::addMember($team, User::factory()->create(['display_name' => '相手']), [TeamRole::Parent]);
        $nextRepresentative = TeamFactory::addMember($team, User::factory()->create(['display_name' => '次の代表']), [TeamRole::Officer]);
        $player = Player::create(['team_id' => $team->id, 'display_name' => '紐づいていない選手', 'grade' => Grade::E1, 'number' => null]);
        $joinRequestIds = array_map(function (string $name) use ($team): int {
            $applicant = User::factory()->create(['display_name' => $name]);

            return TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $applicant->id, 'status' => JoinRequestStatus::Pending, 'pending_user_id' => $applicant->id])->id;
        }, ['申請者1', '申請者2']);

        if (in_array(TeamRole::Representative, $roles, true)) {
            $actor = $representative;
            TeamMembership::where('team_id', $team->id)->where('user_id', $actor->id)->sole()->grant($roles);
        } else {
            $actor = User::factory()->create(['display_name' => '操作する人']);
            TeamFactory::addMember($team, $actor, $roles);
        }

        $allowed = isset($spec['decide'])
            ? ($spec['decide'])($roles)
            : array_intersect($roleValues, array_map(fn (TeamRole $r) => $r->value, $spec['allowed'])) !== [];

        $own = Notice::create(['team_id' => $team->id, 'author_user_id' => $actor->id, 'title' => '自分の予約', 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $received = Notice::create(['team_id' => $team->id, 'author_user_id' => $representative->id, 'title' => '届いた連絡', 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Published, 'published_at' => now(), 'recipient_count' => 1]);
        $received->recipients()->create(['user_id' => $actor->id]);

        $this->actingAs($actor);
        $context = new PermissionContext($this, $team, $target->id, $nextRepresentative->id, $player->id, [$joinRequestIds[0], $joinRequestIds[1]], $own->id, $received->id);
        foreach ($spec['calls'] as $i => $step) {
            $response = ($step['call'])($context);
            if ($allowed) {
                isset($step['ok']) ? ($step['ok'])($response) : $this->assertTrue($response->isSuccessful(), "{$operation} の{$i}番目の API を {$this->label($roleValues)} が行えなかった({$response->status()})");
            } else {
                isset($step['denied']) ? ($step['denied'])($response) : $response->assertForbidden();
            }
        }
    }

    public function test_AC_004_01_every_team_policy_ability_is_in_the_permission_matrix(): void
    {
        $abilities = array_merge(...array_values(array_map(fn (array $op) => $op['abilities'], PermissionMatrix::operations())));
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            array_filter((new ReflectionClass(TeamPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $m) => ! $m->isConstructor()),
        );

        $this->assertEqualsCanonicalizing($methods, $abilities, 'TeamPolicy に判定を足したら、権限表(tests/Support/PermissionMatrix.php)にも足してください。');
    }

    public function test_AC_004_01_the_matrix_has_exactly_the_rows_of_the_spec_table(): void
    {
        // 005・006 の行は、作るまで PENDING に置く
        $this->assertEqualsCanonicalizing(PermissionMatrix::SPEC_ROWS, [...array_keys(PermissionMatrix::operations()), ...PermissionMatrix::PENDING]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function label(array $roles): string
    {
        return implode('+', $roles);
    }
}
