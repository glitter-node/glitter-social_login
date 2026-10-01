<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Http\Controllers\SocialAuthController;
use ReflectionMethod;

final class SocialAuthControllerBindingTest extends TestCase
{
    public function test_session_binding_is_used_when_session_and_cookie_match(): void
    {
        $binding = 'session-binding';
        $request = $this->request($binding, $binding);

        self::assertSame($binding, $this->resolve($request));
    }

    public function test_cookie_binding_bridges_a_missing_session_value(): void
    {
        $binding = 'cookie-binding';
        $request = $this->request(null, $binding);

        self::assertSame($binding, $this->resolve($request));
    }

    public function test_mismatched_session_and_cookie_bindings_fail_closed(): void
    {
        $request = $this->request('session-binding', 'different-cookie-binding');

        self::assertNull($this->resolve($request));
    }

    private function request(?string $sessionBinding, ?string $cookieBinding): Request
    {
        $request = Request::create('/api/plugins/glitter-social_login/exchange', 'POST');
        $session = new Store('test', new ArraySessionHandler(300));
        $session->start();

        if ($sessionBinding !== null) {
            $session->put('g7sl.exchange_binding', $sessionBinding);
        }

        $request->setLaravelSession($session);

        if ($cookieBinding !== null) {
            $request->cookies->set('g7sl_exchange_binding', $cookieBinding);
        }

        return $request;
    }

    private function resolve(Request $request): ?string
    {
        $controller = (new \ReflectionClass(SocialAuthController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SocialAuthController::class, 'resolveExchangeBinding');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }
}
