<?php

namespace Plugins\Glitter\SocialLogin\Auth;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Social OAuth 첫 번째 인증과 G7 Login-purpose IDV를 연결합니다.
 *
 * pending record에는 OAuth credential이나 raw provider 응답을 저장하지 않고,
 * 짧은 수명의 opaque id와 User/challenge/session binding만 저장합니다.
 */
final class SocialTwoFactorService
{
    private const PENDING_TTL_SECONDS = 300;

    private const LOCK_SECONDS = 10;

    private const CACHE_PREFIX = 'glitter-social-login:two-factor:';

    public const ERROR_INVALID = 'social_login_two_factor_invalid';

    public function __construct(
        private readonly IdentityVerificationService $identityVerification,
        private readonly SocialLoginEligibility $eligibility,
    ) {}

    /**
     * @return array{pending_id: string, challenge_id: string, provider_id: string, expires_at: string}
     */
    public function start(
        User $user,
        string $provider,
        string $providerUserId,
        string $sessionBinding,
        string $redirectPath,
    ): array {
        if ($sessionBinding === '' || $provider === '' || $providerUserId === '') {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        $this->eligibility->assertBaseCanAuthenticate($user);

        if (! $this->eligibility->requiresAdditionalAuthentication($user)) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        $challenge = $this->identityVerification->start(
            IdentityVerificationPurpose::Login->value,
            $user,
            [
                'origin_type' => 'route',
                'origin_identifier' => 'glitter-social_login.2fa',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        );

        $status = $this->identityVerification->getStatus($challenge->id);
        if ($status === null
            || ($status['purpose'] ?? null) !== IdentityVerificationPurpose::Login->value
            || ! in_array($status['status'] ?? null, [
                IdentityVerificationStatus::Requested->value,
                IdentityVerificationStatus::Sent->value,
                IdentityVerificationStatus::Processing->value,
            ], true)
        ) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        $pendingId = Str::random(48);
        $expiresIn = min(
            self::PENDING_TTL_SECONDS,
            max(1, now()->diffInSeconds($challenge->expiresAt, false)),
        );

        $stored = Cache::put($this->key($pendingId), [
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id_hash' => $this->identityHash($provider, $providerUserId),
            'session_binding_hash' => hash('sha256', $sessionBinding),
            'challenge_id' => $challenge->id,
            'redirect_path' => $redirectPath,
        ], now()->addSeconds($expiresIn));

        if (! $stored) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        return [
            'pending_id' => $pendingId,
            'challenge_id' => $challenge->id,
            'provider_id' => $challenge->providerId,
            'expires_at' => $challenge->expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{user: User, redirect_path: string}|null
     */
    public function complete(string $pendingId, string $sessionBinding, string $code): ?array
    {
        if ($pendingId === '' || $sessionBinding === '' || $code === '') {
            return null;
        }

        $key = $this->key($pendingId);
        $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $pending = Cache::get($key);
            if (! is_array($pending) || ! $this->bindingMatches($pending, $sessionBinding)) {
                return null;
            }

            if (! is_numeric($pending['user_id'] ?? null)
                || ! is_string($pending['provider'] ?? null)
                || $pending['provider'] === ''
                || ! is_string($pending['provider_user_id_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $pending['provider_user_id_hash'])
            ) {
                Cache::forget($key);
                return null;
            }

            $challengeId = $pending['challenge_id'] ?? null;
            if (! is_string($challengeId) || $challengeId === '') {
                Cache::forget($key);
                return null;
            }

            $status = $this->identityVerification->getStatus($challengeId);
            if ($status === null
                || ($status['purpose'] ?? null) !== IdentityVerificationPurpose::Login->value
                || ! in_array($status['status'] ?? null, [
                    IdentityVerificationStatus::Requested->value,
                    IdentityVerificationStatus::Sent->value,
                    IdentityVerificationStatus::Processing->value,
                ], true)
            ) {
                Cache::forget($key);
                return null;
            }

            $result = $this->identityVerification->verify(
                $challengeId,
                ['code' => $code],
                [
                    'origin_type' => 'route',
                    'origin_identifier' => 'glitter-social_login.2fa',
                ],
            );

            if (! $result->success) {
                return null;
            }

            $verifiedUser = $this->identityVerification->resolveVerifiedUser(
                $challengeId,
                IdentityVerificationPurpose::Login->value,
            );

            if (! $verifiedUser || (int) $verifiedUser->getKey() !== (int) ($pending['user_id'] ?? 0)) {
                Cache::forget($key);
                return null;
            }

            // Challenge 완료와 최종 인증 사이에도 status/lock은 다시 검사합니다.
            $this->eligibility->assertBaseCanAuthenticate($verifiedUser);

            // pending은 exchange 발급 전에 소비합니다. 프로세스가 중단되더라도 같은
            // IDV 결과로 pending을 재사용하여 exchange를 여러 번 만들 수 없게 합니다.
            Cache::forget($key);

            return [
                'user' => $verifiedUser,
                'redirect_path' => (string) ($pending['redirect_path'] ?? '/'),
            ];
        } catch (\Throwable) {
            // provider/IDV 내부 오류와 challenge 세부정보를 외부에 노출하지 않습니다.
            return null;
        } finally {
            $lock->release();
        }
    }

    private function key(string $pendingId): string
    {
        return self::CACHE_PREFIX.hash('sha256', $pendingId);
    }

    private function bindingMatches(array $pending, string $sessionBinding): bool
    {
        $expected = $pending['session_binding_hash'] ?? null;

        return is_string($expected)
            && hash_equals($expected, hash('sha256', $sessionBinding));
    }

    private function identityHash(string $provider, string $providerUserId): string
    {
        return hash('sha256', $provider."\0".$providerUserId);
    }
}
