<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_subscription_risk_requests')) {
            Schema::create('v2_subscription_risk_requests', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->char('user_agent_hash', 64);
                $table->unsignedInteger('requested_at')->index();
                $table->unsignedInteger('created_at');
                $table->index(['user_id', 'requested_at']);
            });
        }

        if (!Schema::hasTable('v2_subscription_risk_events')) {
            Schema::create('v2_subscription_risk_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('action', 32);
                $table->string('risk_level', 16);
                $table->text('reasons')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->unsignedInteger('normal_requests')->default(0);
                $table->unsignedInteger('normal_ips')->default(0);
                $table->unsignedInteger('normal_user_agents')->default(0);
                $table->unsignedInteger('high_requests')->default(0);
                $table->unsignedInteger('high_ips')->default(0);
                $table->unsignedInteger('high_user_agents')->default(0);
                $table->unsignedInteger('occurred_at')->index();
                $table->unsignedInteger('created_at');
                $table->index(['user_id', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('v2_subscription_risk_watchlist')) {
            Schema::create('v2_subscription_risk_watchlist', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->unsignedInteger('first_flagged_at')->index();
                $table->unsignedInteger('last_abnormal_at')->index();
                $table->string('last_action', 32);
                $table->text('last_reasons')->nullable();
                $table->unsignedInteger('hit_count')->default(1);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }

        if (!Schema::hasTable('v2_subscription_risk_banlist')) {
            Schema::create('v2_subscription_risk_banlist', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->text('reasons')->nullable();
                $table->unsignedInteger('hit_count')->default(0);
                $table->unsignedInteger('banned_at')->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_risk_banlist');
        Schema::dropIfExists('v2_subscription_risk_watchlist');
        Schema::dropIfExists('v2_subscription_risk_events');
        Schema::dropIfExists('v2_subscription_risk_requests');
    }
};
