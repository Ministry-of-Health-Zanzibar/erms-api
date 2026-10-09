<?php

use App\Services\TransferReferralService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospital_letters', function (Blueprint $table): void {
            $table->unsignedBigInteger('transferred_referral_id')->nullable()->unique();
            $table->foreign('transferred_referral_id')->references('referral_id')->on('referrals')->restrictOnDelete();
            $table->uuid('submission_key')->nullable()->unique();
        });
        app(TransferReferralService::class)->repairLegacy(true);
    }

    public function down(): void
    {
        // Generated letters are legitimate records and are deliberately retained.
        Schema::table('hospital_letters', function (Blueprint $table): void {
            $table->dropForeign(['transferred_referral_id']);
            $table->dropUnique(['transferred_referral_id']);
            $table->dropUnique(['submission_key']);
            $table->dropColumn(['transferred_referral_id', 'submission_key']);
        });
    }
};
