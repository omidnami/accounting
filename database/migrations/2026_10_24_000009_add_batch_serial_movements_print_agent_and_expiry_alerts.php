<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'track_batches')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('track_batches')->default(false)->after('stock_policy');
            });
        }

        if (! Schema::hasColumn('products', 'track_serials')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('track_serials')->default(false)->after('track_batches');
            });
        }

        if (! Schema::hasColumn('invoice_items', 'purchase_batch_number')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('purchase_batch_number', 80)->nullable()->after('sort_order');
            });
        }

        if (! Schema::hasColumn('invoice_items', 'purchase_expiry_date')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->date('purchase_expiry_date')->nullable()->after('purchase_batch_number');
            });
        }

        if (! Schema::hasColumn('invoice_items', 'purchase_serial_numbers')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->json('purchase_serial_numbers')->nullable()->after('purchase_expiry_date');
            });
        }

        if (Schema::hasTable('inventory_batch_balances')
            && DB::table('inventory_batch_balances')->count() === 0) {
            Schema::drop('inventory_batch_balances');
        }

        Schema::create('inventory_batch_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_batch_id']);
            $table->index(['store_id', 'branch_id', 'product_batch_id'], 'inventory_batch_scope_idx');
        });

        Schema::create('inventory_movement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('total_cost', 18, 2);
            $table->timestamps();

            $table->unique(['inventory_movement_id', 'product_batch_id'], 'movement_batch_unique');
            $table->index(['product_batch_id', 'inventory_movement_id'], 'batch_movement_idx');
        });

        Schema::create('inventory_movement_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_serial_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['inventory_movement_id', 'product_serial_id'], 'movement_serial_unique');
            $table->index(['product_serial_id', 'inventory_movement_id'], 'serial_movement_idx');
        });

        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('severity', 20);
            $table->string('message', 255);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['product_batch_id', 'type']);
            $table->index(['store_id', 'branch_id', 'acknowledged_at']);
        });

        Schema::create('print_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('platform', 40);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'branch_id', 'status']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('print_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('template', 60);
            $table->unsignedInteger('template_version')->default(1);
            $table->json('payload');
            $table->unsignedSmallInteger('paper_width_mm')->default(80);
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['print_station_id', 'status', 'expires_at']);
            $table->index(['store_id', 'branch_id', 'created_at']);
        });

        Schema::create('print_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('print_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('print_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['print_job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_events');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_stations');
        Schema::dropIfExists('inventory_alerts');
        Schema::dropIfExists('inventory_movement_serials');
        Schema::dropIfExists('inventory_movement_batches');
        Schema::dropIfExists('inventory_batch_balances');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_batch_number',
                'purchase_expiry_date',
                'purchase_serial_numbers',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['track_batches', 'track_serials']);
        });
    }
};
