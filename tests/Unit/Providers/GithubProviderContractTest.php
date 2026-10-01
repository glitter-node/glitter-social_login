<?php

namespace Plugins\Glitter\SocialLogin\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

final class GithubProviderContractTest extends TestCase
{
    public function test_bundled_socialite_github_provider_selects_primary_verified_email(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/vendor/laravel/socialite/src/Two/GithubProvider.php');

        self::assertIsString($source);
        self::assertStringContainsString("protected \$scopes = ['user:email'];", $source);
        self::assertStringContainsString('https://api.github.com/user/emails', $source);
        self::assertStringContainsString("if (\$email['primary'] && \$email['verified'])", $source);
    }
}
