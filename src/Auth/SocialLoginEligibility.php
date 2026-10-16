<?php

namespace Plugins\Glitter\SocialLogin\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Social Login 이 최종 인증 단계로 진행할 수 있는지 판단합니다.
 *
 * Core 의 private AuthService 메서드를 호출하거나 복제하지 않고, 공개 User
 * status/repository contract 와 Core 가 사용하는 2FA 설정 조건만 적용합니다.
 */
final class SocialLoginEligibility
{
    public const ERROR_NOT_ELIGIBLE = 'social_login_not_eligible';

    public const ERROR_TWO_FACTOR_REQUIRED = 'social_login_two_factor_required';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function assertCanAuthenticate(User $user): void
    {
        $this->assertBaseCanAuthenticate($user);

        if ($this->requiresAdditionalAuthentication($user)) {
            throw new \RuntimeException(self::ERROR_TWO_FACTOR_REQUIRED);
        }
    }

    /**
     * Status와 administrative lock만 검사합니다.
     *
     * OAuth가 첫 번째 인증 요소로 성공한 뒤 추가 IDV가 필요한 흐름에서는
     * 이 검사를 통과한 사용자를 pending authentication으로 보낼 수 있습니다.
     */
    public function assertBaseCanAuthenticate(User $user): void
    {
        if ($user->status !== UserStatus::Active->value) {
            throw new \RuntimeException(self::ERROR_NOT_ELIGIBLE);
        }

        if ($this->userRepository->isLocked($user)) {
            throw new \RuntimeException(self::ERROR_NOT_ELIGIBLE);
        }
    }

    public function requiresAdditionalAuthentication(User $user): bool
    {
        return $this->isTwoFactorRequired($user);
    }

    /**
     * Link/unlink는 이미 인증된 사용자 작업이므로 2FA completion을 여기서
     * 요구하지 않는다. 다만 callback round-trip 중 계정이 비활성/잠금 상태가
     * 되었다면 DB identity 변경은 허용하지 않는다.
     */
    public function assertCanLink(User $user): void
    {
        if ($user->status !== UserStatus::Active->value || $this->userRepository->isLocked($user)) {
            throw new \RuntimeException(SocialLoginEligibility::ERROR_NOT_ELIGIBLE);
        }
    }

    /**
     * 신규 Social User 를 만들기 전에 Core 와 같은 2FA 조건을 확인합니다.
     * 이번 Phase 에서는 plugin-owned 2FA completion 을 구현하지 않으므로,
     * 조건이 맞으면 User 생성도 진행하지 않고 fail closed 합니다.
     */
    public function assertCanCreateSocialUser(): void
    {
        // Social signup 은 이 Phase 에서 2FA completion 경로를 제공하지 않는다.
        // 실제 생성 시에는 email 이 없을 때도 placeholder email 을 부여하므로,
        // 2FA 가 켜진 환경에서는 신규 User 생성 자체를 진행하지 않는다.
        if ((bool) g7_core_settings('security.two_factor_auth', false)) {
            throw new \RuntimeException(self::ERROR_TWO_FACTOR_REQUIRED);
        }
    }

    private function isTwoFactorRequired(User $user): bool
    {
        return (bool) g7_core_settings('security.two_factor_auth', false)
            && filled($user->email);
    }
}
