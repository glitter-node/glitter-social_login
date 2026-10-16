<?php

namespace Plugins\Glitter\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Glitter\SocialLogin\Support\ProviderOrder;

final class NormalizeProviderOrderSettingsListener implements HookListenerInterface
{
    private const PLUGIN_ID = 'glitter-social_login';

    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.filter_save_data' => [
                'method' => 'normalizeProviderOrder',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function normalizeProviderOrder(array $settings, string $identifier): array
    {
        if ($identifier !== self::PLUGIN_ID || ! array_key_exists('provider_order', $settings)) {
            return $settings;
        }

        $settings['provider_order'] = ProviderOrder::normalize($settings['provider_order']);

        return $settings;
    }
}
