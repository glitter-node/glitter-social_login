<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    private const TABLES = [
        'g7_social_login_accounts' => 'glitter_social_login_accounts',
        'g7_social_login_link_nonces' => 'glitter_social_login_link_nonces',
        'g7_social_login_user_flags' => 'glitter_social_login_user_flags',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $legacy => $current) {
            if (! Schema::hasTable($legacy)) {
                continue;
            }

            if (Schema::hasTable($current)) {
                throw new RuntimeException("Cannot migrate legacy social-login table {$legacy}: {$current} already exists.");
            }

            Schema::rename($legacy, $current);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES, true) as $legacy => $current) {
            if (! Schema::hasTable($current)) {
                continue;
            }

            if (Schema::hasTable($legacy)) {
                throw new RuntimeException("Cannot roll back social-login table {$current}: {$legacy} already exists.");
            }

            Schema::rename($current, $legacy);
        }
    }
};
