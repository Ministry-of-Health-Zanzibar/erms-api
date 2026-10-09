<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (Schema::hasTable('patient_histories')) {
                DB::statement(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS patient_histories_active_status_idx '
                    .'ON patient_histories (status) WHERE deleted_at IS NULL'
                );
                DB::statement(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS patient_histories_active_created_idx '
                    .'ON patient_histories (created_at) WHERE deleted_at IS NULL'
                );
            }

            if (Schema::hasTable('boarded_out_letters')) {
                DB::statement(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS boarded_out_letters_history_idx '
                    .'ON boarded_out_letters (patient_histories_id)'
                );
            }

            if (Schema::hasTable('referrals')) {
                DB::statement(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS referrals_active_status_idx '
                    .'ON referrals (status) WHERE deleted_at IS NULL'
                );
            }

            return;
        }

        // Keep local non-PostgreSQL environments usable without partial-index
        // syntax; the composite indexes still support the report filters.
        if (Schema::hasTable('patient_histories')) {
            Schema::table('patient_histories', function ($table): void {
                $table->index(['status', 'deleted_at'], 'patient_histories_active_status_idx');
                $table->index(['created_at', 'deleted_at'], 'patient_histories_active_created_idx');
            });
        }

        if (Schema::hasTable('boarded_out_letters')) {
            Schema::table('boarded_out_letters', function ($table): void {
                $table->index('patient_histories_id', 'boarded_out_letters_history_idx');
            });
        }

        if (Schema::hasTable('referrals')) {
            Schema::table('referrals', function ($table): void {
                $table->index(['status', 'deleted_at'], 'referrals_active_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'patient_histories_active_status_idx',
                'patient_histories_active_created_idx',
                'boarded_out_letters_history_idx',
                'referrals_active_status_idx',
            ] as $index) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
            }

            return;
        }

        foreach ([
            ['patient_histories', 'patient_histories_active_status_idx'],
            ['patient_histories', 'patient_histories_active_created_idx'],
            ['boarded_out_letters', 'boarded_out_letters_history_idx'],
            ['referrals', 'referrals_active_status_idx'],
        ] as [$table, $index]) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function ($blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }
        }
    }
};
