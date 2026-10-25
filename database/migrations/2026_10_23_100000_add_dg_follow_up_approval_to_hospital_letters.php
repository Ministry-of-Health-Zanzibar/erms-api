<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospital_letters', function (Blueprint $table): void {
            // Existing follow-ups do not need a new approval step. New
            // hospital-user submissions explicitly opt into the pending state.
            $table->string('dg_approval_status', 30)
                ->default('not_required')
                ->after('outcome');
            $table->unsignedBigInteger('proposed_hospital_id')->nullable()->after('dg_approval_status');
            $table->unsignedBigInteger('proposed_by')->nullable()->after('proposed_hospital_id');
            $table->timestamp('dg_approval_requested_at')->nullable()->after('proposed_by');
            $table->unsignedBigInteger('dg_approved_by')->nullable()->after('dg_approval_requested_at');
            $table->timestamp('dg_approved_at')->nullable()->after('dg_approved_by');
            $table->text('dg_comments')->nullable()->after('dg_approved_at');

            $table->foreign('proposed_hospital_id')
                ->references('hospital_id')
                ->on('hospitals')
                ->nullOnDelete();
            $table->foreign('proposed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('dg_approved_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['dg_approval_status', 'letter_id'], 'hospital_letters_dg_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hospital_letters', function (Blueprint $table): void {
            $table->dropForeign(['proposed_hospital_id']);
            $table->dropForeign(['proposed_by']);
            $table->dropForeign(['dg_approved_by']);
            $table->dropIndex('hospital_letters_dg_approval_idx');
            $table->dropColumn([
                'dg_approval_status',
                'proposed_hospital_id',
                'proposed_by',
                'dg_approval_requested_at',
                'dg_approved_by',
                'dg_approved_at',
                'dg_comments',
            ]);
        });
    }
};
