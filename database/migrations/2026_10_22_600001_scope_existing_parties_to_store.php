<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('parties', 'store_id')) {
            return;
        }

        $storeId = DB::table('stores')->where('code', 'MAIN')->value('id')
            ?? DB::table('stores')->orderBy('id')->value('id');

        if ($storeId === null) {
            throw new RuntimeException('برای محدودکردن Parties به فروشگاه، فروشگاه پیش‌فرض الزامی است.');
        }

        Schema::table('parties', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        DB::table('parties')->update(['store_id' => $storeId]);

        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['store_id', 'code']);
            $table->foreignId('store_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('parties', 'store_id')) {
            return;
        }

        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'code']);
            $table->dropConstrainedForeignId('store_id');
            $table->string('code', 30)->unique()->change();
        });
    }
};
