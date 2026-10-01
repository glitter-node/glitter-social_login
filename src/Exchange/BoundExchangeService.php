<?php

namespace Plugins\Glitter\SocialLogin\Exchange;

use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Glitter\SocialLogin\Models\SocialLoginExchange;
use Plugins\Glitter\SocialLogin\Support\RedirectPath;

/**
 * 최종 인증 교환코드의 DB-backed one-time 경계입니다.
 *
 * 평문 code와 browser binding은 저장하지 않습니다. consume은 조회 후 삭제가
 * 아니라 조건부 UPDATE 한 번으로 수행하여 동시 요청 중 하나만 승리하게 합니다.
 */
final class BoundExchangeService
{
    private const TTL_SECONDS = 60;

    private const CLEANUP_LIMIT = 50;

    public function issue(User $user, string $sessionBinding, string $redirectPath): string
    {
        if ($sessionBinding === '') {
            throw new \RuntimeException('social_login_exchange_binding_missing');
        }

        $this->cleanupExpired();

        $code = Str::random(48);
        SocialLoginExchange::create([
            'code_hash' => $this->hash($code),
            'user_id' => $user->getKey(),
            'session_binding_hash' => $this->hash($sessionBinding),
            'redirect_path' => RedirectPath::sanitize($redirectPath),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'consumed_at' => null,
        ]);

        return $code;
    }

    /**
     * Consume-on-attempt semantics입니다. User eligibility 또는 token 생성이
     * 뒤에서 실패해도 code를 되살리지 않아 credential replay를 허용하지 않습니다.
     */
    public function consume(string $code, string $sessionBinding): ?SocialLoginExchange
    {
        if ($code === '' || $sessionBinding === '') {
            return null;
        }

        $codeHash = $this->hash($code);
        $now = now();
        $affected = SocialLoginExchange::query()
            ->where('code_hash', $codeHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', $now)
            ->where('session_binding_hash', $this->hash($sessionBinding))
            ->update([
                'consumed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            return null;
        }

        return SocialLoginExchange::query()
            ->where('code_hash', $codeHash)
            ->whereNotNull('consumed_at')
            ->first();
    }

    private function cleanupExpired(): void
    {
        $ids = SocialLoginExchange::query()
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->limit(self::CLEANUP_LIMIT)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            SocialLoginExchange::query()->whereIn('id', $ids)->delete();
        }
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
