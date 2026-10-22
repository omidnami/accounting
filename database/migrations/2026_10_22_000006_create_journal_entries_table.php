<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 50)->unique();
            $table->date('entry_date');
            $table->text('description');
            $table->enum('status', [
                'draft',
                'posted',
                'reversed',
                'cancelled',
            ])->default('draft');
            $table->nullableMorphs('source');
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['entry_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
