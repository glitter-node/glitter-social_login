<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Services\PluginSettingsService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GithubProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Auth\SocialLoginEligibility;
use Plugins\Glitter\SocialLogin\Auth\SocialAuthCompletion;
use Plugins\Glitter\SocialLogin\Exchange\BoundExchangeService;
use Plugins\Glitter\SocialLogin\Linking\SocialLinkingService;
use Plugins\Glitter\SocialLogin\Registration\SocialRegistrationService;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use RuntimeException;

final class SocialAuthServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('request', Request::create('/login', 'GET'));
        Container::setInstance($container);
    }

    public function test_facebook_is_registered_in_canonical_order_and_uses_builtin_provider(): void
    {
        self::assertSame(['naver', 'kakao', 'google', 'facebook', 'github'], SocialAuthService::PROVIDERS);

        $provider = $this->service([
            'facebook_enabled' => true,
            'facebook_client_id' => 'app-id',
            'facebook_client_secret' => 'app-secret',
        ])->driverFor('facebook');

        self::assertInstanceOf(FacebookProvider::class, $provider);
    }

    public function test_github_uses_the_bundled_builtin_provider(): void
    {
        $provider = $this->service([
            'github_enabled' => true,
            'github_client_id' => 'client-id',
            'github_client_secret' => 'client-secret',
        ])->driverFor('github');

        self::assertInstanceOf(GithubProvider::class, $provider);
    }

    public function test_disabled_github_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider_disabled:github');

        $this->service(['github_enabled' => false])->driverFor('github');
    }

    public function test_missing_github_configuration_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider_not_configured:github');

        $this->service(['github_enabled' => true])->driverFor('github');
    }

    public function test_generic_routes_use_the_canonical_provider_registry(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/routes/api.php');

        self::assertIsString($source);
        self::assertStringContainsString("->whereIn('provider', SocialAuthService::PROVIDERS)", $source);
    }

    public function test_disabled_facebook_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider_disabled:facebook');

        $this->service(['facebook_enabled' => false])->driverFor('facebook');
    }

    public function test_missing_facebook_configuration_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider_not_configured:facebook');

        $this->service(['facebook_enabled' => true])->driverFor('facebook');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function service(array $values): SocialAuthService
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturnCallback(
            static function (string $plugin, string $key, mixed $default = null) use ($values): mixed {
                return $values[$key] ?? $default;
            },
        );

        return new SocialAuthService(
            $settings,
            $this->createMock(RoleRepositoryInterface::class),
            $this->createMock(SocialLoginEligibility::class),
            $this->createMock(SocialLinkingService::class),
            $this->createMock(SocialAuthCompletion::class),
            $this->createMock(SocialRegistrationService::class),
            $this->createMock(BoundExchangeService::class),
        );
    }
}
