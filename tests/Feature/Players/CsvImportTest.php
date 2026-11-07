<?php

namespace Tests\Feature\Players;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 名簿の CSV 取り込み(AC-003-13〜19・21、ADR-0014)。
 */
class CsvImportTest extends PlayerTestCase
{
    private function csv(string $content, string $name = 'players.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * @return TestResponse<Response>
     */
    private function preview(string $content): TestResponse
    {
        return $this->actingAs($this->representative)->post($this->url('/import/preview'), ['file' => $this->csv($content)], ['Accept' => 'application/json']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function import(string $content, ?string $hash = null): TestResponse
    {
        return $this->actingAs($this->representative)->post($this->url('/import'), [
            'file' => $this->csv($content),
            'file_hash' => $hash ?? hash('sha256', $content),
        ], ['Accept' => 'application/json']);
    }

    /**
     * @param  list<string>  $rows
     */
    private function lines(array $rows): string
    {
        return "表示名,学年,背番号\r\n".implode("\r\n", $rows)."\r\n";
    }

    public function test_AC_003_13_the_template_is_utf8_with_bom_and_has_the_header_and_one_example(): void
    {
        $response = $this->actingAs($this->representative)->get($this->url('/import/template'))->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $body = (string) $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_values(array_filter(explode("\r\n", substr($body, 3))));
        $this->assertSame('表示名,学年,背番号', $lines[0]);
        $this->assertCount(2, $lines);

        // テンプレートはそのまま取り込める
        $this->preview($body)->assertOk()->assertJsonPath('errors', [])->assertJsonPath('count', 1);
    }

    public function test_AC_003_14_preview_shows_the_count_and_first_ten_rows_and_registers_nothing(): void
    {
        $rows = array_map(fn (int $i) => "選手{$i},小3,{$i}", range(1, 12));

        $response = $this->preview($this->lines($rows))
            ->assertOk()
            ->assertJsonPath('count', 12)
            ->assertJsonCount(10, 'rows')
            ->assertJsonPath('rows.0', ['line' => 2, 'display_name' => '選手1', 'grade' => 'e3', 'number' => 1])
            ->assertJsonPath('errors', [])
            ->assertJsonPath('warnings', []);
        $this->assertSame(hash('sha256', $this->lines($rows)), $response->json('file_hash'));
        $this->assertSame(0, Player::count());

        $this->import($this->lines($rows), (string) $response->json('file_hash'))->assertCreated()->assertExactJson(['imported' => 12]);
        $this->assertSame(12, Player::where('team_id', $this->team->id)->count());
        $this->assertSame('選手12', Player::where('number', 12)->sole()->display_name);
    }

    public function test_AC_003_14_confirming_a_different_file_than_the_preview_is_rejected(): void
    {
        $previewed = $this->lines(['山田 太郎,小3,10']);
        $hash = (string) $this->preview($previewed)->json('file_hash');

        $this->import($this->lines(['山田 太郎,小3,10', '佐藤 次郎,小1,']), $hash)
            ->assertUnprocessable()->assertJsonValidationErrors(['file_hash']);
        $this->assertSame(0, Player::count());
    }

    public function test_AC_003_15_one_bad_row_blocks_the_whole_import_and_errors_show_line_column_and_reason(): void
    {
        $content = $this->lines(['山田 太郎,小3,10', '佐藤 次郎,小7,', ',小1,1', '鈴木 三郎,中1,1000']);

        $this->preview($content)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('errors', [
                ['line' => 3, 'column' => '学年', 'message' => '3行目 学年:『小7』は選べません(未就学、小1〜小6、中1〜中3)'],
                ['line' => 4, 'column' => '表示名', 'message' => '4行目 表示名:入力してください'],
                ['line' => 5, 'column' => '背番号', 'message' => '5行目 背番号:0〜999の数字にしてください'],
            ]);

        $this->import($content)
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', '3行目 学年:『小7』は選べません(未就学、小1〜小6、中1〜中3)')
            ->assertJsonCount(3, 'errors.file');
        $this->assertSame(0, Player::count());
        $this->assertNull($this->latestAudit('player.imported'));
    }

    public function test_AC_003_15_line_numbers_count_the_header_as_line_one_even_with_blank_lines(): void
    {
        $content = "表示名,学年,背番号\r\n山田 太郎,小3,\r\n\r\n佐藤 次郎,小8,\r\n,,\r\n";

        $this->preview($content)->assertOk()->assertJsonPath('count', 1)->assertJsonPath('errors.0.line', 4);
    }

    public function test_AC_003_15_columns_are_found_by_the_header_and_the_number_column_is_optional(): void
    {
        $this->preview("学年,表示名\n小２, 山田 太郎 \n")
            ->assertOk()
            ->assertJsonPath('rows.0', ['line' => 2, 'display_name' => '山田 太郎', 'grade' => 'e2', 'number' => null]);

        $this->preview("名前,学年\n山田,小1\n")->assertUnprocessable()->assertJsonPath('errors.file.0', '1行目 見出し:「表示名」の列がありません。見出しは「表示名,学年,背番号」にしてください。');
        $this->preview("表示名,学年,背番号\n")->assertUnprocessable()->assertJsonPath('errors.file.0', '取り込む選手の行がありません。');
        $this->preview('')->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_AC_003_16_a_shift_jis_file_saved_by_excel_is_read_without_garbling(): void
    {
        // Windows の拡張文字(髙・﨑)も読める CP932 で保存したもの
        $content = mb_convert_encoding($this->lines(['髙橋 一郎,小3,10', '山﨑 花子,未就学,']), 'CP932', 'UTF-8');
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));

        $this->preview($content)
            ->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('rows.0.display_name', '髙橋 一郎')
            ->assertJsonPath('rows.1.display_name', '山﨑 花子')
            ->assertJsonPath('rows.1.grade', 'preschool');
        $this->import($content)->assertCreated();
        $this->assertEqualsCanonicalizing(['髙橋 一郎', '山﨑 花子'], Player::all()->pluck('display_name')->all());
    }

    public function test_AC_003_16_utf8_with_and_without_bom_are_read(): void
    {
        foreach (["\xEF\xBB\xBF", ''] as $bom) {
            $this->preview($bom.$this->lines(['山田 太郎,小3,10']))
                ->assertOk()->assertJsonPath('errors', [])->assertJsonPath('rows.0.display_name', '山田 太郎');
        }
    }

    public function test_AC_003_16_an_unreadable_encoding_is_rejected(): void
    {
        $this->preview("\x81\x7F\xFF\xFE,\x80\n")->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'CSVの文字コードを読み取れません。UTF-8 か Shift_JIS で保存してください。');
    }

    public function test_AC_003_17_more_than_500_rows_is_rejected_with_the_limit(): void
    {
        $this->preview($this->lines(array_map(fn (int $i) => "選手{$i},小1,", range(1, 500))))->assertOk()->assertJsonPath('count', 500);

        $content = $this->lines(array_map(fn (int $i) => "選手{$i},小1,", range(1, 501)));
        $this->preview($content)->assertUnprocessable()->assertJsonPath('errors.file.0', 'CSVは500行(見出しを除く)までです。501行あります。');
        $this->import($content)->assertUnprocessable();
        $this->assertSame(0, Player::count());
    }

    public function test_AC_003_17_a_file_over_1mb_is_rejected_with_the_limit(): void
    {
        $content = $this->lines(['山田,小1,']).str_repeat(' ', 1024 * 1024);

        $this->preview($content)->assertUnprocessable()->assertJsonPath('errors.file.0', 'CSVファイルは1MBまでです。');
        $this->assertSame(0, Player::count());
    }

    public function test_AC_003_18_values_starting_with_formula_characters_are_stored_as_plain_text(): void
    {
        $content = $this->lines(['=HYPERLINK("x.invalid"),小3,', '+1,小3,', '-太郎,小3,', '@SUM(A1),小3,']);

        $this->preview($content)->assertOk()->assertJsonPath('errors', [])->assertJsonPath('rows.0.display_name', '=HYPERLINK("x.invalid")');
        $this->import($content)->assertCreated();

        $this->assertEqualsCanonicalizing(['=HYPERLINK("x.invalid")', '+1', '-太郎', '@SUM(A1)'], Player::all()->pluck('display_name')->all());
    }

    public function test_AC_003_19_the_import_is_audited_with_the_count_only(): void
    {
        $this->import($this->lines(['山田 太郎,小3,10', '佐藤 次郎,小1,']))->assertCreated();

        $log = $this->latestAudit('player.imported');
        $this->assertNotNull($log);
        $this->assertSame($this->representative->id, $log->actor_user_id);
        $this->assertEquals(['count' => 2, 'warnings' => 0], $log->details);
        $this->assertStringNotContainsString('山田', (string) DB::table('audit_logs')->pluck('details')->implode(' '));
    }

    public function test_AC_003_19_the_200_player_limit_is_checked_on_preview_and_again_on_import(): void
    {
        $now = now();
        DB::table('players')->insert(array_map(fn (int $i) => [
            'team_id' => $this->team->id, 'display_name' => encrypt("選手{$i}", false), 'grade' => 'e1', 'number' => null, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 197)));
        $content = $this->lines(['A,小1,', 'B,小1,', 'C,小1,']);
        $hash = (string) $this->preview($content)->assertOk()->assertJsonPath('errors', [])->json('file_hash');

        // 確認から確定までの間に1人増えた
        $this->player('割り込み');
        $this->import($content, $hash)->assertUnprocessable()
            ->assertJsonPath('errors.file.0', '選手は1チーム200人までです。今は198人なので、登録できるのはあと2人です。');
        $this->assertSame(198, Player::count());

        $this->preview($content)->assertOk()->assertJsonPath('errors.0', ['line' => null, 'column' => null, 'message' => '選手は1チーム200人までです。今は198人なので、登録できるのはあと2人です。']);
    }

    public function test_AC_003_21_duplicates_within_the_csv_and_against_the_roster_are_warnings_not_errors(): void
    {
        $this->player('山田 太郎', Grade::E3);

        $content = $this->lines(['山田　太郎,小3,10', '佐藤 次郎,小1,', 'さとう,小2,', ' 佐藤 次郎 ,小1,5', '佐藤 次郎,小2,']);
        $this->preview($content)
            ->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('warnings', [
                ['line' => 2, 'column' => '表示名', 'message' => '2行目:同じ表示名・学年の選手がすでに名簿にいます'],
                ['line' => 5, 'column' => '表示名', 'message' => '5行目:3行目と同じ表示名・学年です'],
            ]);

        $this->import($content)->assertCreated()->assertExactJson(['imported' => 5]);
        $this->assertEquals(['count' => 5, 'warnings' => 2], $this->latestAudit('player.imported')?->details);
    }

    public function test_AC_003_13_only_representatives_and_officers_can_use_the_import(): void
    {
        $content = $this->lines(['山田,小1,']);
        foreach ([[TeamRole::Coach], [TeamRole::Parent]] as $roles) {
            $member = $this->member($roles);
            $this->actingAs($member)->get($this->url('/import/template'), ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($member)->post($this->url('/import/preview'), ['file' => $this->csv($content)], ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($member)->post($this->url('/import'), ['file' => $this->csv($content), 'file_hash' => hash('sha256', $content)], ['Accept' => 'application/json'])->assertForbidden();
        }
        $this->assertSame(0, Player::count());

        $officer = $this->member([TeamRole::Officer]);
        $this->actingAs($officer)->post($this->url('/import'), ['file' => $this->csv($content), 'file_hash' => hash('sha256', $content)], ['Accept' => 'application/json'])->assertCreated();
    }
}
