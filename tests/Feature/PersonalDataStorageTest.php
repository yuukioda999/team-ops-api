<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-0003:表示名とメールアドレスは暗号化して保存し、照合はハッシュ値で行う。
 */
class PersonalDataStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_and_display_name_are_not_stored_in_plain_text(): void
    {
        $user = User::create(['email' => 'coach@example.invalid', 'display_name' => '山田 太郎']);

        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $stored = User::findOrFail($user->id);

        $this->assertStringNotContainsString('coach@example.invalid', implode(' ', array_map('strval', $row)));
        $this->assertStringNotContainsString('山田', implode(' ', array_map('strval', $row)));
        $this->assertSame('coach@example.invalid', $stored->email);
        $this->assertSame('山田 太郎', $stored->display_name);
    }

    public function test_a_user_can_be_found_by_email_regardless_of_case(): void
    {
        $user = User::create(['email' => 'coach@example.invalid']);

        $this->assertTrue(User::whereEmail('Coach@Example.INVALID')->first()?->is($user));
        $this->assertNull(User::whereEmail('other@example.invalid')->first());
    }

    public function test_changing_the_email_updates_the_index(): void
    {
        $user = User::create(['email' => 'old@example.invalid']);

        $user->update(['email' => 'new@example.invalid']);

        $this->assertNull(User::whereEmail('old@example.invalid')->first());
        $this->assertTrue(User::whereEmail('new@example.invalid')->first()?->is($user));
    }

    public function test_personal_data_is_not_included_when_a_user_is_serialized(): void
    {
        $user = User::create(['email' => 'coach@example.invalid', 'display_name' => '山田 太郎']);

        $json = $user->toJson();

        $this->assertStringNotContainsString('coach@example.invalid', $json);
        $this->assertStringNotContainsString('山田', $json);
        $this->assertStringNotContainsString($user->email_index, $json);
    }
}
