<?php

namespace Tests\Feature\Patients;

use App\Models\PatientHistory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PatientHistorySubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['activitylog.enabled' => false]);
        $socket = getenv('CASE_REPORT_TEST_PG_SOCKET');
        if ($socket) {
            $this->assertStringStartsWith('/private/tmp/eris-case-tests.', $socket);
            $schema = 'submission_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'submission_tests', 'database.connections.submission_tests' => [
                'driver' => 'pgsql', 'host' => $socket, 'port' => 55439, 'database' => 'postgres',
                'username' => 'case_tests', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema,
            ]]);
            DB::purge('submission_tests');
            DB::statement('CREATE SCHEMA "'.$schema.'"');
        } else {
            if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                $this->markTestSkipped('Requires isolated SQLite or CASE_REPORT_TEST_PG_SOCKET.');
            }
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
        Schema::create('patients', function (Blueprint $t): void {
            $t->bigIncrements('patient_id'); $t->string('name'); $t->softDeletes();
        });
        Schema::create('patient_histories', function (Blueprint $t): void {
            $t->bigIncrements('patient_histories_id'); $t->integer('patient_id'); $t->integer('reason_id');
            // Exercise a real legacy database default, not just an in-memory model.
            $t->string('status')->default('pending'); $t->string('case_type')->default('Emergency');
            $t->string('referring_doctor')->nullable(); $t->date('referring_date')->nullable();
            $t->integer('mkurugenzi_tiba_id')->nullable(); $t->integer('dg_id')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('referrals', function (Blueprint $t): void {
            $t->bigIncrements('referral_id'); $t->integer('patient_id'); $t->string('status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('reasons', function (Blueprint $t): void {
            $t->bigIncrements('reason_id'); $t->string('referral_reason_name'); $t->softDeletes();
        });
        Schema::create('diagnoses', function (Blueprint $t): void {
            $t->bigIncrements('diagnosis_id'); $t->string('diagnosis_name'); $t->softDeletes();
        });
        Schema::create('history_diagnosis', function (Blueprint $t): void {
            $t->integer('patient_histories_id'); $t->integer('diagnosis_id'); $t->string('added_by');
        });
        DB::table('patients')->insert(['patient_id' => 1, 'name' => 'Test patient']);
        DB::table('reasons')->insert(['reason_id' => 1, 'referral_reason_name' => 'Further treatment']);
        DB::table('diagnoses')->insert(['diagnosis_id' => 1, 'diagnosis_name' => 'Test diagnosis']);
        $this->login();
    }

    private function login(bool $allowed = true): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'first_name' => 'Hospital', 'last_name' => 'Doctor', 'is_blocked' => false]);
        $user->shouldReceive('can')->with('Create Patient History')->andReturn($allowed);
        $this->actingAs($user, 'sanctum');
    }

    public function test_add_history_enters_the_medical_board_queue_without_forging_approval(): void
    {
        $this->postJson('/api/patient-histories', [
            'patient_id' => 1, 'reason_id' => 1, 'case_type' => 'Emergency', 'diagnosis_ids' => [1],
            'status' => 'confirmed', 'dg_id' => 999, 'mkurugenzi_tiba_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.status_tracking.stage', 1)
            ->assertJsonPath('data.status_tracking.label', 'Awaiting Medical Board')
            ->assertJsonPath('data.progress_percentage', '20%')
            ->assertJsonPath('data.diagnoses.0.diagnosis_id', 1);
        $history = PatientHistory::firstOrFail();
        $this->assertSame('reviewed', $history->status);
        $this->assertNull($history->dg_id);
        $this->assertNull($history->mkurugenzi_tiba_id);
        $this->assertSame(1, (int) $history->reason_id);
    }

    public function test_new_model_persists_the_queue_default_even_with_a_pending_database_default(): void
    {
        $history = PatientHistory::create(['patient_id' => 1, 'reason_id' => 1]);
        $this->assertSame('reviewed', $history->fresh()->status);
    }

    public function test_existing_eligibility_and_create_permission_are_unchanged(): void
    {
        DB::table('referrals')->insert(['patient_id' => 1, 'status' => 'Confirmed', 'created_at' => now()]);
        $this->postJson('/api/patient-histories', ['patient_id' => 1, 'reason_id' => 1])->assertStatus(409);
        $this->assertDatabaseCount('patient_histories', 0);
        $this->login(false);
        $this->postJson('/api/patient-histories', ['patient_id' => 1, 'reason_id' => 1])->assertForbidden();
    }
}
