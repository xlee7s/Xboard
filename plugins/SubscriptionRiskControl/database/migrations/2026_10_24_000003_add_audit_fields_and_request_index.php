<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $events = 'v2_subscription_risk_events';
        if (Schema::hasTable($events)) {
            Schema::table($events, function (Blueprint $table) use ($events) {
                if (!Schema::hasColumn($events, 'actor_admin_id')) {
                    $table->unsignedInteger('actor_admin_id')->nullable()->index();
                }
                if (!Schema::hasColumn($events, 'actor_admin_email')) {
                    $table->string('actor_admin_email', 255)->nullable();
                }
                if (!Schema::hasColumn($events, 'actor_ip')) {
                    $table->string('actor_ip', 45)->nullable();
                }
            });
        }

        $requests = 'v2_subscription_risk_requests';
        if (Schema::hasTable($requests)) {
            Schema::table($requests, function (Blueprint $table) {
                $table->index(['requested_at', 'user_id'], 'src_requests_requested_user_index');
            });
        }
    }

    public function down(): void
    {
        $requests = 'v2_subscription_risk_requests';
        if (Schema::hasTable($requests)) {
            Schema::table($requests, function (Blueprint $table) {
                $table->dropIndex('src_requests_requested_user_index');
            });
        }

        $events = 'v2_subscription_risk_events';
        if (Schema::hasTable($events)) {
            Schema::table($events, function (Blueprint $table) use ($events) {
                if (Schema::hasColumn($events, 'actor_admin_id')) {
                    $table->dropIndex(['actor_admin_id']);
                    $table->dropColumn('actor_admin_id');
                }
                if (Schema::hasColumn($events, 'actor_admin_email')) {
                    $table->dropColumn('actor_admin_email');
                }
                if (Schema::hasColumn($events, 'actor_ip')) {
                    $table->dropColumn('actor_ip');
                }
            });
        }
    }
};
