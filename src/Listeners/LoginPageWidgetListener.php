<?php

namespace Plugins\Glitter\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use Plugins\Glitter\SocialLogin\Support\BrandIcons;
use Plugins\Glitter\SocialLogin\Support\SocialLoginRateLimiters;

/**
 * 인증 화면(`auth/login`, `auth/register`)에 소셜 로그인 버튼을
 * `core.layout_extension.after_apply` 필터로 주입한다. 로그인 화면에는
 * extension_point 가 없어(조사 결과) 코어/템플릿 파일을 건드리지 않는 이 방식이
 * 유일한 무변경 주입 경로다 (g7-forum-addon 의 board/show 위젯 주입과 동일 패턴).
 *
 * 버튼은 `_global.plugins['glitter-social_login'].{provider}_enabled` 로 켜져 있을
 * 때만 보인다(플러그인 설정의 frontend_schema 노출값, 별도 API 호출 불필요).
 */
class LoginPageWidgetListener implements HookListenerInterface
{
    public function __construct(private readonly SocialAuthService $socialAuth) {}

    private const WIDGET_ID = 'glitter_social_login_widget';

    private const INIT_ACTION_MARKER = 'glitter_social_login_exchange';

    /** 로그인 카드의 의미적 자식 구조를 확인하기 위한 컴포넌트 이름 */
    private const LOGIN_HEADING_NAME = 'h2';

    private const LOGIN_FORM_NAME = 'form';

    private const REGISTER_FORM_PARTIAL = 'partials/auth/_register_form.json';

    /** 로그인 카드 안에서 "하단 링크 영역" Div 바로 앞에 버튼을 끼워 넣는다. */
    private const BOTTOM_LINKS_CLASSNAME = 'mt-4 flex items-center justify-between text-sm';

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectWidget',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function injectWidget(array $layout, int $templateId = 0): array
    {
        $layoutName = $layout['layout_name'] ?? null;

        if (! in_array($layoutName, ['auth/login', 'auth/register'], true)) {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if (! $this->treeHasNodeId($layout['components'], self::WIDGET_ID)) {
            $injected = 0;
            $layout['components'] = $layoutName === 'auth/register'
                ? $this->spliceIntoRegisterFormContainer($layout['components'], $injected)
                : $this->spliceIntoLoginFormContainer($layout['components'], $injected);

            if ($injected === 0) {
                Log::error('[glitter-social_login] 인증 폼 구조 앵커를 찾지 못해 소셜 로그인 버튼을 주입하지 못했습니다.', [
                    'template_id' => $templateId,
                    'layout_name' => $layoutName,
                ]);
            }
        }

        // OAuth callback은 일반 로그인에서는 exchange로, 2FA 대상 계정에서는
        // pending context로 /login에 돌아온다. 두 흐름 모두 login 화면이 소비한다.
        if ($layoutName === 'auth/login') {
            $layout['initActions'] = $this->ensureExchangeInitAction($layout['initActions'] ?? []);
        }

        return $layout;
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function spliceIntoLoginFormContainer(array $nodes, int &$injected): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if ($this->isLoginFormContainer($node)) {
                $node['children'] = $this->insertBeforeBottomLinks($node['children'], $injected);

                if ($injected > 0) {
                    continue;
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceIntoLoginFormContainer($node['children'], $injected);
            }
        }

        return $nodes;
    }

    /**
     * 회원가입 폼 컨테이너의 의미적 로그인 전환 영역 바로 앞에 주입한다.
     *
     * @param  array<int, array>  $nodes
     */
    private function spliceIntoRegisterFormContainer(array $nodes, int &$injected): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if ($this->isRegisterFormContainer($node)) {
                $node['children'] = $this->insertBeforeRegisterSwitch($node['children'], $injected);

                if ($injected > 0) {
                    continue;
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceIntoRegisterFormContainer($node['children'], $injected);
            }
        }

        return $nodes;
    }

    /**
     * Identify the login card from its semantic child structure, not visual styling.
     *
     * The final layout-extension payload is a flattened component tree. The login
     * card is the direct parent of the login heading, login form, and the existing
     * bottom-links row. Requiring all three direct children prevents an unrelated
     * container with a similarly styled link row from becoming an injection target.
     *
     * @param  array<string, mixed>  $node
     */
    private function isLoginFormContainer(array $node): bool
    {
        if (! isset($node['children']) || ! is_array($node['children'])) {
            return false;
        }

        $hasLoginHeading = false;
        $hasLoginForm = false;
        $hasBottomLinks = false;

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $name = strtolower((string) ($child['name'] ?? ''));

            if (in_array($name, ['h1', self::LOGIN_HEADING_NAME], true)) {
                $hasLoginHeading = true;
            }

            if ($name === self::LOGIN_FORM_NAME) {
                $hasLoginForm = true;
            }

            if (($child['props']['className'] ?? null) === self::BOTTOM_LINKS_CLASSNAME
                || $this->isLoginSwitch($child)
            ) {
                $hasBottomLinks = true;
            }
        }

        return $hasLoginHeading && $hasLoginForm && $hasBottomLinks;
    }

    /**
     * Identify the register container from semantic registration content.
     *
     * The current project-hub layout has a register heading, a form, and a
     * "already a member" switch. The directory template uses the equivalent
     * register heading, form, and register-to-login link. Translation keys
     * and the /login destination make this narrower than a generic heading
     * plus form container while remaining independent of presentation CSS.
     *
     * @param  array<string, mixed>  $node
     */
    private function isRegisterFormContainer(array $node): bool
    {
        if (! isset($node['children']) || ! is_array($node['children'])) {
            return false;
        }

        $hasRegisterHeading = false;
        $hasRegisterForm = false;
        $hasRegisterSwitch = false;

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $name = strtolower((string) ($child['name'] ?? ''));

            if (in_array($name, ['h1', 'h2'], true)
                && in_array($child['text'] ?? null, ['$t:auth.register.title', '$t:auth_page.register_heading'], true)
            ) {
                $hasRegisterHeading = true;
            }

            if ($this->isRegisterFormNode($child)) {
                $hasRegisterForm = true;
            }

            if ($this->isRegisterSwitch($child)) {
                $hasRegisterSwitch = true;
            }
        }

        return $hasRegisterHeading && $hasRegisterForm && $hasRegisterSwitch;
    }

    /**
     * Recognize both direct Form nodes and the project-hub register partial.
     *
     * The exact partial path is a semantic layout contract; broad partial or
     * CSS matching would allow unrelated containers to become injection targets.
     *
     * @param  array<string, mixed>  $node
     */
    private function isRegisterFormNode(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));

        return $name === self::LOGIN_FORM_NAME
            || ($node['partial'] ?? null) === self::REGISTER_FORM_PARTIAL;
    }

    /**
     * Detect the semantic register-to-login switch without matching its CSS.
     *
     * @param  array<string, mixed>  $node
     */
    private function isRegisterSwitch(array $node): bool
    {
        if (! isset($node['children']) || ! is_array($node['children'])) {
            return false;
        }

        $hasPrompt = false;
        $hasLoginDestination = false;

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (in_array($child['text'] ?? null, ['$t:auth.already_member', '$t:auth_page.register_login_prompt'], true)) {
                $hasPrompt = true;
            }

            $name = strtolower((string) ($child['name'] ?? ''));
            $href = $child['props']['href'] ?? null;
            $actions = $child['actions'] ?? [];

            if ($name === 'a' && $href === '/login') {
                $hasLoginDestination = true;
            }

            foreach (is_array($actions) ? $actions : [] as $action) {
                if (($action['type'] ?? null) === 'click'
                    && ($action['handler'] ?? null) === 'navigate'
                    && ($action['params']['path'] ?? null) === '/login'
                ) {
                    $hasLoginDestination = true;
                }
            }
        }

        return $hasPrompt && $hasLoginDestination;
    }

    private function insertBeforeBottomLinks(array $children, int &$injected): array
    {
        $anchorIndex = null;

        foreach ($children as $index => $child) {
            if (is_array($child) && (
                ($child['props']['className'] ?? null) === self::BOTTOM_LINKS_CLASSNAME
                || $this->isLoginSwitch($child)
            )) {
                $anchorIndex = $index;
                break;
            }
        }

        if ($anchorIndex === null) {
            return $children;
        }

        array_splice($children, $anchorIndex, 0, [
            $this->buildWidgetNode('login'),
            $this->buildTwoFactorNode(),
        ]);
        $injected++;

        return $children;
    }

    /**
     * Known alternate-template login switch: a paragraph containing a
     * registration link. Unknown containers are intentionally not matched.
     *
     * @param  array<string, mixed>  $node
     */
    private function isLoginSwitch(array $node): bool
    {
        if (($node['props']['className'] ?? null) !== 'gd-auth-switch'
            || ! isset($node['children'])
            || ! is_array($node['children'])
        ) {
            return false;
        }

        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['props']['href'] ?? null) === '/register') {
                return true;
            }

            foreach (is_array($child['actions'] ?? null) ? $child['actions'] : [] as $action) {
                if (($action['type'] ?? null) === 'click'
                    && ($action['handler'] ?? null) === 'navigate'
                    && ($action['params']['path'] ?? null) === '/register'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function insertBeforeRegisterSwitch(array $children, int &$injected): array
    {
        foreach ($children as $index => $child) {
            if (is_array($child) && $this->isRegisterSwitch($child)) {
                array_splice($children, $index, 0, [
                    $this->buildWidgetNode('register'),
                    $this->buildRegistrationNode(),
                    $this->buildRegistrationIdentityNode(),
                ]);
                $injected++;

                return $children;
            }
        }

        return $children;
    }

    private function buildWidgetNode(string $context): array
    {
        $translationGroup = $context === 'register' ? 'register' : 'login';

        $widget = [
            'id' => self::WIDGET_ID,
            'comment' => 'glitter-social_login: 소셜 로그인 버튼',
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{_global.plugins?.['glitter-social_login']?.naver_enabled || _global.plugins?.['glitter-social_login']?.kakao_enabled || _global.plugins?.['glitter-social_login']?.google_enabled || _global.plugins?.['glitter-social_login']?.facebook_enabled || _global.plugins?.['glitter-social_login']?.github_enabled}}",
            'props' => ['className' => 'mt-6 space-y-3'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'relative my-4'],
                    'children' => [
                        [
                            'type' => 'basic', 'name' => 'Div',
                            'props' => ['className' => 'absolute inset-0 flex items-center'],
                            'children' => [[
                                'type' => 'basic', 'name' => 'Div',
                                'props' => ['className' => 'w-full border-t border-gray-200 dark:border-gray-700'],
                            ]],
                        ],
                        [
                            'type' => 'basic', 'name' => 'Div',
                            'props' => ['className' => 'relative flex justify-center'],
                            'children' => [[
                                'type' => 'basic', 'name' => 'Span',
                                'props' => ['className' => 'px-3 bg-white dark:bg-gray-800 text-sm text-gray-500 dark:text-gray-400'],
                                'text' => '$t:glitter-social_login.login.divider',
                            ]],
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['glitter-social_login']?.naver_enabled}}",
                    'props' => [
                        'href' => '/api/plugins/glitter-social_login/naver/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-[#03C75A] bg-[#03C75A] text-white hover:bg-[#02B350] transition-colors',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::naverDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => "\$t:glitter-social_login.{$translationGroup}.naver_button",
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['glitter-social_login']?.kakao_enabled}}",
                    'props' => [
                        // 고정 경로 — g7 표현식 평가기(SafeExpressionEvaluator)는 encodeURIComponent 등
                        // 화이트리스트 밖 전역 함수를 호출하지 못하고, 실패 시 {{ }} 원문을 그대로 흘린다.
                        'href' => '/api/plugins/glitter-social_login/kakao/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium bg-[#FEE500] text-black/85 hover:opacity-90 transition-opacity',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::kakaoDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => "\$t:glitter-social_login.{$translationGroup}.kakao_button",
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['glitter-social_login']?.google_enabled}}",
                    'props' => [
                        'href' => '/api/plugins/glitter-social_login/google/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::googleDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => "\$t:glitter-social_login.{$translationGroup}.google_button",
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['glitter-social_login']?.facebook_enabled}}",
                    'props' => [
                        'href' => '/api/plugins/glitter-social_login/facebook/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-[#1877F2] bg-[#1877F2] text-white hover:bg-[#166FE5] transition-colors',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::facebookDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => "\$t:glitter-social_login.{$translationGroup}.facebook_button",
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['glitter-social_login']?.github_enabled}}",
                    'props' => [
                        'href' => '/api/plugins/glitter-social_login/github/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::githubDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => "\$t:glitter-social_login.{$translationGroup}.github_button",
                        ],
                    ],
                ],
            ],
        ];

        $providerNodes = array_slice($widget['children'], 1);
        $providerNodesByProvider = array_combine(SocialAuthService::PROVIDERS, $providerNodes);
        $orderedProviders = $this->socialAuth->orderedProviders();
        $widget['children'] = [
            $widget['children'][0],
            ...array_values(array_map(
                static fn (string $provider): array => $providerNodesByProvider[$provider],
                $orderedProviders,
            )),
        ];

        return $widget;
    }

    /**
     * OAuth callback이 2FA pending context로 돌아온 경우에만 보이는 최소 입력 UI.
     * challenge 자체는 서버에 보관하고, 브라우저에는 opaque pending id만 둔다.
     */
    private function buildTwoFactorNode(): array
    {
        return [
            'id' => 'glitter_social_login_two_factor',
            'if' => '{{query?.social_two_factor}}',
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'H3',
                    'props' => ['className' => 'mb-2 text-base font-semibold text-gray-900 dark:text-gray-100'],
                    'text' => '$t:glitter-social_login.login.two_factor_title',
                ],
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'props' => ['className' => 'mb-3 text-sm text-gray-600 dark:text-gray-300'],
                    'text' => '$t:glitter-social_login.login.two_factor_description',
                ],
                [
                    'type' => 'basic',
                    'name' => 'Form',
                    'dataKey' => 'socialTwoFactor',
                    'actions' => [[
                        'type' => 'submit',
                        'handler' => 'apiCall',
                        'target' => '/api/plugins/glitter-social_login/two-factor',
                        'params' => [
                            'method' => 'POST',
                            'body' => [
                                'pending_id' => '{{query.social_two_factor}}',
                                'code' => '{{$event.target.code.value}}',
                            ],
                        ],
                        'onSuccess' => [[
                            'handler' => 'openWindow',
                            'target' => '{{response.redirect_url}}',
                            'params' => ['target' => '_self'],
                        ]],
                        'onError' => [[
                            'handler' => 'toast',
                            'params' => [
                                'type' => 'error',
                                'message' => '$t:glitter-social_login.login.two_factor_failed',
                            ],
                        ]],
                    ]],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Label',
                            'props' => ['className' => 'sr-only', 'htmlFor' => 'glitter-social-login-two-factor-code'],
                            'text' => '$t:glitter-social_login.login.two_factor_code',
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Input',
                            'props' => [
                                'id' => 'glitter-social-login-two-factor-code',
                                'name' => 'code',
                                'type' => 'text',
                                'inputMode' => 'numeric',
                                'autoComplete' => 'one-time-code',
                                'required' => true,
                                'className' => 'mb-3 w-full rounded-md border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800',
                                'placeholder' => '$t:glitter-social_login.login.two_factor_code_placeholder',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'props' => [
                                'type' => 'submit',
                                'className' => 'w-full rounded-md bg-gray-900 px-4 py-2 font-medium text-white dark:bg-gray-100 dark:text-gray-900',
                            ],
                            'text' => '$t:glitter-social_login.login.two_factor_submit',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 신규 Social identity의 사용자 입력/약관 동의와 signup IDV 코드를 받습니다.
     * Core register template를 수정하지 않고 pending query가 있을 때만 표시합니다.
     */
    private function buildRegistrationNode(): array
    {
        $fields = [
            [
                'name' => 'name',
                'type' => 'text',
                'required' => true,
                'placeholder' => '$t:glitter-social_login.register.name_placeholder',
            ],
            [
                'name' => 'nickname',
                'type' => 'text',
                'placeholder' => '$t:glitter-social_login.register.nickname_placeholder',
            ],
            [
                'name' => 'email',
                'type' => 'email',
                'placeholder' => '$t:glitter-social_login.register.email_placeholder',
            ],
        ];

        $fieldNodes = [];
        foreach ($fields as $field) {
            $fieldNodes[] = [
                'type' => 'basic',
                'name' => 'Input',
                'props' => array_merge([
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'className' => 'mb-3 w-full rounded-md border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800',
                ], array_filter([
                    'required' => $field['required'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                ], static fn (mixed $value): bool => $value !== null)),
            ];
        }

        $fieldNodes[] = [
            'type' => 'basic',
            'name' => 'Label',
            'props' => ['className' => 'mb-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => ['name' => 'agree_terms', 'type' => 'checkbox', 'required' => true],
                ],
                ['type' => 'basic', 'name' => 'Span', 'text' => '$t:glitter-social_login.register.agree_terms'],
            ],
        ];
        $fieldNodes[] = [
            'type' => 'basic',
            'name' => 'Label',
            'props' => ['className' => 'mb-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => ['name' => 'agree_privacy', 'type' => 'checkbox', 'required' => true],
                ],
                ['type' => 'basic', 'name' => 'Span', 'text' => '$t:glitter-social_login.register.agree_privacy'],
            ],
        ];

        $fieldNodes[] = [
            'type' => 'basic',
            'name' => 'Button',
            'props' => [
                'type' => 'submit',
                'className' => 'w-full rounded-md bg-gray-900 px-4 py-2 font-medium text-white dark:bg-gray-100 dark:text-gray-900',
            ],
            'text' => '$t:glitter-social_login.register.complete_button',
        ];

        return [
            'id' => 'glitter_social_login_registration',
            'if' => '{{query?.social_registration && !query?.social_registration_idv}}',
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700'],
            'children' => [
                ['type' => 'basic', 'name' => 'H3', 'text' => '$t:glitter-social_login.register.title'],
                ['type' => 'basic', 'name' => 'P', 'props' => ['className' => 'mb-3 text-sm text-gray-600 dark:text-gray-300'], 'text' => '$t:glitter-social_login.register.description'],
                [
                    'type' => 'basic',
                    'name' => 'Form',
                    'actions' => [[
                        'type' => 'submit',
                        'handler' => 'apiCall',
                        'target' => '/api/plugins/glitter-social_login/registration',
                        'params' => [
                            'method' => 'POST',
                            'body' => [
                                'pending_id' => '{{query.social_registration}}',
                                'name' => '{{$event.target.name.value}}',
                                'nickname' => '{{$event.target.nickname.value}}',
                                'email' => '{{$event.target.email.value}}',
                                'agree_terms' => '{{$event.target.agree_terms.checked}}',
                                'agree_privacy' => '{{$event.target.agree_privacy.checked}}',
                            ],
                        ],
                        'onSuccess' => [[
                            'handler' => 'openWindow',
                            'target' => '{{response.redirect_url}}',
                            'params' => ['target' => '_self'],
                        ]],
                        'onError' => [[
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '$t:glitter-social_login.register.failed'],
                        ]],
                    ]],
                    'children' => $fieldNodes,
                ],
            ],
        ];
    }

    private function buildRegistrationIdentityNode(): array
    {
        return [
            'id' => 'glitter_social_login_registration_idv',
            'if' => '{{query?.social_registration && query?.social_registration_idv}}',
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30'],
            'children' => [
                ['type' => 'basic', 'name' => 'H3', 'text' => '$t:glitter-social_login.register.idv_title'],
                ['type' => 'basic', 'name' => 'P', 'props' => ['className' => 'mb-3 text-sm text-gray-600 dark:text-gray-300'], 'text' => '$t:glitter-social_login.register.idv_description'],
                [
                    'type' => 'basic',
                    'name' => 'Form',
                    'actions' => [[
                        'type' => 'submit',
                        'handler' => 'apiCall',
                        'target' => '/api/plugins/glitter-social_login/registration/idv',
                        'params' => [
                            'method' => 'POST',
                            'body' => [
                                'pending_id' => '{{query.social_registration}}',
                                'code' => '{{$event.target.code.value}}',
                            ],
                        ],
                        'onSuccess' => [[
                            'handler' => 'openWindow',
                            'target' => '{{response.redirect_url}}',
                            'params' => ['target' => '_self'],
                        ]],
                        'onError' => [[
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '$t:glitter-social_login.register.failed'],
                        ]],
                    ]],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Input',
                            'props' => [
                                'name' => 'code',
                                'type' => 'text',
                                'inputMode' => 'numeric',
                                'autoComplete' => 'one-time-code',
                                'required' => true,
                                'className' => 'mb-3 w-full rounded-md border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800',
                                'placeholder' => '$t:glitter-social_login.register.idv_code_placeholder',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'props' => ['type' => 'submit', 'className' => 'w-full rounded-md bg-gray-900 px-4 py-2 font-medium text-white dark:bg-gray-100 dark:text-gray-900'],
                            'text' => '$t:glitter-social_login.register.idv_submit',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function ensureExchangeInitAction(array $initActions): array
    {
        foreach ($initActions as $action) {
            if (($action['_marker'] ?? null) === self::INIT_ACTION_MARKER) {
                return $initActions;
            }
        }

        // sequence 로 감싸지 않고 apiCall 을 직접 둔다 — 코어 TemplateApp::executeInitActions 는
        // init action 을 ActionDefinition 으로 옮길 때 `actions` 필드를 복사하지 않아, sequence 가
        // 빈 배열로 조용히 건너뛰어져 교환 API 가 한 번도 호출되지 않았다(실측).
        $initActions[] = [
            '_marker' => self::INIT_ACTION_MARKER,
            'if' => '{{query?.social_exchange}}',
            'handler' => 'apiCall',
            'target' => '/api/plugins/glitter-social_login/exchange',
            'params' => [
                'method' => 'POST',
                'body' => ['code' => '{{query.social_exchange}}'],
            ],
            'onSuccess' => [
                [
                    'handler' => 'saveToLocalStorage',
                    'params' => ['key' => 'auth_token', 'value' => '{{response.token}}'],
                ],
                [
                    'handler' => 'replaceUrl',
                    'params' => [
                        'query' => [
                            'social_exchange' => '',
                        ],
                        'mergeQuery' => true,
                    ],
                ],
                [
                    // SPA navigate 가 아니라 전체 이동(window.location.assign)이어야 한다.
                    // 코어 AuthManager 의 인증 상태(isAuthenticated)는 `login` 핸들러 내부의 private
                    // establishSession 또는 부팅 시 preloadAuth 로만 켜진다. navigate 로 이동하면
                    // 상태가 false 로 남아 DataSourceManager 가 auth_required 데이터소스(current_user)를
                    // 건너뛰고 fallback 으로 _global.currentUser 를 덮어써, 새로고침 전까지 비로그인처럼
                    // 보였다(실측). 전체 이동은 부팅 preloadAuth 를 거쳐 저장된 토큰으로 상태를 복원한다.
                    'handler' => 'openWindow',
                    // 서버가 검증한 값만 쓴다(쿼리스트링의 redirect 는 읽지 않음).
                    'target' => '{{response.redirect_path ?? \'/\'}}',
                    'params' => ['target' => '_self'],
                ],
            ],
            'onError' => [
                [
                    'handler' => 'replaceUrl',
                    'params' => [
                        'query' => [
                            'social_exchange' => '',
                        ],
                        'mergeQuery' => true,
                    ],
                ],
                [
                    'handler' => 'toast',
                    'params' => ['type' => 'error', 'message' => '$t:auth.login_failed'],
                ],
            ],
        ];

        return [...$initActions, ...$this->socialErrorToasts()];
    }

    /**
     * `social_error` 쿼리 안내 토스트.
     *
     * 기존 오류 코드는 지금처럼 코드 그대로 보여 주되, 보안상 사용자에게 안내해야
     * 하는 2FA 차단과 제한 초과는 번역 문구로 표시한다.
     *
     * @return array<int, array>
     */
    private function socialErrorToasts(): array
    {
        $code = SocialLoginRateLimiters::ERROR_CODE;
        $twoFactorCode = 'two_factor_required';
        $twoFactorFailedCode = 'two_factor_failed';
        $registrationPendingCode = 'registration_pending';

        return [
            [
                'if' => "{{query?.social_error && query.social_error !== '{$code}' && query.social_error !== '{$twoFactorCode}' && query.social_error !== '{$twoFactorFailedCode}' && query.social_error !== '{$registrationPendingCode}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '{{query.social_error}}',
                ],
            ],
            [
                'if' => "{{query?.social_error === '{$code}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '$t:glitter-social_login.login.too_many_attempts',
                ],
            ],
            [
                'if' => "{{query?.social_error === '{$twoFactorCode}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '$t:glitter-social_login.login.two_factor_required',
                ],
            ],
            [
                'if' => "{{query?.social_error === '{$twoFactorFailedCode}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '$t:glitter-social_login.login.two_factor_failed',
                ],
            ],
            [
                'if' => "{{query?.social_error === '{$registrationPendingCode}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'info',
                    'message' => '$t:glitter-social_login.login.registration_pending',
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function treeHasNodeId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $id) {
                return true;
            }

            if (isset($node['children']) && is_array($node['children']) && $this->treeHasNodeId($node['children'], $id)) {
                return true;
            }
        }

        return false;
    }
}
