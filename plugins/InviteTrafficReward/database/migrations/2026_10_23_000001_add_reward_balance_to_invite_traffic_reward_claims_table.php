<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_invite_traffic_reward_claims')
            || Schema::hasColumn('v2_invite_traffic_reward_claims', 'reward_balance')) {
            return;
        }

        Schema::table('v2_invite_traffic_reward_claims', function (Blueprint $table) {
            $table->unsignedInteger('reward_balance')->default(0)->after('extension_seconds');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_invite_traffic_reward_claims')
            && Schema::hasColumn('v2_invite_traffic_reward_claims', 'reward_balance')) {
            Schema::table('v2_invite_traffic_reward_claims', function (Blueprint $table) {
                $table->dropColumn('reward_balance');
            });
        }
    }
};
