<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('DROP INDEX uniq_active_user ON subscriptions');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE subscriptions DROP COLUMN active_user_id');
        } catch (\Throwable $e) {
        }

        //    (NULL dönenler unlimited tekrar eder, active olan sadece 1)
        DB::statement("
            CREATE UNIQUE INDEX uniq_active_user
            ON subscriptions ((CASE WHEN status='active' THEN user_id END))
        ");
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX uniq_active_user ON subscriptions');
        } catch (\Throwable $e) {
        }
    }
};
