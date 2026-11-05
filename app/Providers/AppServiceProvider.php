<?php

namespace App\Providers;

use App\Support\Crypto\BlindIndex;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\RelayClient;
use App\Support\Queue\RedactingFailedJobProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            BlindIndex::class,
            fn () => BlindIndex::fromConfig(config('team_ops.blind_index_key')),
        );

        $this->app->singleton(
            RelayClient::class,
            fn (Application $app) => RelayClient::fromConfig($app->make(HttpFactory::class), (array) config('team_ops.notify_relay')),
        );
        $this->app->singleton(
            EmailOutbox::class,
            fn () => new EmailOutbox((bool) config('team_ops.demo_mode')),
        );

        // 失敗したジョブの記録にも、個人情報を伏せた例外を保存する(AC-000-04)
        $this->app->extend('queue.failer', function (mixed $failer, Application $app) {
            if (! $failer instanceof DatabaseUuidFailedJobProvider) {
                return $failer;
            }

            return new RedactingFailedJobProvider(
                $app->make('db'),
                (string) config('queue.failed.database'),
                (string) config('queue.failed.table'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cookie のセッションだけで認証する(ADR-0008)。APIトークンの表は持たないため、
        // Authorization: Bearer を付けたリクエストもトークンとしては扱わない
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
    }
}
