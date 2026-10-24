<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $requests = 'v2_subscription_risk_requests';
        if (!Schema::hasTable($requests)) {
            return;
        }

        Schema::table($requests, function (Blueprint $table) use ($requests) {
            if (!Schema::hasColumn($requests, 'is_allowed')) {
                $table->boolean('is_allowed')->default(true)->index();
            }
            if (!Schema::hasColumn($requests, 'block_reason')) {
                $table->string('block_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        $requests = 'v2_subscription_risk_requests';
        if (!Schema::hasTable($requests)) {
            return;
        }

        Schema::table($requests, function (Blueprint $table) use ($requests) {
            if (Schema::hasColumn($requests, 'is_allowed')) {
                $table->dropIndex(['is_allowed']);
                $table->dropColumn('is_allowed');
            }
            if (Schema::hasColumn($requests, 'block_reason')) {
                $table->dropColumn('block_reason');
            }
        });
    }
};
