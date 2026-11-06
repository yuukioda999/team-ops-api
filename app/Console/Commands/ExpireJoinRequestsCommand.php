<?php

namespace App\Console\Commands;

use App\UseCases\JoinRequests\ExpireJoinRequests;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('join-requests:expire')]
#[Description('Cancel join requests left pending for 14 days (AC-002-15)')]
final class ExpireJoinRequestsCommand extends Command
{
    public function handle(ExpireJoinRequests $expire): int
    {
        $this->info('Cancelled '.$expire().' join request(s).');

        return self::SUCCESS;
    }
}
