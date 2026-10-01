<?php

namespace Plugins\Glitter\SocialLogin\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Glitter\SocialLogin\Support\SocialLoginRateLimiters;

/**
 * glitter-social_login 서비스 프로바이더.
 *
 * 코어 `PluginServiceProvider` 가 `plugins/<id>/src/Providers/*ServiceProvider.php` 를
 * 자동 발견해 등록한다. 라우트(`src/routes/api.php`)·마이그레이션·훅 리스너는 규약대로
 * 자동이다. 여기서는 라우트가 쓰는 이름 있는 제한기만 등록한다.
 *
 * `bootstrap/providers.php` 에서 `PluginServiceProvider` 가 `PluginRouteServiceProvider`
 * 보다 앞에 있어, 이 `boot()` 는 라우트 등록보다 먼저 실행된다.
 */
class SocialLoginServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'glitter-social_login';

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        parent::boot();

        SocialLoginRateLimiters::register();
    }
}
