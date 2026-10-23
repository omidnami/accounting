<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->string('document_number', 50)
                ->nullable()
                ->after('idempotency_request_hash');
            $table->dropUnique(['bank_name', 'check_number', 'type']);
            $table->index(
                ['store_id', 'bank_name', 'check_number', 'type'],
                'checks_external_number_index',
            );
        });

        DB::table('checks')
            ->whereNull('document_number')
            ->orderBy('id')
            ->each(function (object $check): void {
                DB::table('checks')
                    ->where('id', $check->id)
                    ->update([
                        'document_number' => sprintf('CK-LEGACY-%06d', $check->id),
                    ]);
            });

        Schema::table('checks', function (Blueprint $table) {
            $table->unique(
                ['store_id', 'document_number'],
                'checks_store_document_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropUnique('checks_store_document_number_unique');
            $table->dropIndex('checks_external_number_index');
            $table->unique(['bank_name', 'check_number', 'type']);
            $table->dropColumn('document_number');
        });
    }
};
