<?php

namespace Tests\Feature\Members;

use App\Enums\TeamRole;
use App\Models\Team;
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
            // 役割の和(AC-004-02)
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

        $this->actingAs($actor);
        $response = ($spec['call'])(new PermissionContext($this, $team, $target->id, $nextRepresentative->id));

        if ($allowed) {
            isset($spec['ok']) ? ($spec['ok'])($response) : $this->assertTrue($response->isSuccessful(), "{$operation} を {$this->label($roleValues)} が行えなかった({$response->status()})");
        } else {
            isset($spec['denied']) ? ($spec['denied'])($response) : $response->assertForbidden();
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

    public function test_AC_004_01_the_matrix_covers_every_row_of_the_spec_table(): void
    {
        // spec の権限表の行数(メンバー一覧の行を含む)。005・006 の行は、作るまで PENDING に置く
        $this->assertCount(14, array_merge(array_keys(PermissionMatrix::operations()), PermissionMatrix::PENDING));
    }

    /**
     * @param  list<string>  $roles
     */
    private function label(array $roles): string
    {
        return implode('+', $roles);
    }
}
