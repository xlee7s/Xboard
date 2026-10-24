<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_subscription_risk_banlist')) {
            return;
        }

        Schema::create('v2_subscription_risk_banlist', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->text('reasons')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->unsignedInteger('banned_at')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_risk_banlist');
    }
};
