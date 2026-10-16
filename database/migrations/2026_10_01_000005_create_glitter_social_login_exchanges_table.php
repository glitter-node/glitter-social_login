<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_social_login_exchanges')) {
            return;
        }

        Schema::create('glitter_social_login_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique('social_login_exchange_code_unique');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_binding_hash', 64);
            $table->string('redirect_path', 2048)->default('/');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['expires_at', 'consumed_at'],
                'social_login_exchange_expiry_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glitter_social_login_exchanges');
    }
};
