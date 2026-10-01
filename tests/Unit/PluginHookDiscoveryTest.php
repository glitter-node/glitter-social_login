<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Glitter\SocialLogin\Listeners\InvalidateSocialLoginLayoutCacheListener;
use Plugins\Glitter\SocialLogin\Listeners\LoginPageWidgetListener;
use Plugins\Glitter\SocialLogin\Listeners\MypageProfileWidgetListener;
use Plugins\Glitter\SocialLogin\Listeners\NormalizeProviderOrderSettingsListener;
use Plugins\Glitter\SocialLogin\Listeners\NotificationExtractDataListener;
use Plugins\Glitter\SocialLogin\Listeners\PasswordChangedFlagListener;
use Plugins\Glitter\SocialLogin\Listeners\ProfileEditPasswordGateListener;
use Plugins\Glitter\SocialLogin\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginHookDiscoveryTest extends TestCase
{
    public function test_plugin_exposes_the_gnuboard_hook_discovery_contract(): void
    {
        $plugin = new Plugin();

        self::assertSame('glitter-social_login', $plugin->getIdentifier());
        self::assertSame(
            [
                LoginPageWidgetListener::class,
                MypageProfileWidgetListener::class,
                NormalizeProviderOrderSettingsListener::class,
                InvalidateSocialLoginLayoutCacheListener::class,
                NotificationExtractDataListener::class,
                PasswordChangedFlagListener::class,
                ProfileEditPasswordGateListener::class,
            ],
            $plugin->getHookListeners(),
        );

        foreach ($plugin->getHookListeners() as $listenerClass) {
            self::assertTrue(is_subclass_of($listenerClass, HookListenerInterface::class));
            self::assertIsArray($listenerClass::getSubscribedHooks());
        }
    }

    public function test_login_listener_subscribes_to_layout_extension_filter(): void
    {
        self::assertSame(
            [
                'core.layout_extension.after_apply' => [
                    'method' => 'injectWidget',
                    'type' => 'filter',
                    'priority' => 20,
                ],
            ],
            LoginPageWidgetListener::getSubscribedHooks(),
        );
    }

    public function test_social_login_cache_listener_subscribes_to_successful_settings_save(): void
    {
        self::assertSame(
            [
                'core.plugin_settings.after_save' => [
                    'method' => 'onSettingsSave',
                    'priority' => 20,
                    'type' => 'action',
                ],
            ],
            InvalidateSocialLoginLayoutCacheListener::getSubscribedHooks(),
        );
    }
}
