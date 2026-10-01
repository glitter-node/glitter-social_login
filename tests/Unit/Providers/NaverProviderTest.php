<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Providers\NaverProvider;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use ReflectionMethod;
use RuntimeException;

final class NaverProviderTest extends TestCase
{
    public function test_naver_is_the_first_canonical_provider(): void
    {
        self::assertSame(['naver', 'kakao', 'google', 'facebook', 'github'], SocialAuthService::PROVIDERS);
    }

    public function test_authorization_url_contains_naver_oauth_fields(): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $method = new ReflectionMethod($provider, 'getAuthUrl');
        $method->setAccessible(true);

        parse_str((string) parse_url($method->invoke($provider, 'state-value'), PHP_URL_QUERY), $query);

        self::assertSame('https://nid.naver.com/oauth2.0/authorize', strtok($method->invoke($provider, 'state-value'), '?'));
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('https://example.test/callback', $query['redirect_uri']);
        self::assertSame('state-value', $query['state']);
    }

    public function test_token_request_contains_exact_naver_fields_and_callback_state(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'access-token',
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));

        $request = Request::create('/callback', 'GET', ['state' => 'callback-state']);
        $provider = $this->provider($request);
        $provider->setHttpClient(new Client(['handler' => $stack]));

        $provider->getAccessTokenResponse('authorization-code');

        parse_str((string) $history[0]['request']->getBody(), $fields);

        self::assertSame('authorization_code', $fields['grant_type']);
        self::assertSame('client-id', $fields['client_id']);
        self::assertSame('client-secret', $fields['client_secret']);
        self::assertSame('authorization-code', $fields['code']);
        self::assertSame('callback-state', $fields['state']);
        self::assertArrayNotHasKey('redirect_uri', $fields);
    }

    public function test_profile_request_validates_naver_response_and_uses_bearer_token(): void
    {
        $history = [];
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'resultcode' => '00',
                'message' => 'success',
                'response' => [
                    'id' => 'opaque-naver-id',
                    'email' => 'user@example.test',
                    'nickname' => 'NaverNick',
                    'name' => 'Naver Name',
                    'profile_image' => 'https://example.test/avatar.png',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $provider = $this->provider(Request::create('/callback', 'GET'));
        $provider->setHttpClient(new Client(['handler' => $stack]));
        $method = new ReflectionMethod($provider, 'getUserByToken');
        $method->setAccessible(true);

        $payload = $method->invoke($provider, 'access-token');

        self::assertSame('opaque-naver-id', $payload['response']['id']);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('/v1/nid/me', $history[0]['request']->getUri()->getPath());
        self::assertSame('Bearer access-token', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_unsuccessful_resultcode_is_rejected(): void
    {
        $this->assertProfileRejected([
            'resultcode' => '024',
            'response' => ['id' => 'opaque-naver-id'],
        ]);
    }

    public function test_missing_response_is_rejected(): void
    {
        $this->assertProfileRejected(['resultcode' => '00']);
    }

    public function test_missing_empty_and_non_string_ids_are_rejected(): void
    {
        foreach ([
            ['resultcode' => '00', 'response' => []],
            ['resultcode' => '00', 'response' => ['id' => '   ']],
            ['resultcode' => '00', 'response' => ['id' => 12345]],
        ] as $payload) {
            $this->assertProfileRejected($payload);
        }
    }

    public function test_invalid_json_is_rejected(): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $provider->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], '{invalid-json'),
            ])),
        ]));

        $this->assertProfileRequestFailure($provider);
    }

    public function test_profile_is_normalized_from_nested_response_without_optional_fields(): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $method = new ReflectionMethod($provider, 'mapUserToObject');
        $method->setAccessible(true);

        $user = $method->invoke($provider, [
            'resultcode' => '00',
            'response' => [
                'id' => 'opaque-naver-id',
                'nickname' => 'NaverNick',
            ],
        ]);

        self::assertSame('opaque-naver-id', $user->getId());
        self::assertNull($user->getEmail());
        self::assertSame('NaverNick', $user->getName());
        self::assertSame('NaverNick', $user->getNickname());
        self::assertNull($user->getAvatar());
    }

    public function test_malformed_optional_email_is_normalized_to_null(): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $method = new ReflectionMethod($provider, 'mapUserToObject');
        $method->setAccessible(true);

        $user = $method->invoke($provider, [
            'resultcode' => '00',
            'response' => [
                'id' => 'opaque-naver-id',
                'email' => 'not-an-email',
                'name' => 'Naver Name',
                'profile_image' => 'https://example.test/avatar.png',
            ],
        ]);

        self::assertNull($user->getEmail());
        self::assertSame('Naver Name', $user->getName());
        self::assertSame('https://example.test/avatar.png', $user->getAvatar());
    }

    public function test_invalid_profile_response_uses_a_safe_message(): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $method = new ReflectionMethod($provider, 'mapUserToObject');
        $method->setAccessible(true);

        try {
            $method->invoke($provider, ['resultcode' => '00', 'response' => []]);
            self::fail('Expected invalid NAVER profile to throw.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            self::assertSame('naver_profile_response_invalid', $exception->getMessage());
        }
    }

    private function provider(Request $request): NaverProvider
    {
        return new NaverProvider(
            $request,
            'client-id',
            'client-secret',
            'https://example.test/callback',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertProfileRejected(array $payload): void
    {
        $provider = $this->provider(Request::create('/callback', 'GET'));
        $provider->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
            ])),
        ]));

        $this->assertProfileRequestFailure($provider);
    }

    private function assertProfileRequestFailure(NaverProvider $provider): void
    {
        $method = new ReflectionMethod($provider, 'getUserByToken');
        $method->setAccessible(true);

        try {
            $method->invoke($provider, 'access-token');
            self::fail('Expected invalid NAVER profile to throw.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            self::assertSame('naver_profile_response_invalid', $exception->getMessage());
        }
    }
}
