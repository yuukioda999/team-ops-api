<?php

namespace App\UseCases\Demo;

use App\Models\Team;
use App\Support\Demo\DemoData;
use App\Support\Demo\MailpitClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * デモチームから送られたメールを新しい順に返す(AC-007-12、ADR-0021)。
 * Mailpit に、このチームのデモ用ドメインで問い合わせる。ほかの訪問者のメールは出さない。
 * デモモードでない、またはデモチームでなければ 404。
 */
final class ListDemoMails
{
    public const int LIMIT = 30;

    public function __construct(private readonly MailpitClient $mailpit) {}

    /**
     * @return list<array{subject: string, to: list<string>, sent_at: string, text: string}>
     */
    public function __invoke(Team $team): array
    {
        if (! (bool) config('team_ops.demo_mode') || ! $team->isDemo()) {
            throw new NotFoundHttpException;
        }

        return $this->mailpit->messagesTo(DemoData::domain($team->id), self::LIMIT);
    }
}
