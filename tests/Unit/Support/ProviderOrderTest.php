<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Support\ProviderOrder;

final class ProviderOrderTest extends TestCase
{
    public function test_normalize_uses_canonical_order_for_missing_or_malformed_values(): void
    {
        self::assertSame(['naver', 'kakao', 'google', 'facebook', 'github'], ProviderOrder::normalize(null));
        self::assertSame(['naver', 'kakao', 'google', 'facebook', 'github'], ProviderOrder::normalize('github'));
    }

    public function test_normalize_preserves_custom_order_and_appends_missing_known_providers(): void
    {
        self::assertSame(
            ['github', 'google', 'naver', 'kakao', 'facebook'],
            ProviderOrder::normalize(['github', 'google', 'naver', 'kakao', 'facebook']),
        );
        self::assertSame(
            ['github', 'naver', 'kakao', 'google', 'facebook'],
            ProviderOrder::normalize(['github', 'unknown', 'naver']),
        );
        self::assertSame(
            ['github', 'naver', 'kakao', 'google', 'facebook'],
            ProviderOrder::normalize(['github', 'github', 1, null, 'naver']),
        );
    }

    public function test_enabled_filters_normalized_order_without_changing_relative_positions(): void
    {
        self::assertSame(
            ['github', 'naver', 'google'],
            ProviderOrder::enabled(
                ['facebook', 'kakao', 'github', 'naver', 'google'],
                static fn (string $provider): bool => in_array($provider, ['github', 'naver', 'google'], true),
            ),
        );
    }

    public function test_merge_enabled_order_preserves_disabled_provider_slots(): void
    {
        self::assertSame(
            ['facebook', 'kakao', 'google', 'github', 'naver'],
            ProviderOrder::mergeEnabledOrder(
                ['facebook', 'kakao', 'github', 'naver', 'google'],
                ['google', 'github', 'naver'],
                ['github', 'naver', 'google'],
            ),
        );
    }

    public function test_empty_and_single_enabled_sets_are_supported(): void
    {
        self::assertSame([], ProviderOrder::enabled([], static fn (): bool => false));
        self::assertSame(
            ['google'],
            ProviderOrder::enabled(
                ['google', 'naver', 'kakao', 'facebook', 'github'],
                static fn (string $provider): bool => $provider === 'google',
            ),
        );
    }
}
