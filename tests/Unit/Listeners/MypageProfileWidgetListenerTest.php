<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Glitter\SocialLogin\Listeners\MypageProfileWidgetListener;
use Plugins\Glitter\SocialLogin\Services\SocialAuthService;

final class MypageProfileWidgetListenerTest extends TestCase
{
    public function test_social_auth_service_is_required_for_container_resolution(): void
    {
        $parameter = (new \ReflectionMethod(MypageProfileWidgetListener::class, '__construct'))->getParameters()[0];
        $type = $parameter->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(SocialAuthService::class, $type->getName());
        self::assertFalse($type->allowsNull());
        self::assertFalse($parameter->isDefaultValueAvailable());
    }

    public function test_custom_provider_order_is_used_for_mypage_rows(): void
    {
        $service = $this->createMock(SocialAuthService::class);
        $service->method('orderedProviders')->willReturn(['github', 'google', 'naver', 'kakao', 'facebook']);
        $layout = [
            'layout_name' => 'mypage/profile',
            'components' => [['id' => 'profile_view_bio_card', 'type' => 'basic', 'name' => 'Div']],
        ];

        $result = (new MypageProfileWidgetListener($service))->injectWidget($layout);
        $rows = $result['components'][0]['children'][0]['children'][1]['children'];
        $providers = array_map(static function (array $row): string {
            preg_match("/\?\.\['glitter-social_login'\]\?\.([a-z]+)_enabled/", $row['if'], $matches);

            return $matches[1] ?? '';
        }, $rows);

        self::assertSame(['github', 'google', 'naver', 'kakao', 'facebook'], $providers);
    }

    public function test_facebook_is_fourth_and_github_is_fifth_provider_with_generic_endpoints(): void
    {
        $layout = [
            'layout_name' => 'mypage/profile',
            'components' => [
                ['id' => 'profile_view_bio_card', 'type' => 'basic', 'name' => 'Div'],
            ],
        ];

        $result = $this->listener()->injectWidget($layout);
        $widget = $result['components'][0]['children'][0];
        $rows = $widget['children'][1]['children'];

        self::assertCount(5, $rows);
        self::assertSame('Img', $rows[0]['children'][0]['children'][0]['name']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $rows[0]['children'][0]['children'][0]['props']['src']);
        self::assertSame('Img', $rows[2]['children'][0]['children'][0]['name']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $rows[2]['children'][0]['children'][0]['props']['src']);
        self::assertStringContainsString("facebook_enabled", $rows[3]['if']);
        self::assertSame('Img', $rows[3]['children'][0]['children'][0]['name']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $rows[3]['children'][0]['children'][0]['props']['src']);
        self::assertSame(
            '/api/plugins/glitter-social_login/facebook/link/prepare',
            $rows[3]['children'][1]['actions'][0]['target'],
        );
        self::assertSame(
            '/api/plugins/glitter-social_login/facebook/unlink',
            $rows[3]['children'][2]['actions'][0]['target'],
        );
        self::assertStringContainsString("github_enabled", $rows[4]['if']);
        self::assertCount(2, $rows[4]['children'][0]['children']);
        self::assertSame('Img', $rows[4]['children'][0]['children'][0]['name']);
        self::assertSame('', $rows[4]['children'][0]['children'][0]['props']['alt']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $rows[4]['children'][0]['children'][0]['props']['src']);
        self::assertSame(
            '/api/plugins/glitter-social_login/github/link/prepare',
            $rows[4]['children'][1]['actions'][0]['target'],
        );
        self::assertSame(
            '/api/plugins/glitter-social_login/github/unlink',
            $rows[4]['children'][2]['actions'][0]['target'],
        );
    }

    private function listener(): MypageProfileWidgetListener
    {
        $service = $this->createMock(SocialAuthService::class);
        $service->method('orderedProviders')->willReturn(['naver', 'kakao', 'google', 'facebook', 'github']);

        return new MypageProfileWidgetListener($service);
    }
}
