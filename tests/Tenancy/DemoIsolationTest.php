<?php

namespace Tests\Tenancy;

use App\Models\Notice;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Demo\DemoTestCase;

/**
 * 訪問者どうしのデモチームの分離(AC-007-06)。通常のチーム間の分離と同じ仕組み(ADR-0009)で守る。
 */
class DemoIsolationTest extends DemoTestCase
{
    public function test_AC_007_06_one_visitor_cannot_read_another_visitors_demo_team(): void
    {
        $a = $this->demoTeam($this->demoLogin('representative', '203.0.113.30')->assertCreated());
        $b = $this->demoTeam($this->demoLogin('representative', '203.0.113.31')->assertCreated());
        $playerOfA = Player::where('team_id', $a->id)->value('id');
        $noticeOfA = Notice::where('team_id', $a->id)->value('id');

        // いまは B としてログインしている
        $this->getJson("/api/teams/{$a->id}")->assertNotFound();
        $this->getJson("/api/teams/{$a->id}/players")->assertNotFound();
        $this->getJson("/api/teams/{$a->id}/demo/mails")->assertNotFound();
        $this->getJson("/api/teams/{$b->id}/players/{$playerOfA}")->assertNotFound();
        $this->getJson("/api/teams/{$b->id}/notices/{$noticeOfA}/status")->assertNotFound();
        $this->assertSame(1, DB::table('teams')->where('id', $b->id)->count());
    }
}
