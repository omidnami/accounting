<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_name', 255)->nullable()->after('user_id');
            $table->string('actor_email', 255)->nullable()->after('actor_name');
        });

        DB::table('audit_logs')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function (object $log): void {
                $actor = DB::table('users')
                    ->where('id', $log->user_id)
                    ->first(['name', 'email']);

                if ($actor) {
                    DB::table('audit_logs')
                        ->where('id', $log->id)
                        ->update([
                            'actor_name' => $actor->name,
                            'actor_email' => $actor->email,
                        ]);
                }
            });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        $this->createAppendOnlyTriggers();
    }

    public function down(): void
    {
        $this->dropAppendOnlyTriggers();

        DB::table('audit_logs')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', DB::table('users')->select('id'))
            ->update(['user_id' => null]);

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->dropColumn(['actor_name', 'actor_email']);
        });
    }

    private function createAppendOnlyTriggers(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs are append-only'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs are append-only'
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs are append-only');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs are append-only');
                END
                SQL);
        }
    }

    private function dropAppendOnlyTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
