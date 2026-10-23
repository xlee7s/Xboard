<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_invite_traffic_reward_claims')) {
            return;
        }

        Schema::create('v2_invite_traffic_reward_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('inviter_user_id')->index();
            $table->unsignedInteger('invitee_user_id')->unique();
            $table->string('reward_type', 24);
            $table->unsignedBigInteger('reward_bytes')->default(0);
            $table->unsignedInteger('extension_seconds')->default(0);
            $table->unsignedInteger('claimed_at')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['inviter_user_id', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_traffic_reward_claims');
    }
};
