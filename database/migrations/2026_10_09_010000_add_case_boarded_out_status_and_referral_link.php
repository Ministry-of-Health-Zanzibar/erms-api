<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addBoardedOutReferralLink();
        $this->allowBoardedOutCaseStatus();
        $this->backfillBoardedOutReferralLinks();
        $this->backfillBoardedOutCaseStatuses();
    }

    public function down(): void
    {
        if (Schema::hasTable('patient_histories')) {
            DB::table('patient_histories')
                ->where('status', 'boarded_out')
                ->update(['status' => 'confirmed']);
        }

        $this->removeBoardedOutCaseStatus();

        if (Schema::hasTable('boarded_out_letters') && Schema::hasColumn('boarded_out_letters', 'referral_id')) {
            Schema::table('boarded_out_letters', function (Blueprint $table): void {
                $table->dropForeign(['referral_id']);
                $table->dropIndex('boarded_out_letters_referral_idx');
                $table->dropColumn('referral_id');
            });
        }
    }

    private function addBoardedOutReferralLink(): void
    {
        if (! Schema::hasTable('boarded_out_letters') || Schema::hasColumn('boarded_out_letters', 'referral_id')) {
            return;
        }

        Schema::table('boarded_out_letters', function (Blueprint $table): void {
            // Nullable keeps virtual boarded-out cases working when there is
            // no hospital referral row.
            $table->unsignedBigInteger('referral_id')->nullable()->after('patient_histories_id');
            $table->index('referral_id', 'boarded_out_letters_referral_idx');
            $table->foreign('referral_id')
                ->references('referral_id')
                ->on('referrals')
                ->nullOnDelete();
        });
    }

    private function allowBoardedOutCaseStatus(): void
    {
        if (! Schema::hasTable('patient_histories')) {
            return;
        }

        $statuses = "'pending', 'reviewed', 'assigned', 'requested', 'approved', 'confirmed', 'boarded_out', 'rejected'";
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE patient_histories DROP CONSTRAINT IF EXISTS patient_histories_status_check');
            DB::statement(
                "ALTER TABLE patient_histories ADD CONSTRAINT patient_histories_status_check CHECK (status IN ({$statuses}))"
            );
        } elseif ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE patient_histories MODIFY status ENUM({$statuses}) NOT NULL DEFAULT 'pending'"
            );
        }
    }

    private function removeBoardedOutCaseStatus(): void
    {
        if (! Schema::hasTable('patient_histories')) {
            return;
        }

        $statuses = "'pending', 'reviewed', 'assigned', 'requested', 'approved', 'confirmed', 'rejected'";
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE patient_histories DROP CONSTRAINT IF EXISTS patient_histories_status_check');
            DB::statement(
                "ALTER TABLE patient_histories ADD CONSTRAINT patient_histories_status_check CHECK (status IN ({$statuses}))"
            );
        } elseif ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE patient_histories MODIFY status ENUM({$statuses}) NOT NULL DEFAULT 'pending'"
            );
        }
    }

    private function backfillBoardedOutReferralLinks(): void
    {
        if (! Schema::hasColumn('boarded_out_letters', 'referral_id')) {
            return;
        }

        DB::table('boarded_out_letters')
            ->whereNull('referral_id')
            ->orderBy('id')
            ->get(['id', 'patient_histories_id'])
            ->each(function (object $letter): void {
                $patientId = DB::table('patient_histories')
                    ->where('patient_histories_id', $letter->patient_histories_id)->value('patient_id');
                // A patient may have several separate cases. Do not attach a
                // letter to the newest referral merely because the patient matches.
                if ($patientId === null || DB::table('patient_histories')->where('patient_id', $patientId)->count() !== 1) {
                    return;
                }
                $referralIds = DB::table('referrals')
                    ->where('patient_id', $patientId)
                    ->where('referrals.status', 'BoardedOut')
                    ->whereNull('referrals.deleted_at')
                    ->pluck('referrals.referral_id');

                if ($referralIds->count() === 1) {
                    DB::table('boarded_out_letters')
                        ->where('id', $letter->id)
                        ->update(['referral_id' => $referralIds->first()]);
                }
            });
    }

    private function backfillBoardedOutCaseStatuses(): void
    {
        if (! Schema::hasTable('patient_histories') || ! Schema::hasTable('boarded_out_letters')) {
            return;
        }

        $boardedOutHistoryIds = DB::table('boarded_out_letters')
            ->whereNull('deleted_at')
            ->whereNotNull('patient_histories_id')
            ->select('patient_histories_id')
            ->distinct();

        DB::table('patient_histories')
            ->whereIn('patient_histories_id', $boardedOutHistoryIds)
            ->where('status', 'confirmed')
            ->update(['status' => 'boarded_out']);
    }
};
