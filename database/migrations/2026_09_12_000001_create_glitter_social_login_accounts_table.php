<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_social_login_accounts')) {
            return;
        }

        Schema::create('glitter_social_login_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_user_id'],
                'social_login_provider_user_unique'
            );
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glitter_social_login_accounts');
    }
};
