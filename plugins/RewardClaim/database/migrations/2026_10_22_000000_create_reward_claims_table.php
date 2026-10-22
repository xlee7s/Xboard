<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_reward_claims')) {
            return;
        }

        Schema::create('v2_reward_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedBigInteger('reward_bytes');
            $table->unsignedInteger('claimed_at')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['user_id', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_reward_claims');
    }
};
