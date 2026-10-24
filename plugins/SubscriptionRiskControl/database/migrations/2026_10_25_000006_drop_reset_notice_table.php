<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Invalid subscription Tokens are now handled generically at the route
     * boundary. Historical Token hashes are no longer collected or needed.
     */
    public function up(): void
    {
        Schema::dropIfExists('v2_subscription_risk_reset_notices');
    }

    public function down(): void
    {
        // The preceding migration owns this table. Do not recreate it here.
    }
};
