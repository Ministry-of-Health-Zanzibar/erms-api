<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referrals') && ! Schema::hasColumn('referrals', 'telemedicine_request_id')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->unsignedBigInteger('telemedicine_request_id')->nullable()->after('patient_histories_id');
                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->nullOnDelete();
                $table->index('telemedicine_request_id', 'referrals_telemedicine_request_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('referrals') && Schema::hasColumn('referrals', 'telemedicine_request_id')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->dropForeign(['telemedicine_request_id']);
                $table->dropIndex('referrals_telemedicine_request_idx');
                $table->dropColumn('telemedicine_request_id');
            });
        }
    }
};
