<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name', 100);
            $table->string('aggregate_type', 150);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('available_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events_outbox');
    }
};
