<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Support\Crypto\BlindIndex;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\RelayClient;
use App\Support\Queue\RedactingFailedJobProvider;
use App\Support\Security\ProductionSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Middleware\TrustProxies;
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
            function (Application $app) {
                ProductionSettings::refusePublishedKey('BLIND_INDEX_KEY', config('team_ops.blind_index_key'), (string) $app->environment());

                return BlindIndex::fromConfig(config('team_ops.blind_index_key'));
            },
        );

        // 暗号化(Cookie、暗号化した項目)を初めて使うときに、公開済みのローカル用の鍵でないか確かめる
        $this->app->extend('encrypter', function (mixed $encrypter, Application $app) {
            ProductionSettings::refusePublishedKey('APP_KEY', config('app.key'), (string) $app->environment());

            return $encrypter;
        });

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
            fn () => new EmailOutbox((bool) config('team_ops.invalid_recipients_only')),
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

        // 回数制限で利用者のIPを数えるため、信頼するプロキシだけから X-Forwarded-For を受け取る。
        // ホスト名・ポート・パスの X-Forwarded-* は信じない(将来 url() で作るリンクを書き換えられないように)
        $trustedProxies = (array) config('team_ops.trusted_proxies');
        TrustProxies::at($trustedProxies);
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        if (! $this->app->runningInConsole()) {
            ProductionSettings::requireTrustedProxies($trustedProxies, (string) $this->app->environment());
        }

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

            // 所属しているチームだけを、そのチームでの役割(membership)付きで探す。HasUlids は小文字で保存する
            return $user->teams()->whereKey(strtolower($value))->firstOrFail();
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
