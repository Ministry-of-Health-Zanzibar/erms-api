<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('case_journey_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Retain the evidence if a related record is later soft-deleted.
            // Reporting still requires an authorized, existing patient/case.
            $table->unsignedBigInteger('patient_histories_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('referral_id');
            $table->unsignedBigInteger('letter_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_kind');
            $table->string('outcome')->nullable();
            $table->timestamp('occurred_at');
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['patient_histories_id', 'occurred_at']);
            $table->index(['letter_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_journey_events');
    }
};
