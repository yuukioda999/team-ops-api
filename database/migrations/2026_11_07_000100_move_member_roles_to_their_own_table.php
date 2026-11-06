<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1人が複数の役割を持てるよう、役割を別の表に移す(ADR-0011)。
 * 001 の team_memberships.role の値を移してから、列を消す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('team_memberships')->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->comment('representative / officer / coach / parent');
            $table->ulid('representative_of')->nullable()->unique()
                ->comment('代表の行だけにチームIDを入れる。一意制約で「代表は各チームに1人だけ」を守る(004)');
            $table->timestamps();

            $table->unique(['membership_id', 'role']);
        });

        $now = now();
        DB::table('team_memberships')->orderBy('id')->each(function (object $membership) use ($now): void {
            DB::table('team_member_roles')->insert([
                'membership_id' => $membership->id,
                'team_id' => $membership->team_id,
                'role' => $membership->role,
                'representative_of' => $membership->role === 'representative' ? $membership->team_id : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('team_memberships', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('team_memberships', function (Blueprint $table) {
            $table->string('role', 30)->default('parent');
        });
        DB::table('team_member_roles')->where('role', 'representative')->orderBy('id')->each(function (object $role): void {
            DB::table('team_memberships')->where('id', $role->membership_id)->update(['role' => 'representative']);
        });
        Schema::dropIfExists('team_member_roles');
    }
};
