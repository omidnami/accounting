<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('created_by');
            $table->string('idempotency_request_hash', 64)->nullable()->after('idempotency_key');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_request_hash']);
        });
    }
};
