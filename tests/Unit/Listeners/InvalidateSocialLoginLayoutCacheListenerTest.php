<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Listeners;

use App\Services\LayoutService;
use App\Services\TemplateService;
use Plugins\Glitter\SocialLogin\Listeners\InvalidateSocialLoginLayoutCacheListener;
use PHPUnit\Framework\TestCase;

final class InvalidateSocialLoginLayoutCacheListenerTest extends TestCase
{
    public function test_successful_social_login_save_clears_only_affected_layouts(): void
    {
        $cleared = [];
        $layoutService = $this->createMock(LayoutService::class);
        $layoutService->expects(self::exactly(3))
            ->method('clearLayoutCache')
            ->willReturnCallback(static function (int $templateId, string $layoutName) use (&$cleared): void {
                $cleared[] = [$templateId, $layoutName];
            });

        $templateService = $this->createMock(TemplateService::class);
        $templateService->expects(self::once())
            ->method('getActiveTemplateIdentifier')
            ->with('user')
            ->willReturn('sirsoft-basic');
        $templateService->expects(self::once())
            ->method('findByIdentifier')
            ->with('sirsoft-basic')
            ->willReturn((object) ['id' => 42]);

        $listener = new InvalidateSocialLoginLayoutCacheListener($layoutService, $templateService);
        $listener->onSettingsSave('glitter-social_login', ['provider_order' => ['google']], true);

        self::assertSame(
            [
                [42, 'auth/login'],
                [42, 'auth/register'],
                [42, 'mypage/profile'],
            ],
            $cleared,
        );
    }

    public function test_failed_or_unrelated_save_does_not_clear_layouts(): void
    {
        $layoutService = $this->createMock(LayoutService::class);
        $layoutService->expects(self::never())->method('clearLayoutCache');
        $templateService = $this->createMock(TemplateService::class);
        $templateService->expects(self::never())->method('getActiveTemplateIdentifier');

        $listener = new InvalidateSocialLoginLayoutCacheListener($layoutService, $templateService);

        $listener->onSettingsSave('glitter-social_login', [], false);
        $listener->onSettingsSave('other-plugin', [], true);

        self::assertTrue(true);
    }
}
