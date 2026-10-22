<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->unique()->after('receipt_number');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('pending')
                ->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->unique()->after('payment_number');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('confirmed')
                ->change();
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('confirmed')
                ->change();
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
