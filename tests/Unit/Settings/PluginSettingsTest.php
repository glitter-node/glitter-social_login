<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Plugin;

final class PluginSettingsTest extends TestCase
{
    public function test_github_settings_have_expected_schema_and_defaults(): void
    {
        $plugin = new Plugin();
        $schema = $plugin->getSettingsSchema();
        $defaults = $plugin->getConfigValues();

        self::assertSame(['type' => 'boolean'], $schema['github_enabled']);
        self::assertSame(['type' => 'string'], $schema['github_client_id']);
        self::assertSame(['type' => 'string', 'sensitive' => true], $schema['github_client_secret']);
        self::assertFalse($defaults['github_enabled']);
        self::assertSame('', $defaults['github_client_id']);
        self::assertSame('', $defaults['github_client_secret']);
    }

    public function test_github_settings_are_not_frontend_exposed_except_enabled_flag(): void
    {
        $defaults = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/config/settings/defaults.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($defaults['frontend_schema']['github_enabled']['expose']);
        self::assertFalse($defaults['frontend_schema']['github_client_id']['expose']);
        self::assertFalse($defaults['frontend_schema']['github_client_secret']['expose']);
    }

    public function test_facebook_settings_have_expected_schema_and_defaults(): void
    {
        $plugin = new Plugin();
        $schema = $plugin->getSettingsSchema();
        $defaults = $plugin->getConfigValues();

        self::assertSame(['type' => 'boolean'], $schema['facebook_enabled']);
        self::assertSame(['type' => 'string'], $schema['facebook_client_id']);
        self::assertSame(['type' => 'string', 'sensitive' => true], $schema['facebook_client_secret']);
        self::assertFalse($defaults['facebook_enabled']);
        self::assertSame('', $defaults['facebook_client_id']);
        self::assertSame('', $defaults['facebook_client_secret']);
    }

    public function test_facebook_settings_are_not_frontend_exposed_except_enabled_flag(): void
    {
        $defaults = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/config/settings/defaults.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($defaults['frontend_schema']['facebook_enabled']['expose']);
        self::assertFalse($defaults['frontend_schema']['facebook_client_id']['expose']);
        self::assertFalse($defaults['frontend_schema']['facebook_client_secret']['expose']);
    }

    public function test_provider_order_has_canonical_array_default_and_is_backend_only(): void
    {
        $plugin = new Plugin();
        $schema = $plugin->getSettingsSchema();
        $defaults = $plugin->getConfigValues();

        self::assertSame(['type' => 'array'], $schema['provider_order']);
        self::assertSame(['naver', 'kakao', 'google', 'facebook', 'github'], $defaults['provider_order']);

        $config = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/config/settings/defaults.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey('provider_order', $config['frontend_schema']);
    }

    public function test_admin_layout_uses_sortable_provider_rows_with_move_actions(): void
    {
        $layout = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/layouts/admin/plugin_settings.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $source = json_encode($layout, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('provider_order_section', $source);
        self::assertStringContainsString('"sortable"', $source);
        self::assertStringContainsString('onSortEnd', $source);
        self::assertStringContainsString('data-drag-handle', $source);
        self::assertStringContainsString('arrow-up', $source);
        self::assertStringContainsString('arrow-down', $source);
        self::assertStringContainsString('provider_order_move_up', $source);
        self::assertStringContainsString('provider_order_move_down', $source);
        self::assertSame(3, substr_count($source, '"hasChanges":true'));
        self::assertStringContainsString('"trackChanges":true', $source);
        self::assertStringContainsString('provider_order', $source);
        self::assertStringNotContainsString('JSON.stringify', $source);
        self::assertStringNotContainsString('provider_order_json', $source);
        self::assertStringContainsString('length >= 2', $source);
        self::assertStringContainsString('providerIndex === 0', $source);
        self::assertStringContainsString('providerIndex === (', $source);
        self::assertStringNotContainsString('DynamicFieldList', $source);
        self::assertStringNotContainsString('glitter-social-provider-order-row', $source);
        self::assertStringNotContainsString('"name":"provider_order"', $source);
    }

    public function test_provider_order_actions_use_typed_nested_form_state(): void
    {
        $layout = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/layouts/admin/plugin_settings.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $findActions = function (mixed $node) use (&$findActions): array {
            if (!is_array($node)) {
                return [];
            }

            $actions = [];
            if (($node['handler'] ?? null) === 'setState') {
                $actions[] = $node;
            }

            foreach ($node as $child) {
                $actions = [...$actions, ...$findActions($child)];
            }

            return $actions;
        };

        $providerOrderActions = array_values(array_filter(
            $findActions($layout),
            static fn (array $action): bool => array_key_exists(
                'provider_order',
                $action['params']['form'] ?? [],
            ),
        ));

        self::assertCount(3, $providerOrderActions);
        foreach ($providerOrderActions as $action) {
            self::assertSame('local', $action['params']['target'] ?? null);
            self::assertIsString($action['params']['form']['provider_order']);
            self::assertStringStartsWith('{{', $action['params']['form']['provider_order']);
            self::assertStringEndsWith('}}', $action['params']['form']['provider_order']);
        }

        self::assertStringNotContainsString('"form.provider_order"', json_encode($layout, JSON_THROW_ON_ERROR));
    }
}
