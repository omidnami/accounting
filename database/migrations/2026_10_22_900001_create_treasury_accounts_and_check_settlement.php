<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('gl_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->enum('type', ['cash', 'bank', 'card']);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['store_id', 'branch_id', 'type', 'is_active']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('treasury_account_id')->nullable()->after('payment_method')
                ->constrained('treasury_accounts')->restrictOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'bounced', 'cancelled'])
                ->default('pending')->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('treasury_account_id')->nullable()->after('payment_method')
                ->constrained('treasury_accounts')->restrictOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'bounced', 'cancelled'])
                ->default('pending')->change();
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->foreignId('settlement_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('bounce_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bounce_entry_id');
            $table->dropConstrainedForeignId('settlement_entry_id');
            $table->dropColumn(['settled_at', 'bounced_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treasury_account_id');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('pending')->change();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treasury_account_id');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])
                ->default('pending')->change();
        });

        Schema::dropIfExists('treasury_accounts');
    }
};
