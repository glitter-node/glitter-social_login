<?php

namespace Plugins\Glitter\SocialLogin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Plugins\Glitter\SocialLogin\Auth\SocialTwoFactorService;
use Plugins\Glitter\SocialLogin\Auth\SocialLoginEligibility;
use Plugins\Glitter\SocialLogin\Linking\SocialLinkingService;
use Plugins\Glitter\SocialLogin\Models\SocialAccount;
use Plugins\Glitter\SocialLogin\Registration\SocialRegistrationService;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use Plugins\Glitter\SocialLogin\Support\RedirectPath;

class SocialAuthController extends Controller
{
    private const SESSION_REDIRECT = 'g7sl.redirect';

    private const SESSION_LINK_NONCE = 'g7sl.link_nonce';

    private const SESSION_EXCHANGE_BINDING = 'g7sl.exchange_binding';

    private const COOKIE_EXCHANGE_BINDING = 'g7sl_exchange_binding';

    public function __construct(
        private readonly SocialAuthService $service,
        private readonly ?SocialTwoFactorService $twoFactor = null,
        private readonly ?SocialRegistrationService $registration = null,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        try {
            $driver = $this->service->driverFor($provider);
        } catch (\RuntimeException $e) {
            return $this->frontendLoginError('provider_unavailable');
        } catch (\Throwable $e) {
            // provider exception message에는 authorization code/token 등이 포함될 수
            // 있으므로 class/action/provider만 기록한다.
            Log::error('glitter-social_login: OAuth provider 조립 실패', [
                'provider' => $provider,
                'exception' => get_class($e),
                'action' => 'redirect',
            ]);

            return $this->frontendLoginError('provider_unavailable');
        }

        $request->session()->put(self::SESSION_REDIRECT, RedirectPath::sanitize($request->query('redirect')));
        $binding = Str::random(64);
        $request->session()->put(self::SESSION_EXCHANGE_BINDING, $binding);

        // 연동 대상 회원은 쿼리에서 읽지 않는다. `linkPrepare`(auth:sanctum)가 이미 세션에
        // 심어둔 값만 쓴다 — 쿼리로 받으면 남이 만든 링크로 대상을 지정할 수 있게 된다.
        // 세션에 값이 없으면 그대로 로그인 흐름이다.

        // OAuth 왕복 중 session ID가 재발급되거나 session cookie가 재설정되어도
        // 같은 브라우저의 binding을 보존한다. 값 자체는 Laravel encrypted,
        // HttpOnly cookie로 전달하고 DB에는 기존처럼 hash만 저장한다.
        return $driver->redirect()->withCookie($this->exchangeBindingCookie($binding));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        // 진입 시 이미 검증했지만 세션 값을 그대로 믿지 않고 콜백에서도 다시 검증한다.
        $redirectAfter = RedirectPath::sanitize($request->session()->pull(self::SESSION_REDIRECT));
        $linkNonce = $request->session()->pull(self::SESSION_LINK_NONCE);

        try {
            $driver = $this->service->driverFor($provider);
            $socialUser = $driver->user();
        } catch (\Throwable $e) {
            Log::warning('glitter-social_login: OAuth 콜백 실패', [
                'provider' => $provider,
                'exception' => get_class($e),
                'action' => 'callback',
            ]);

            return $linkNonce
                ? $this->frontendProfileError('oauth_failed')
                : $this->frontendLoginError('oauth_failed');
        }

        if (is_string($linkNonce) && $linkNonce !== '') {
            try {
                $providerUserId = $this->service->providerUserId($socialUser);
            } catch (\RuntimeException) {
                return $this->frontendProfileError('oauth_failed');
            }

            return $this->handleLinkCallback(
                $provider,
                $linkNonce,
                $providerUserId,
                $this->service->providerVerifiedEmail($provider, $socialUser),
            );
        }

        // 정상 login exchange 는 OAuth 시작 시 만든 browser binding 없이는
        // User resolution/신규 signup side effect까지 진행하지 않는다.
        $binding = $this->resolveExchangeBinding($request);
        if ($binding === null) {
            return $this->frontendLoginError('login_failed');
        }

        try {
            $result = $this->service->handleLogin($provider, $socialUser, $binding, $redirectAfter);
        } catch (\RuntimeException $e) {
            $key = match ($e->getMessage()) {
                'email_exists_unverified' => 'email_exists_unverified',
                SocialLoginEligibility::ERROR_TWO_FACTOR_REQUIRED => 'two_factor_required',
                default => 'login_failed',
            };

            return $this->frontendLoginError($key);
        }

        if (isset($result['pending_registration'])) {
            return redirect('/register?'.http_build_query([
                'social_registration' => $result['pending_registration']['registration_id'],
            ]));
        }

        if (($result['requires_additional_auth'] ?? false) === true) {
            try {
                if (! $this->twoFactor) {
                    throw new \RuntimeException(SocialTwoFactorService::ERROR_INVALID);
                }

                $pending = $this->twoFactor->start(
                    $result['user'],
                    $provider,
                    $this->service->providerUserId($socialUser),
                    $binding,
                    $redirectAfter,
                );
            } catch (\Throwable $e) {
                Log::warning('glitter-social_login: 추가 인증 시작 실패', [
                    'provider' => $provider,
                    'exception' => get_class($e),
                    'action' => 'two_factor_start',
                ]);

                return $this->frontendLoginError('two_factor_failed');
            }

            return redirect('/login?'.http_build_query([
                'social_two_factor' => $pending['pending_id'],
            ]));
        }

        // 돌아갈 경로는 URL 에 싣지 않고 교환코드 캐시에 담는다 — 프론트가 쿼리스트링을
        // 읽어 이동 경로를 정하면 검증을 우회한 오픈 리다이렉트 표면이 생긴다.
        $code = $this->service->issueExchangeCode($result['user'], $binding, $redirectAfter);

        return redirect('/login?'.http_build_query([
            'social_exchange' => $code,
        ]));
    }

    private function handleLinkCallback(string $provider, string $nonce, string $providerUserId, ?string $email): RedirectResponse
    {
        $userId = $this->service->consumeLinkNonce($nonce, $provider);

        if ($userId === null) {
            return $this->frontendProfileError('link_expired');
        }

        $user = User::query()->find($userId);

        if (! $user) {
            return $this->frontendProfileError('link_expired');
        }

        try {
            $this->service->linkAccount($user, $provider, $providerUserId, $email);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === SocialLinkingService::IDENTITY_CONFLICT) {
                return $this->frontendProfileError('link_taken');
            }

            return $this->frontendProfileError('link_expired');
        }

        return redirect('/mypage/profile?social_link=success');
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:128']]);
        $binding = $this->resolveExchangeBinding($request);
        if ($binding === null) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        try {
            $result = $this->service->consumeExchangeCode($validated['code'], $binding);
        } catch (\RuntimeException $e) {
            if (! in_array($e->getMessage(), [
                SocialLoginEligibility::ERROR_NOT_ELIGIBLE,
                SocialLoginEligibility::ERROR_TWO_FACTOR_REQUIRED,
            ], true)) {
                throw $e;
            }

            Log::notice('glitter-social_login: exchange eligibility rejected', [
                'exception' => get_class($e),
                'action' => 'exchange_eligibility',
            ]);

            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        if (! $result) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        $request->session()->forget(self::SESSION_EXCHANGE_BINDING);

        $user = $result['user'];
        $user->load(['roles.permissions']);

        return response()->json([
            'message' => __('auth.login_success'),
            'data' => (new UserResource($user))->toAuthArray($request),
            'token' => $result['token'],
            'redirect_path' => RedirectPath::sanitize($result['redirect_path']),
        ])->withCookie(cookie()->forget(self::COOKIE_EXCHANGE_BINDING));
    }

    public function completeTwoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pending_id' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'min:4', 'max:16'],
        ]);
        $binding = $this->resolveExchangeBinding($request);
        if ($binding === null) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        if (! $this->twoFactor) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        $result = $this->twoFactor->complete(
            $validated['pending_id'],
            $binding,
            $validated['code'],
        );

        if (! $result) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        try {
            // 2FA 완료 후에도 최종 token은 기존 bound one-time exchange 경계를 통과한다.
            $code = $this->service->issueExchangeCode(
                $result['user'],
                $binding,
                $result['redirect_path'],
            );
        } catch (\Throwable $e) {
            Log::warning('glitter-social_login: 추가 인증 완료 후 exchange 발급 실패', [
                'exception' => get_class($e),
                'action' => 'two_factor_complete',
            ]);

            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        return response()->json([
            'redirect_url' => '/login?'.http_build_query(['social_exchange' => $code]),
        ]);
    }

    public function submitRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pending_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'language' => ['nullable', 'string', 'in:ko,en'],
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
        ]);

        return $this->finishRegistration($request, $validated);
    }

    public function completeRegistrationIdentity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pending_id' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'min:4', 'max:16'],
        ]);

        if (! $this->registration) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        $binding = $this->resolveExchangeBinding($request);
        if ($binding === null) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        try {
            $result = $this->registration->completeIdentityVerification(
                $validated['pending_id'],
                $binding,
                $validated['code'],
            );
        } catch (\Throwable $e) {
            Log::warning('glitter-social_login: Social signup IDV completion failed', [
                'exception' => get_class($e),
                'action' => 'registration_idv',
            ]);

            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        return $this->registrationResult($result, $binding);
    }

    private function finishRegistration(Request $request, array $validated): JsonResponse
    {
        if (! $this->registration) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        $binding = $this->resolveExchangeBinding($request);
        if ($binding === null) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        try {
            $result = $this->registration->submit($validated['pending_id'], $binding, $validated);
        } catch (\Throwable $e) {
            Log::warning('glitter-social_login: Social signup submission failed', [
                'exception' => get_class($e),
                'action' => 'registration_submit',
            ]);

            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        if (($result['status'] ?? null) === 'idv_required') {
            return response()->json([
                'status' => 'idv_required',
                'redirect_url' => '/register?'.http_build_query([
                    'social_registration' => $validated['pending_id'],
                    'social_registration_idv' => '1',
                ]),
                'challenge_id' => $result['challenge_id'],
                'expires_at' => $result['expires_at'],
            ]);
        }

        return $this->registrationResult($result, $binding);
    }

    private function registrationResult(array $result, string $binding): JsonResponse
    {
        $user = $result['user'] ?? null;
        if (! $user instanceof User) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        if (($result['pending_verification'] ?? false) === true) {
            return response()->json([
                'redirect_url' => '/login?social_error=registration_pending',
            ]);
        }

        if ($this->service->requiresAdditionalAuthentication($user)) {
            if (! $this->twoFactor) {
                return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
            }

            try {
                $pending = $this->twoFactor->start(
                    $user,
                    (string) ($result['provider'] ?? ''),
                    (string) ($result['provider_user_id'] ?? ''),
                    $binding,
                    (string) ($result['redirect_path'] ?? '/'),
                );

                return response()->json([
                    'redirect_url' => '/login?'.http_build_query(['social_two_factor' => $pending['pending_id']]),
                ]);
            } catch (\Throwable) {
                return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
            }
        }

        try {
            $code = $this->service->issueExchangeCode(
                $user,
                $binding,
                (string) ($result['redirect_path'] ?? '/'),
            );
        } catch (\Throwable) {
            return response()->json(['message' => __('glitter-social_login::messages.registration_failed')], 422);
        }

        return response()->json([
            'redirect_url' => '/login?'.http_build_query(['social_exchange' => $code]),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $linked = SocialAccount::where('user_id', $request->user()->id)
            ->pluck('provider')
            ->all();

        return response()->json([
            'data' => [
                'linked_providers' => $linked,
            ],
        ]);
    }

    public function linkPrepare(Request $request, string $provider): JsonResponse
    {
        if (! $this->service->isEnabled($provider)) {
            return response()->json(['message' => __('common.not_found')], 404);
        }

        $nonce = $this->service->createLinkNonce($request->user(), $provider);

        // nonce 는 URL 이 아니라 세션에 싣는다. 이 라우트는 auth:sanctum 을 통과한 요청만
        // 닿을 수 있으므로, 연동 대상 회원을 정하는 값은 본인 브라우저의 세션에만 남는다.
        $request->session()->put(self::SESSION_LINK_NONCE, $nonce);

        return response()->json([
            'data' => [
                'redirect_url' => '/api/plugins/'.SocialAuthService::IDENTIFIER."/{$provider}/redirect",
            ],
        ]);
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        $result = $this->service->unlinkAccount($user, $provider);

        if ($result === SocialLinkingService::UNLINK_BLOCKED) {
            return response()->json(['message' => __('glitter-social_login::messages.profile.unlink_blocked_no_password')], 422);
        }

        return response()->json(['message' => __('glitter-social_login::messages.profile.unlink_success')]);
    }

    private function frontendLoginError(string $key): RedirectResponse
    {
        return redirect('/login?'.http_build_query(['social_error' => $key]));
    }

    private function frontendProfileError(string $key): RedirectResponse
    {
        return redirect('/mypage/profile?'.http_build_query(['social_link' => 'error', 'social_error' => $key]));
    }

    private function resolveExchangeBinding(Request $request): ?string
    {
        $sessionBinding = $request->session()->get(self::SESSION_EXCHANGE_BINDING);
        $cookieBinding = $request->cookie(self::COOKIE_EXCHANGE_BINDING);

        if (is_string($sessionBinding) && $sessionBinding !== '') {
            if (is_string($cookieBinding)
                && $cookieBinding !== ''
                && ! hash_equals($sessionBinding, $cookieBinding)) {
                return null;
            }

            return $sessionBinding;
        }

        return is_string($cookieBinding) && $cookieBinding !== ''
            ? $cookieBinding
            : null;
    }

    private function exchangeBindingCookie(string $binding): Cookie
    {
        return cookie(
            self::COOKIE_EXCHANGE_BINDING,
            $binding,
            10,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }
}
