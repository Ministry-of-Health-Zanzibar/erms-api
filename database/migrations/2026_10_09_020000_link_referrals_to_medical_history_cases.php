<?php

use App\Services\ReferralCaseLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('referrals', 'patient_histories_id')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->unsignedBigInteger('patient_histories_id')->nullable();
                $table->index('patient_histories_id', 'referrals_case_idx');
                $table->foreign('patient_histories_id')->references('patient_histories_id')->on('patient_histories')->restrictOnDelete();
            });
        }
        app(ReferralCaseLinker::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropForeign(['patient_histories_id']);
            $table->dropIndex('referrals_case_idx');
            $table->dropColumn('patient_histories_id');
        });
    }
};
