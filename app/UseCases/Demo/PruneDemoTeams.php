<?php

namespace App\UseCases\Demo;

use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Demo\MailpitClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 期限を過ぎたデモチームを消す(AC-007-07・19、ADR-0021)。$all なら期限に関係なくすべて消す(make demo-reset)。
 * チームに付く行(名簿・お知らせ・既読・招待・参加申請・監査ログ・デモユーザー)は外部キーで一緒に消える。
 * デモユーザーのセッションの IP・ブラウザの情報、アウトボックスの行、Mailpit のメールもここで消す。
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
        $mailpitReachable = true;
        foreach ($query->pluck('id') as $id) {
            DB::transaction(function () use ($id): void {
                $this->deleteSessions(User::where('demo_team_id', $id)->pluck('id'));
                Team::whereKey($id)->delete();
                // チームのメールの重複防止キーは、すべて team:{チームID}: で始まる(ADR-0018)
                OutboxMessage::where('dedupe_key', 'like', "team:{$id}:%")->delete();
            });
            // Mailpit につながらなければ、この回は残りのチームのメールを消しに行かない(1件ごとに待たない)。
            // 消し残しは、次の回以降の古いメールの削除で消える
            if ($mailpitReachable) {
                $mailpitReachable = $this->mailpit->deleteTo(DemoData::domain((string) $id));
            }
            $deleted++;
        }
        // 削除のあとに届いたメールや、Mailpit が止まっていて消せなかったメールを、期限を過ぎたものから消す。
        // 期限(24時間)に1時間の余裕を持たせる。Mailpit 自身の保持期限(MP_MAX_AGE)と二重の歯止め
        if ($mailpitReachable) {
            $this->mailpit->deleteDemoMailsReceivedBefore(now()->subHours(CreateDemoTeam::LIFETIME_HOURS + 1));
        }
        if ($deleted > 0) {
            Log::info('Demo teams deleted', ['deleted' => $deleted, 'active' => Team::whereNotNull('demo_expires_at')->count()]);
        }

        return $deleted;
    }

    /**
     * デモユーザーのセッションの行から、IP・ブラウザの情報とユーザーを消す。
     * 行そのものは残す。訪問者が次に操作したときに「デモの有効期限が切れました」と伝えるため(AC-007-08)。
     * 残る中身はデモチームのIDだけで、セッションの寿命が来れば消える
     *
     * @param  Collection<int, mixed>  $userIds
     */
    private function deleteSessions(Collection $userIds): void
    {
        if ($userIds->isEmpty() || config('session.driver') !== 'database') {
            return;
        }
        $table = config('session.table');
        DB::connection(is_string(config('session.connection')) ? config('session.connection') : null)
            ->table(is_string($table) ? $table : 'sessions')
            ->whereIn('user_id', $userIds)
            ->update(['user_id' => null, 'ip_address' => null, 'user_agent' => null]);
    }
}
