<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Support\Crypto\BlindIndex;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\RelayClient;
use App\Support\Queue\RedactingFailedJobProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
            fn (Application $app) => RelayClient::fromConfig(
                $app->make(HttpFactory::class),
                (array) config('team_ops.notify_relay'),
                (string) $app->environment(),
            ),
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

        $this->configureRateLimiting();
        $this->bindTeamToMembership();
    }

    /**
     * ルートの {team} は、ログイン中の利用者が所属するチームからだけ探す(ADR-0009)。
     * 所属していないチームは、存在していても 404 にする(AC-000-01)。
     * この処理を1か所にまとめ、各 Controller でチームの絞り込みを書かない。
     */
    private function bindTeamToMembership(): void
    {
        Route::bind('team', function (string $value): Team {
            $user = request()->user();
            if (! $user instanceof User || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) !== 1) {
                abort(404);
            }

            return Team::whereKey(strtolower($value)) // HasUlids は小文字で保存する
                ->whereHas('members', fn ($q) => $q->whereKey($user->id))
                ->firstOrFail();
        });
    }

    /**
     * 回数制限。メールアドレスはハッシュ値をキーにする(キャッシュに平文を残さない)。
     * 超えたら 429 を返し、メールは送らない(AC-001-05)。
     */
    private function configureRateLimiting(): void
    {
        $perEmail = (int) config('team_ops.login_link.per_hour_per_email');
        $perIp = (int) config('team_ops.login_link.per_hour_per_ip');
        $emailKey = function (Request $request, string $field): string {
            $email = $request->input($field);

            return is_string($email) && $email !== '' ? app(BlindIndex::class)->email($email) : 'none';
        };

        RateLimiter::for('login-link', fn (Request $request) => [
            Limit::perHour($perEmail)->by('login-link:email:'.$emailKey($request, 'email')),
            Limit::perHour($perIp)->by('login-link:ip:'.$request->ip()),
        ]);

        RateLimiter::for('email-change', fn (Request $request) => [
            Limit::perHour($perEmail)->by('email-change:user:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour($perEmail)->by('email-change:email:'.$emailKey($request, 'new_email')),
        ]);

        // トークンの総当たりを防ぐ(トークンは256ビットなので、主に負荷の対策)
        RateLimiter::for('token-consume', fn (Request $request) => Limit::perMinute(30)->by('token-consume:ip:'.$request->ip()));
    }
}
