<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Listeners;

use Plugins\Glitter\SocialLogin\Listeners\LoginPageWidgetListener;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;
use PHPUnit\Framework\TestCase;

final class LoginPageWidgetListenerTest extends TestCase
{
    public function test_social_auth_service_is_required_for_container_resolution(): void
    {
        $parameter = (new \ReflectionMethod(LoginPageWidgetListener::class, '__construct'))->getParameters()[0];
        $type = $parameter->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(SocialAuthService::class, $type->getName());
        self::assertFalse($type->allowsNull());
        self::assertFalse($parameter->isDefaultValueAvailable());
    }

    public function test_custom_provider_order_is_used_for_login_and_register(): void
    {
        $service = $this->createMock(SocialAuthService::class);
        $service->method('orderedProviders')->willReturn(['github', 'google', 'naver', 'kakao', 'facebook']);
        $listener = new LoginPageWidgetListener($service);

        $loginWidget = $listener->injectWidget($this->loginLayout('w-full max-w-md'))['components'][0]['children'][2];
        $registerWidget = $listener->injectWidget($this->registerLayout())['components'][0]['children'][2];
        $expected = [
            '/api/plugins/glitter-social_login/github/redirect',
            '/api/plugins/glitter-social_login/google/redirect',
            '/api/plugins/glitter-social_login/naver/redirect',
            '/api/plugins/glitter-social_login/kakao/redirect',
            '/api/plugins/glitter-social_login/facebook/redirect',
        ];

        self::assertSame($expected, array_map(static fn (array $node): string => $node['props']['href'], array_slice($loginWidget['children'], 1)));
        self::assertSame($expected, array_map(static fn (array $node): string => $node['props']['href'], array_slice($registerWidget['children'], 1)));
    }

    public function test_injects_into_current_login_layout_without_relying_on_outer_visual_classes(): void
    {
        $layout = $this->loginLayout('w-full max-w-md p-8 bg-white dark:bg-slate-800 rounded-lg shadow');

        $result = $this->listener()->injectWidget($layout);

        $children = $result['components'][0]['children'];
        self::assertSame('glitter_social_login_widget', $children[2]['id']);
        self::assertSame('/api/plugins/glitter-social_login/naver/redirect', $children[2]['children'][1]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/kakao/redirect', $children[2]['children'][2]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/google/redirect', $children[2]['children'][3]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/facebook/redirect', $children[2]['children'][4]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/github/redirect', $children[2]['children'][5]['props']['href']);
    }

    public function test_retains_compatibility_with_previous_gray_login_layout(): void
    {
        $layout = $this->loginLayout('w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow');

        $result = $this->listener()->injectWidget($layout);

        self::assertSame('glitter_social_login_widget', $result['components'][0]['children'][2]['id']);
    }

    public function test_only_injects_into_auth_login(): void
    {
        $layout = $this->loginLayout('w-full max-w-md p-8 bg-white dark:bg-slate-800 rounded-lg shadow');
        $layout['layout_name'] = 'auth/register';

        $result = $this->listener()->injectWidget($layout);

        self::assertArrayNotHasKey('id', $result['components'][0]['children'][2]);
        self::assertArrayNotHasKey('initActions', $result);
    }

    public function test_injects_into_current_auth_register_layout(): void
    {
        $layout = $this->registerLayout();

        $result = $this->listener()->injectWidget($layout);

        $children = $result['components'][0]['children'];
        self::assertSame('glitter_social_login_widget', $children[2]['id']);
        self::assertSame('/api/plugins/glitter-social_login/naver/redirect', $children[2]['children'][1]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/kakao/redirect', $children[2]['children'][2]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/google/redirect', $children[2]['children'][3]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/facebook/redirect', $children[2]['children'][4]['props']['href']);
        self::assertSame('/api/plugins/glitter-social_login/github/redirect', $children[2]['children'][5]['props']['href']);
        self::assertArrayNotHasKey('initActions', $result);
    }

    public function test_login_and_register_use_context_specific_button_translation_keys(): void
    {
        $listener = $this->listener();

        $login = $listener->injectWidget($this->loginLayout('w-full max-w-md'));
        $register = $listener->injectWidget($this->registerLayout());

        self::assertSame(
            [
                '$t:glitter-social_login.login.naver_button',
                '$t:glitter-social_login.login.kakao_button',
                '$t:glitter-social_login.login.google_button',
                '$t:glitter-social_login.login.facebook_button',
                '$t:glitter-social_login.login.github_button',
            ],
            $this->widgetLabels($login['components'][0]['children'][2]),
        );
        self::assertSame(
            [
                '$t:glitter-social_login.register.naver_button',
                '$t:glitter-social_login.register.kakao_button',
                '$t:glitter-social_login.register.google_button',
                '$t:glitter-social_login.register.facebook_button',
                '$t:glitter-social_login.register.github_button',
            ],
            $this->widgetLabels($register['components'][0]['children'][2]),
        );
    }

    public function test_login_and_register_translation_values_match_each_context(): void
    {
        $root = dirname(__DIR__, 3);
        $ko = json_decode(file_get_contents($root.'/resources/lang/ko.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode(file_get_contents($root.'/resources/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['NAVER로 계속하기', '카카오로 계속하기', 'Google로 계속하기', 'Facebook으로 계속하기', 'GitHub으로 계속하기'],
            [$ko['login']['naver_button'], $ko['login']['kakao_button'], $ko['login']['google_button'], $ko['login']['facebook_button'], $ko['login']['github_button']],
        );
        self::assertSame(
            ['NAVER로 가입하기', '카카오로 가입하기', 'Google로 가입하기', 'Facebook으로 가입하기', 'GitHub으로 가입하기'],
            [$ko['register']['naver_button'], $ko['register']['kakao_button'], $ko['register']['google_button'], $ko['register']['facebook_button'], $ko['register']['github_button']],
        );
        self::assertSame(
            ['Continue with Naver', 'Continue with Kakao', 'Continue with Google', 'Continue with Facebook', 'Continue with GitHub'],
            [$en['login']['naver_button'], $en['login']['kakao_button'], $en['login']['google_button'], $en['login']['facebook_button'], $en['login']['github_button']],
        );
        self::assertSame(
            ['Sign up with Naver', 'Sign up with Kakao', 'Sign up with Google', 'Sign up with Facebook', 'Sign up with GitHub'],
            [$en['register']['naver_button'], $en['register']['kakao_button'], $en['register']['google_button'], $en['register']['facebook_button'], $en['register']['github_button']],
        );
    }

    public function test_injects_into_project_hub_register_layout_with_register_partial(): void
    {
        $layout = $this->registerLayoutWithPartial();

        $result = $this->listener()->injectWidget($layout);

        $children = $result['components'][0]['children'];
        self::assertSame('glitter_social_login_widget', $children[2]['id']);
        self::assertArrayNotHasKey('initActions', $result);
    }

    public function test_does_not_treat_an_unrelated_partial_as_register_form(): void
    {
        $layout = $this->registerLayoutWithPartial('partials/auth/_login_form.json');

        $result = $this->listener()->injectWidget($layout);

        self::assertSame($layout['components'], $result['components']);
        self::assertArrayNotHasKey('initActions', $result);
    }

    public function test_register_widget_preserves_provider_visibility_conditions(): void
    {
        $result = $this->listener()->injectWidget($this->registerLayout());
        $widget = $result['components'][0]['children'][2];

        self::assertSame(
            "{{_global.plugins?.['glitter-social_login']?.naver_enabled || _global.plugins?.['glitter-social_login']?.kakao_enabled || _global.plugins?.['glitter-social_login']?.google_enabled || _global.plugins?.['glitter-social_login']?.facebook_enabled || _global.plugins?.['glitter-social_login']?.github_enabled}}",
            $widget['if'],
        );
        self::assertSame("{{_global.plugins?.['glitter-social_login']?.naver_enabled}}", $widget['children'][1]['if']);
        self::assertSame("{{_global.plugins?.['glitter-social_login']?.kakao_enabled}}", $widget['children'][2]['if']);
        self::assertSame("{{_global.plugins?.['glitter-social_login']?.google_enabled}}", $widget['children'][3]['if']);
        self::assertSame("{{_global.plugins?.['glitter-social_login']?.facebook_enabled}}", $widget['children'][4]['if']);
        self::assertSame("{{_global.plugins?.['glitter-social_login']?.github_enabled}}", $widget['children'][5]['if']);
    }

    public function test_provider_buttons_use_enabled_only_visibility_for_login_and_register(): void
    {
        $listener = $this->listener();
        $loginWidget = $listener->injectWidget($this->loginLayout('w-full max-w-md'))['components'][0]['children'][2];
        $registerWidget = $listener->injectWidget($this->registerLayout())['components'][0]['children'][2];

        $providers = ['naver', 'kakao', 'google', 'facebook', 'github'];

        foreach ([$loginWidget, $registerWidget] as $widget) {
            foreach (array_values($providers) as $index => $provider) {
                $condition = $widget['children'][$index + 1]['if'];

                self::assertSame(
                    "{{_global.plugins?.['glitter-social_login']?.{$provider}_enabled}}",
                    $condition,
                );
                self::assertStringNotContainsString('client_id', $condition);
                self::assertStringNotContainsString('client_secret', $condition);
            }
        }
    }

    public function test_facebook_button_uses_brand_colors_with_local_icon(): void
    {
        $result = $this->listener()->injectWidget($this->loginLayout('w-full max-w-md'));
        $facebookButton = $result['components'][0]['children'][2]['children'][4];

        self::assertSame(
            'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-[#1877F2] bg-[#1877F2] text-white hover:bg-[#166FE5] transition-colors',
            $facebookButton['props']['className'],
        );
        self::assertCount(2, $facebookButton['children']);
        self::assertSame('Img', $facebookButton['children'][0]['name']);
        self::assertSame('', $facebookButton['children'][0]['props']['alt']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $facebookButton['children'][0]['props']['src']);
        self::assertSame('Span', $facebookButton['children'][1]['name']);
    }

    public function test_naver_button_uses_brand_colors_with_local_icon(): void
    {
        $result = $this->listener()->injectWidget($this->loginLayout('w-full max-w-md'));
        $naverButton = $result['components'][0]['children'][2]['children'][1];

        self::assertSame(
            'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-[#03C75A] bg-[#03C75A] text-white hover:bg-[#02B350] transition-colors',
            $naverButton['props']['className'],
        );
        self::assertCount(2, $naverButton['children']);
        self::assertSame('Img', $naverButton['children'][0]['name']);
        self::assertSame('', $naverButton['children'][0]['props']['alt']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $naverButton['children'][0]['props']['src']);
        self::assertSame('Span', $naverButton['children'][1]['name']);
    }

    public function test_google_button_uses_the_local_svg_icon(): void
    {
        $result = $this->listener()->injectWidget($this->loginLayout('w-full max-w-md'));
        $googleButton = $result['components'][0]['children'][2]['children'][3];

        self::assertCount(2, $googleButton['children']);
        self::assertSame('Img', $googleButton['children'][0]['name']);
        self::assertSame('', $googleButton['children'][0]['props']['alt']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $googleButton['children'][0]['props']['src']);
        self::assertSame('Span', $googleButton['children'][1]['name']);
    }

    public function test_github_button_is_fifth_and_uses_the_local_icon(): void
    {
        $result = $this->listener()->injectWidget($this->loginLayout('w-full max-w-md'));
        $githubButton = $result['components'][0]['children'][2]['children'][5];

        self::assertSame('/api/plugins/glitter-social_login/github/redirect', $githubButton['props']['href']);
        self::assertSame(
            'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors',
            $githubButton['props']['className'],
        );
        self::assertCount(2, $githubButton['children']);
        self::assertSame('Img', $githubButton['children'][0]['name']);
        self::assertSame('', $githubButton['children'][0]['props']['alt']);
        self::assertSame('w-5 h-5', $githubButton['children'][0]['props']['className']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $githubButton['children'][0]['props']['src']);
        self::assertSame('Span', $githubButton['children'][1]['name']);
        self::assertStringNotContainsString('sir.kr', $githubButton['children'][0]['props']['src']);
    }

    public function test_both_disabled_condition_hides_register_widget(): void
    {
        $result = $this->listener()->injectWidget($this->registerLayout());

        self::assertSame(
            "{{_global.plugins?.['glitter-social_login']?.naver_enabled || _global.plugins?.['glitter-social_login']?.kakao_enabled || _global.plugins?.['glitter-social_login']?.google_enabled || _global.plugins?.['glitter-social_login']?.facebook_enabled || _global.plugins?.['glitter-social_login']?.github_enabled}}",
            $result['components'][0]['children'][2]['if'],
        );
    }

    public function test_does_not_duplicate_register_widget(): void
    {
        $listener = $this->listener();
        $once = $listener->injectWidget($this->registerLayout());
        $twice = $listener->injectWidget($once);

        $children = $twice['components'][0]['children'];
        self::assertCount(1, array_filter($children, static fn (array $child): bool => ($child['id'] ?? null) === 'glitter_social_login_widget'));
    }

    public function test_does_not_inject_into_unrelated_layout(): void
    {
        $layout = $this->registerLayout();
        $layout['layout_name'] = 'page/show';

        self::assertSame($layout, $this->listener()->injectWidget($layout));
    }

    public function test_keeps_register_layout_unchanged_when_anchor_is_missing(): void
    {
        $layout = $this->registerLayout(false);

        $result = $this->listener()->injectWidget($layout);

        self::assertSame($layout['components'], $result['components']);
        self::assertArrayNotHasKey('initActions', $result);
    }

    public function test_login_exchange_init_action_remains_intact(): void
    {
        $result = $this->listener()->injectWidget($this->loginLayout('w-full max-w-md'));

        self::assertSame('glitter_social_login_exchange', $result['initActions'][0]['_marker']);
        self::assertSame('/api/plugins/glitter-social_login/exchange', $result['initActions'][0]['target']);
    }

    private function listener(): LoginPageWidgetListener
    {
        $service = $this->createMock(SocialAuthService::class);
        $service->method('orderedProviders')->willReturn(['naver', 'kakao', 'google', 'facebook', 'github']);

        return new LoginPageWidgetListener($service);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginLayout(string $outerClass): array
    {
        return [
            'layout_name' => 'auth/login',
            'components' => [
                [
                    'name' => 'Div',
                    'props' => ['className' => $outerClass],
                    'children' => [
                        ['name' => 'H2', 'props' => ['className' => 'text-2xl']],
                        ['name' => 'Form', 'children' => []],
                        [
                            'name' => 'Div',
                            'props' => ['className' => 'mt-4 flex items-center justify-between text-sm'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerLayout(bool $includeSwitch = true): array
    {
        $children = [
            ['name' => 'H2', 'text' => '$t:auth.register.title'],
            ['name' => 'Form', 'children' => []],
        ];

        if ($includeSwitch) {
            $children[] = [
                'name' => 'Div',
                'children' => [
                    ['name' => 'Span', 'text' => '$t:auth.already_member'],
                    [
                        'name' => 'Button',
                        'actions' => [[
                            'type' => 'click',
                            'handler' => 'navigate',
                            'params' => ['path' => '/login'],
                        ]],
                    ],
                ],
            ];
        }

        return [
            'layout_name' => 'auth/register',
            'components' => [[
                'name' => 'Div',
                'props' => ['className' => 'w-full max-w-md bg-white dark:bg-slate-800 rounded-lg shadow'],
                'children' => $children,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerLayoutWithPartial(string $partial = 'partials/auth/_register_form.json'): array
    {
        $layout = $this->registerLayout();
        $layout['components'][0]['children'][1] = ['partial' => $partial];

        return $layout;
    }

    /**
     * @param array<string, mixed> $widget
     * @return array<int, string>
     */
    private function widgetLabels(array $widget): array
    {
        return array_map(static function (array $button): string {
            $children = $button['children'];
            $label = $children[array_key_last($children)];

            return (string) $label['text'];
        }, array_slice($widget['children'], 1, 4));
    }
}
