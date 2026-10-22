<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('idempotency_key', 120)
                ->nullable()
                ->after('invoice_number')
                ->unique();
            $table->string('idempotency_request_hash', 64)
                ->nullable()
                ->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_request_hash']);
        });
    }
};
