<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = 'v2_subscription_risk_requests';

        if (!Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->bigIncrements('id');
                $blueprint->unsignedInteger('user_id')->index();
                $blueprint->string('ip', 45)->nullable();
                $blueprint->string('user_agent', 512)->nullable();
                $blueprint->char('user_agent_hash', 64);
                $blueprint->unsignedInteger('requested_at')->index();
                $blueprint->unsignedInteger('created_at');
                $blueprint->index(['user_id', 'requested_at']);
            });
            return;
        }

        // Earlier test builds could leave a partial request-log table behind.
        // Keep all existing rows and only add the fields used by the dashboard.
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (!Schema::hasColumn($table, 'user_id')) {
                $blueprint->unsignedInteger('user_id')->nullable()->index();
            }
            if (!Schema::hasColumn($table, 'ip')) {
                $blueprint->string('ip', 45)->nullable();
            }
            if (!Schema::hasColumn($table, 'user_agent')) {
                $blueprint->string('user_agent', 512)->nullable();
            }
            if (!Schema::hasColumn($table, 'user_agent_hash')) {
                $blueprint->char('user_agent_hash', 64)->nullable();
            }
            if (!Schema::hasColumn($table, 'is_allowed')) {
                $blueprint->boolean('is_allowed')->default(true)->index();
            }
            if (!Schema::hasColumn($table, 'block_reason')) {
                $blueprint->string('block_reason', 255)->nullable();
            }
            if (!Schema::hasColumn($table, 'requested_at')) {
                $blueprint->unsignedInteger('requested_at')->nullable()->index();
            }
            if (!Schema::hasColumn($table, 'created_at')) {
                $blueprint->unsignedInteger('created_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // This migration repairs existing data and intentionally has no rollback.
    }
};
