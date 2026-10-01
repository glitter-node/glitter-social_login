<?php

namespace Plugins\Glitter\SocialLogin\Registration;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Enums\ConsentType;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Services\IdentityVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Plugins\Glitter\SocialLogin\Auth\SocialLoginEligibility;
use Plugins\Glitter\SocialLogin\Linking\SocialLinkingService;
use Plugins\Glitter\SocialLogin\Models\SocialLoginUserFlag;
use Plugins\Glitter\SocialLogin\Support\RedirectPath;

/**
 * OAuth identity가 아직 회원과 연결되지 않았을 때의 다단계 가입 흐름입니다.
 *
 * User는 동의·필수 입력·필요한 signup IDV가 끝날 때까지 생성하지 않습니다.
 * signup_after_create 정책이 활성화된 경우에는 challenge 결과를 pending
 * registration에 안전하게 결합할 public contract가 없으므로 User 생성을
 * fail closed 합니다.
 */
final class SocialRegistrationService
{
    private const PENDING_TTL_SECONDS = 600;

    private const LOCK_SECONDS = 10;

    private const CACHE_PREFIX = 'glitter-social-login:registration:';

    public const ERROR_INVALID = 'social_registration_invalid';

    public const ERROR_CONFLICT = 'social_registration_conflict';

    public const ERROR_IDV_REQUIRED = 'social_registration_idv_required';

    /**
     * @return array{registration_id: string}
     */
    public function createPending(
        string $provider,
        string $providerUserId,
        ?string $providerEmail,
        ?string $displayName,
        string $sessionBinding,
        string $redirectPath,
    ): array {
        if ($provider === '' || $providerUserId === '' || $sessionBinding === '') {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        $registrationId = Str::random(48);
        $stored = Cache::put($this->key($registrationId), [
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email' => $providerEmail,
            'display_name' => $displayName,
            'session_binding_hash' => hash('sha256', $sessionBinding),
            'redirect_path' => RedirectPath::sanitize($redirectPath),
            'state' => 'collecting',
            'fields' => null,
            'challenge_id' => null,
        ], now()->addSeconds(self::PENDING_TTL_SECONDS));

        if (! $stored) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        return ['registration_id' => $registrationId];
    }

    /**
     * 동의/필수 입력을 수집하고, 필요하면 signup IDV challenge를 발행합니다.
     *
     * @return array{status: string, challenge_id?: string, expires_at?: string, user?: User, pending_verification?: bool, redirect_path?: string}
     */
    public function submit(
        string $registrationId,
        string $sessionBinding,
        array $input,
    ): array {
        return $this->withPendingLock($registrationId, $sessionBinding, function (array $pending, string $key) use ($input): array {
            $fields = $this->normalizeFields($pending, $input);
            $policy = $this->signupBeforePolicy();

            if ($policy?->enabled) {
                $challenge = $this->identityVerification->start(
                    IdentityVerificationPurpose::Signup->value,
                    ['email' => $fields['email']],
                    [
                        'origin_type' => 'route',
                        'origin_identifier' => 'glitter-social_login.registration',
                        'origin_policy_key' => $policy->key,
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                    ],
                    $policy->provider_id,
                );

                $status = $this->identityVerification->getStatus($challenge->id);
                if ($status === null
                    || ($status['purpose'] ?? null) !== IdentityVerificationPurpose::Signup->value
                    || ! in_array($status['status'] ?? null, [
                        IdentityVerificationStatus::Requested->value,
                        IdentityVerificationStatus::Sent->value,
                        IdentityVerificationStatus::Processing->value,
                    ], true)
                ) {
                    throw new \RuntimeException(self::ERROR_IDV_REQUIRED);
                }

                $pending['state'] = 'awaiting_idv';
                $pending['fields'] = $fields;
                $pending['challenge_id'] = $challenge->id;
                $this->storePending($key, $pending, $challenge->expiresAt);

                return [
                    'status' => 'idv_required',
                    'challenge_id' => $challenge->id,
                    'expires_at' => $challenge->expiresAt->toIso8601String(),
                ];
            }

            $this->dispatchBeforeRegister($fields);
            $result = $this->provision($pending, $fields);
            Cache::forget($key);

            return $result;
        });
    }

    /**
     * signup purpose IDV를 검증한 뒤 최종 provisioning을 수행합니다.
     * verification_token은 메모리 안에서 policy enforce/consume에만 사용하고 저장하지 않습니다.
     *
     * @return array{status: string, user?: User, pending_verification?: bool, redirect_path?: string}
     */
    public function completeIdentityVerification(
        string $registrationId,
        string $sessionBinding,
        string $code,
    ): array {
        return $this->withPendingLock($registrationId, $sessionBinding, function (array $pending, string $key) use ($code): array {
            if (($pending['state'] ?? null) !== 'awaiting_idv'
                || ! is_string($pending['challenge_id'] ?? null)
                || ! is_array($pending['fields'] ?? null)
            ) {
                throw new \RuntimeException(self::ERROR_INVALID);
            }

            $challengeId = $pending['challenge_id'];
            $status = $this->identityVerification->getStatus($challengeId);
            if ($status === null
                || ($status['purpose'] ?? null) !== IdentityVerificationPurpose::Signup->value
                || ! in_array($status['status'] ?? null, [
                    IdentityVerificationStatus::Requested->value,
                    IdentityVerificationStatus::Sent->value,
                    IdentityVerificationStatus::Processing->value,
                ], true)
            ) {
                throw new \RuntimeException(self::ERROR_INVALID);
            }

            $verification = $this->identityVerification->verify(
                $challengeId,
                ['code' => $code],
                [
                    'origin_type' => 'route',
                    'origin_identifier' => 'glitter-social_login.registration',
                ],
            );

            if (! $verification->success) {
                throw new \RuntimeException(self::ERROR_INVALID);
            }

            $policy = $this->signupBeforePolicy();
            $verificationToken = $verification->claims['verification_token'] ?? null;
            if (! $policy?->enabled || ! is_string($verificationToken) || $verificationToken === '') {
                throw new \RuntimeException(self::ERROR_INVALID);
            }

            // Core와 같은 public policy service로 purpose/target을 재검증합니다.
            $this->identityPolicies->enforce($policy, null, [
                'signup_stage' => 'before_submit',
                'http_method' => 'POST',
                'target_email' => $pending['fields']['email'],
                'verification_token' => $verificationToken,
                'origin_type' => 'route',
                'origin_identifier' => 'glitter-social_login.registration',
            ]);
            $this->dispatchBeforeRegister($pending['fields'], $verificationToken);
            $this->identityVerification->consumeToken($verificationToken);

            $result = $this->provision($pending, $pending['fields']);
            Cache::forget($key);

            return $result;
        });
    }

    private function provision(array $pending, array $fields): array
    {
        // Phase 3 정책과 동일하게 신규 Social User + 2FA 환경은 fail closed입니다.
        $this->eligibility->assertCanCreateSocialUser();

        $afterPolicy = $this->signupAfterPolicy();
        // Core after_register listener는 challenge를 발행하지만 challenge ID를 가입
        // 응답에 돌려주지 않습니다. plugin이 그 challenge를 browser-bound pending
        // registration과 안전하게 연결할 public contract가 없으므로 User를 만들지 않습니다.
        if ($afterPolicy?->enabled) {
            throw new \RuntimeException(self::ERROR_IDV_REQUIRED);
        }

        $status = UserStatus::Active->value;
        $now = now();

        try {
            $result = DB::transaction(function () use ($pending, $fields, $status, $now): array {
                $existingIdentity = $this->linking->findIdentityForUpdate(
                    $pending['provider'],
                    $pending['provider_user_id'],
                );
                if ($existingIdentity) {
                    throw new \RuntimeException(self::ERROR_CONFLICT);
                }

                $matchedUser = User::query()
                    ->where('email', $fields['email'])
                    ->lockForUpdate()
                    ->first();

                if ($matchedUser) {
                    // Provider verified email + G7 verified email일 때만 기존 계정에 연결합니다.
                    if ($pending['provider_email'] !== null && $matchedUser->email_verified_at !== null) {
                        $this->eligibility->assertBaseCanAuthenticate($matchedUser);
                        $this->linking->attachWithinTransaction(
                            $matchedUser,
                            $pending['provider'],
                            $pending['provider_user_id'],
                            $pending['provider_email'],
                        );

                        return [
                            'status' => 'provisioned',
                            'user' => $matchedUser,
                            'pending_verification' => false,
                            'auto_linked' => true,
                            'redirect_path' => RedirectPath::sanitize($pending['redirect_path'] ?? null),
                        ];
                    }

                    throw new \RuntimeException(self::ERROR_CONFLICT);
                }

                $user = User::create([
                    'name' => $fields['name'],
                    'nickname' => $fields['nickname'],
                    'email' => $fields['email'],
                    // DB의 password 컬럼은 채우되 SocialLoginUserFlag가 실제 password
                    // 보유 여부의 authoritative plugin state로 남습니다.
                    'password' => Hash::make(Str::random(40)),
                    'language' => $fields['language'],
                    'status' => $status,
                ]);

                SocialLoginUserFlag::create([
                    'user_id' => $user->id,
                    'has_real_password' => false,
                ]);

                $role = $this->roles->findByIdentifier('user');
                if ($role) {
                    $user->roles()->sync([$role->id]);
                }

                $this->linking->attachWithinTransaction(
                    $user,
                    $pending['provider'],
                    $pending['provider_user_id'],
                    $pending['provider_email'],
                );

                foreach ([ConsentType::Terms->value, ConsentType::Privacy->value] as $type) {
                    $this->consents->record([
                        'user_id' => $user->id,
                        'consent_type' => $type,
                        'agreed_at' => $now,
                        'ip_address' => request()->ip(),
                    ]);
                }

                return [
                    'status' => 'provisioned',
                    'user' => $user,
                    'pending_verification' => false,
                    'auto_linked' => false,
                    'redirect_path' => RedirectPath::sanitize($pending['redirect_path'] ?? null),
                    'registration_data' => [
                        'name' => $fields['name'],
                        'nickname' => $fields['nickname'],
                        'email' => $fields['email'],
                        'agree_terms' => true,
                        'agree_privacy' => true,
                        'language' => $fields['language'],
                    ],
                ];
            });
        } catch (QueryException $e) {
            if (! $this->linking->isProviderIdentityUniqueViolation($e)) {
                throw $e;
            }

            $winner = $this->linking->findIdentity($pending['provider'], $pending['provider_user_id']);
            $user = $winner?->user;
            if (! $user) {
                throw new \RuntimeException(self::ERROR_CONFLICT);
            }

            $this->eligibility->assertBaseCanAuthenticate($user);

            $result = [
                'status' => 'provisioned',
                'user' => $user,
                'pending_verification' => false,
                'auto_linked' => false,
                'redirect_path' => RedirectPath::sanitize($pending['redirect_path'] ?? null),
            ];
        }

        $result['user']->flushPermissionCaches();

        $result['provider'] = $pending['provider'];
        $result['provider_user_id'] = $pending['provider_user_id'];

        if (($result['auto_linked'] ?? false) !== true && isset($result['registration_data'])) {
            HookManager::doAction('core.auth.record_consents', $result['user'], $result['registration_data'], $now->toIso8601String(), request()->ip());
            HookManager::doAction('core.auth.after_register', $result['user'], [
                'registration_time' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'signup_stage' => 'after_create',
                'registration_data' => $result['registration_data'],
            ]);
        }

        unset($result['registration_data'], $result['auto_linked']);

        return $result;
    }

    private function normalizeFields(array $pending, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $nickname = trim((string) ($input['nickname'] ?? ''));
        $rawEmail = trim((string) ($input['email'] ?? ''));
        $email = strtolower($rawEmail !== '' ? $rawEmail : (string) ($pending['provider_email'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255
            || ($nickname !== '' && mb_strlen($nickname) > 50)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($email) > 255
            || ! $this->accepted($input['agree_terms'] ?? null)
            || ! $this->accepted($input['agree_privacy'] ?? null)
        ) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        if ($pending['provider_email'] !== null && $email !== strtolower($pending['provider_email'])) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        return [
            'name' => $name,
            'nickname' => $nickname !== '' ? $nickname : null,
            'email' => $email,
            'language' => in_array(($input['language'] ?? 'ko'), config('app.supported_locales', ['ko', 'en']), true)
                ? (string) $input['language']
                : 'ko',
        ];
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private function dispatchBeforeRegister(array $fields, ?string $verificationToken = null): void
    {
        $data = [
            'name' => $fields['name'],
            'nickname' => $fields['nickname'],
            'email' => $fields['email'],
            'language' => $fields['language'],
            'agree_terms' => 'accepted',
            'agree_privacy' => 'accepted',
        ];

        if ($verificationToken !== null) {
            $data['verification_token'] = $verificationToken;
        }

        HookManager::doAction('core.auth.before_register', $data, [
            'signup_stage' => 'before_submit',
            'http_method' => 'POST',
            'origin' => 'glitter-social_login',
        ]);
    }

    private function signupBeforePolicy(): ?\App\Models\IdentityPolicy
    {
        return $this->identityPolicies->resolve(
            'route',
            'api.auth.register',
            ['signup_stage' => 'before_submit', 'http_method' => 'POST'],
        );
    }

    private function signupAfterPolicy(): ?\App\Models\IdentityPolicy
    {
        return $this->identityPolicies->resolve(
            'hook',
            'core.auth.after_register',
            ['signup_stage' => 'after_create'],
        );
    }

    private function withPendingLock(string $registrationId, string $sessionBinding, callable $callback): array
    {
        if ($registrationId === '' || $sessionBinding === '') {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        $key = $this->key($registrationId);
        $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }

        try {
            $pending = Cache::get($key);
            if (! is_array($pending)
                || ! is_string($pending['session_binding_hash'] ?? null)
                || ! hash_equals($pending['session_binding_hash'], hash('sha256', $sessionBinding))
            ) {
                throw new \RuntimeException(self::ERROR_INVALID);
            }

            return $callback($pending, $key);
        } finally {
            $lock->release();
        }
    }

    private function storePending(string $key, array $pending, \DateTimeInterface $expiresAt): void
    {
        if (! Cache::put($key, $pending, $expiresAt)) {
            throw new \RuntimeException(self::ERROR_INVALID);
        }
    }

    private function key(string $registrationId): string
    {
        return self::CACHE_PREFIX.hash('sha256', $registrationId);
    }

    public function __construct(
        private readonly IdentityVerificationService $identityVerification,
        private readonly IdentityPolicyService $identityPolicies,
        private readonly SocialLoginEligibility $eligibility,
        private readonly SocialLinkingService $linking,
        private readonly RoleRepositoryInterface $roles,
        private readonly UserConsentRepositoryInterface $consents,
    ) {}
}
