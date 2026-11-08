<?php

namespace App\UseCases\Demo;

use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Demo\MailpitClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 期限を過ぎたデモチームを消す(AC-007-07・19、ADR-0021)。$all なら期限に関係なくすべて消す(make demo-reset)。
 * チームに付く行(名簿・お知らせ・既読・招待・参加申請・監査ログ)は外部キーで一緒に消える。
 * デモユーザー、アウトボックスの行、Mailpit のメールもここで消す。
 */
final class PruneDemoTeams
{
    public function __construct(private readonly MailpitClient $mailpit) {}

    public function __invoke(bool $all = false): int
    {
        $query = Team::whereNotNull('demo_expires_at');
        if (! $all) {
            $query->where('demo_expires_at', '<=', now());
        }
        $deleted = 0;
        foreach ($query->pluck('id') as $id) {
            DB::transaction(function () use ($id): void {
                Team::whereKey($id)->delete();
                User::where('demo_team_id', $id)->delete();
                // チームのメールの重複防止キーは、すべて team:{チームID}: で始まる(ADR-0018)
                OutboxMessage::where('dedupe_key', 'like', "team:{$id}:%")->delete();
            });
            $this->mailpit->deleteTo(DemoData::domain((string) $id));
            $deleted++;
        }
        if ($deleted > 0) {
            Log::info('Demo teams deleted', ['deleted' => $deleted, 'active' => Team::whereNotNull('demo_expires_at')->count()]);
        }

        return $deleted;
    }
}
