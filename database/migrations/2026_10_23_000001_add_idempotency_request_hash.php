<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('idempotency_request_hash', 64)->nullable()->after('idempotency_key');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_request_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('idempotency_request_hash');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('idempotency_request_hash');
        });
    }
};
