<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Services\PluginSettingsService;
use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Auth\SocialLoginEligibility;
use Plugins\Glitter\SocialLogin\Auth\SocialAuthCompletion;
use Plugins\Glitter\SocialLogin\Exchange\BoundExchangeService;
use Plugins\Glitter\SocialLogin\Linking\SocialLinkingService;
use Plugins\Glitter\SocialLogin\Registration\SocialRegistrationService;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use SocialiteProviders\Manager\OAuth2\User;

final class SocialAuthServiceEmailPolicyTest extends TestCase
{
    public function test_valid_authenticated_naver_profile_email_is_eligible(): void
    {
        $raw = $this->naverRaw('user@example.test');

        self::assertSame('user@example.test', $this->service()->providerVerifiedEmail('naver', $this->socialUser($raw)));
    }

    public function test_missing_or_invalid_naver_email_is_not_eligible(): void
    {
        self::assertNull($this->service()->providerVerifiedEmail('naver', $this->socialUser($this->naverRaw(null))));
        self::assertNull($this->service()->providerVerifiedEmail('naver', $this->socialUser($this->naverRaw('not-an-email'))));
    }

    public function test_naver_email_must_match_the_authenticated_nested_profile(): void
    {
        $raw = $this->naverRaw('profile@example.test');
        $user = $this->socialUser($raw, 'request@example.test');

        self::assertNull($this->service()->providerVerifiedEmail('naver', $user));
    }

    public function test_google_and_kakao_email_policies_remain_provider_specific(): void
    {
        $google = $this->socialUser(['email_verified' => true], 'google@example.test');
        $kakao = $this->socialUser([
            'kakao_account' => [
                'is_email_valid' => true,
                'is_email_verified' => true,
            ],
        ], 'kakao@example.test');

        self::assertSame('google@example.test', $this->service()->providerVerifiedEmail('google', $google));
        self::assertSame('kakao@example.test', $this->service()->providerVerifiedEmail('kakao', $kakao));
    }

    public function test_facebook_email_is_not_eligible_for_automatic_existing_account_matching(): void
    {
        $facebook = $this->socialUser([
            'id' => 'facebook-id',
            'name' => 'Facebook User',
            'email' => 'facebook@example.test',
            'verified' => true,
        ], 'facebook@example.test');

        self::assertNull($this->service()->providerVerifiedEmail('facebook', $facebook));
    }

    public function test_github_primary_verified_email_is_eligible_from_socialite_mapping(): void
    {
        $github = $this->socialUser([
            'id' => 12345,
            'login' => 'octocat',
            'email' => 'github@example.test',
        ], 'github@example.test');

        self::assertSame('github@example.test', $this->service()->providerVerifiedEmail('github', $github));
    }

    public function test_github_missing_or_malformed_email_is_not_eligible(): void
    {
        self::assertNull($this->service()->providerVerifiedEmail('github', $this->socialUser(['email' => null], null)));
        self::assertNull($this->service()->providerVerifiedEmail('github', $this->socialUser(['email' => 'not-an-email'], 'not-an-email')));
    }

    public function test_provider_id_lookup_precedes_eligible_email_matching(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Services/SocialAuthService.php');

        self::assertIsString($source);
        self::assertLessThan(
            strpos($source, 'if ($email)'),
            strpos($source, "SocialAccount::where('provider', $provider)"),
        );
    }

    private function service(): SocialAuthService
    {
        return new SocialAuthService(
            $this->createMock(PluginSettingsService::class),
            $this->createMock(RoleRepositoryInterface::class),
            $this->createMock(SocialLoginEligibility::class),
            $this->createMock(SocialLinkingService::class),
            $this->createMock(SocialAuthCompletion::class),
            $this->createMock(SocialRegistrationService::class),
            $this->createMock(BoundExchangeService::class),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function socialUser(array $raw, ?string $email = null): User
    {
        return (new User())->setRaw($raw)->map([
            'id' => 'provider-id',
            'email' => $email ?? ($raw['response']['email'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function naverRaw(?string $email): array
    {
        return [
            'resultcode' => '00',
            'response' => [
                'id' => 'opaque-naver-id',
                'email' => $email,
            ],
        ];
    }
}
