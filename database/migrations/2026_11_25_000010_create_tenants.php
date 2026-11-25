<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('subdomain', 100)->unique();
            $table->string('database_name', 100);
            $table->string('status', 20)->default('active');
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
