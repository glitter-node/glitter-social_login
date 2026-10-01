<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Support\BrandIcons;

final class BrandIconsTest extends TestCase
{
    public function test_github_icon_is_loaded_from_the_plugin_local_svg(): void
    {
        $dataUri = BrandIcons::githubDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $svg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);

        self::assertIsString($svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
        self::assertStringContainsString('fill="#181717"', $svg);
        self::assertStringContainsString('fill="#ffffff"', $svg);
        self::assertStringNotContainsString('sir.kr', $svg);
    }

    public function test_google_icon_is_loaded_from_the_plugin_local_svg(): void
    {
        $dataUri = BrandIcons::googleDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $svg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);

        self::assertIsString($svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
        self::assertStringContainsString('fill="#4285F4"', $svg);
        self::assertStringContainsString('fill="#34A853"', $svg);
        self::assertStringContainsString('fill="#FBBC05"', $svg);
        self::assertStringContainsString('fill="#EA4335"', $svg);
        self::assertStringNotContainsString('sir.kr', $svg);
    }

    public function test_naver_icon_is_loaded_from_the_plugin_local_svg(): void
    {
        $dataUri = BrandIcons::naverDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $svg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);

        self::assertIsString($svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
        self::assertStringContainsString('fill="#03C75A"', $svg);
        self::assertStringContainsString('fill="#ffffff"', $svg);
        self::assertStringNotContainsString('sir.kr', $svg);
    }

    public function test_facebook_icon_is_loaded_from_the_plugin_local_svg(): void
    {
        $dataUri = BrandIcons::facebookDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $svg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);

        self::assertIsString($svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
        self::assertStringContainsString('fill="#1877F2"', $svg);
        self::assertStringContainsString('fill="#ffffff"', $svg);
        self::assertStringNotContainsString('sir.kr', $svg);
    }
}
