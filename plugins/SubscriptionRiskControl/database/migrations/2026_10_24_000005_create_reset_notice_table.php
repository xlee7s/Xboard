<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_subscription_risk_reset_notices')) {
            return;
        }

        Schema::create('v2_subscription_risk_reset_notices', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->unsignedInteger('user_id')->index();
            $table->string('action', 32);
            $table->unsignedInteger('expires_at')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_risk_reset_notices');
    }
};
