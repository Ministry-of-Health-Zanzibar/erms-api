<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telemedicine_requests')) {
            Schema::create('telemedicine_requests', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_request_id');
                $table->string('reference', 40)->unique();
                $table->string('request_type', 40);
                $table->string('status', 50)->default('registered')->index();
                $table->unsignedBigInteger('patient_id');
                $table->unsignedBigInteger('patient_histories_id')->nullable();
                $table->unsignedBigInteger('referral_id')->nullable();
                $table->unsignedBigInteger('requesting_doctor_id')->nullable();
                $table->unsignedBigInteger('requesting_facility_id')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->text('reason')->nullable();
                $table->text('clinical_notes')->nullable();
                $table->text('dcs_comments')->nullable();
                $table->unsignedBigInteger('dcs_decided_by')->nullable();
                $table->timestamp('dcs_decided_at')->nullable();
                $table->text('decision_comments')->nullable();
                $table->string('final_decision', 50)->nullable();
                $table->unsignedBigInteger('decision_by')->nullable();
                $table->timestamp('decision_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('patient_id')->references('patient_id')->on('patients');
                $table->foreign('patient_histories_id')->references('patient_histories_id')->on('patient_histories')->nullOnDelete();
                $table->foreign('referral_id')->references('referral_id')->on('referrals')->nullOnDelete();
                $table->foreign('requesting_doctor_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('requesting_facility_id')->references('hospital_id')->on('hospitals')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users');
                $table->foreign('dcs_decided_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('decision_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['patient_id', 'request_type', 'status'], 'telemedicine_patient_type_status_idx');
                $table->index(['patient_histories_id', 'status'], 'telemedicine_history_status_idx');
            });
        }

        if (! Schema::hasTable('telemedicine_request_documents')) {
            Schema::create('telemedicine_request_documents', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_request_document_id');
                $table->unsignedBigInteger('telemedicine_request_id');
                $table->unsignedBigInteger('uploaded_by');
                $table->string('document_type', 80)->nullable();
                $table->string('original_name', 255);
                $table->string('file_path', 500);
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamps();

                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->cascadeOnDelete();
                $table->foreign('uploaded_by')->references('id')->on('users');
                $table->index('telemedicine_request_id', 'telemedicine_request_documents_request_idx');
            });
        }

        if (! Schema::hasTable('telemedicine_appointments')) {
            Schema::create('telemedicine_appointments', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_appointment_id');
                $table->unsignedBigInteger('telemedicine_request_id');
                $table->unsignedBigInteger('hospital_id');
                $table->unsignedBigInteger('telemedicine_coordinator_id');
                $table->unsignedBigInteger('assigned_doctor_id');
                $table->unsignedBigInteger('scheduled_by');
                $table->timestamp('scheduled_at');
                $table->string('status', 50)->default('proposed')->index();
                $table->text('doctor_comments')->nullable();
                $table->text('reschedule_reason')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->cascadeOnDelete();
                $table->foreign('hospital_id')->references('hospital_id')->on('hospitals');
                $table->foreign('telemedicine_coordinator_id')->references('id')->on('users');
                $table->foreign('assigned_doctor_id')->references('id')->on('users');
                $table->foreign('scheduled_by')->references('id')->on('users');
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['telemedicine_request_id', 'status'], 'telemedicine_appointments_request_status_idx');
                $table->index(['assigned_doctor_id', 'scheduled_at'], 'telemedicine_appointments_doctor_date_idx');
            });
        }

        if (! Schema::hasTable('telemedicine_reports')) {
            Schema::create('telemedicine_reports', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_report_id');
                $table->unsignedBigInteger('telemedicine_request_id');
                $table->unsignedBigInteger('telemedicine_appointment_id')->nullable();
                $table->unsignedBigInteger('recorded_by');
                $table->text('report_text')->nullable();
                $table->string('original_name', 255)->nullable();
                $table->string('file_path', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->cascadeOnDelete();
                $table->foreign('telemedicine_appointment_id')
                    ->references('telemedicine_appointment_id')
                    ->on('telemedicine_appointments')
                    ->nullOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users');
                $table->index('telemedicine_request_id', 'telemedicine_reports_request_idx');
            });
        }

        if (! Schema::hasTable('telemedicine_decisions')) {
            Schema::create('telemedicine_decisions', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_decision_id');
                $table->unsignedBigInteger('telemedicine_request_id');
                $table->unsignedBigInteger('telemedicine_appointment_id')->nullable();
                $table->unsignedBigInteger('decided_by');
                $table->string('decision', 60);
                $table->text('comments');
                $table->unsignedBigInteger('referral_id')->nullable();
                $table->timestamp('decided_at');
                $table->timestamps();

                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->cascadeOnDelete();
                $table->foreign('telemedicine_appointment_id')
                    ->references('telemedicine_appointment_id')
                    ->on('telemedicine_appointments')
                    ->nullOnDelete();
                $table->foreign('decided_by')->references('id')->on('users');
                $table->foreign('referral_id')->references('referral_id')->on('referrals')->nullOnDelete();
                $table->index('telemedicine_request_id', 'telemedicine_decisions_request_idx');
            });
        }

        if (! Schema::hasTable('telemedicine_workflow_events')) {
            Schema::create('telemedicine_workflow_events', function (Blueprint $table): void {
                $table->bigIncrements('telemedicine_workflow_event_id');
                $table->unsignedBigInteger('telemedicine_request_id');
                $table->string('action', 100);
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('telemedicine_request_id')
                    ->references('telemedicine_request_id')
                    ->on('telemedicine_requests')
                    ->cascadeOnDelete();
                $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['telemedicine_request_id', 'created_at'], 'telemedicine_events_request_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_workflow_events');
        Schema::dropIfExists('telemedicine_decisions');
        Schema::dropIfExists('telemedicine_reports');
        Schema::dropIfExists('telemedicine_appointments');
        Schema::dropIfExists('telemedicine_request_documents');
        Schema::dropIfExists('telemedicine_requests');
    }
};
