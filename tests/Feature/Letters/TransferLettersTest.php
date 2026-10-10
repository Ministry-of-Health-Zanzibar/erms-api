<?php

namespace Tests\Feature\Letters;

use App\Models\FollowUp;
use App\Models\HospitalLetter;
use App\Models\Referral;
use App\Models\ReferralLetter;
use App\Models\User;
use App\Services\Letters\LetterDocumentService;
use App\Services\Reports\CaseReport;
use App\Services\TransferReferralService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TransferLettersTest extends TestCase
{
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 12:00:00');
        config(['activitylog.enabled' => false]);
        $socket = getenv('CASE_REPORT_TEST_PG_SOCKET');
        if ($socket) {
            $this->assertStringStartsWith('/private/tmp/eris-case-tests.', $socket);
            $schema = 'transfer_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'transfer_tests', 'database.connections.transfer_tests' => [
                'driver' => 'pgsql', 'host' => $socket, 'port' => 55439, 'database' => 'postgres',
                'username' => 'case_tests', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema,
            ]]);
            DB::purge('transfer_tests');
            DB::statement('CREATE SCHEMA "'.$schema.'"');
        } else {
            if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                $this->markTestSkipped('Requires isolated SQLite or CASE_REPORT_TEST_PG_SOCKET.');
            }
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->string('first_name'); $t->string('middle_name')->nullable(); $t->string('last_name'); $t->string('email'); $t->softDeletes();
        });
        Schema::create('patients', function (Blueprint $t): void {
            $t->bigIncrements('patient_id'); $t->string('name'); $t->integer('created_by'); $t->string('date_of_birth')->nullable();
            $t->integer('location_id')->nullable(); $t->softDeletes();
        });
        Schema::create('patient_histories', function (Blueprint $t): void {
            $t->bigIncrements('patient_histories_id'); $t->integer('patient_id'); $t->string('status');
            $t->string('case_type')->default('Emergency'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('referral_types', function (Blueprint $t): void {
            $t->bigIncrements('referral_type_id'); $t->string('referral_type_code'); $t->softDeletes();
        });
        Schema::create('hospitals', function (Blueprint $t): void {
            $t->bigIncrements('hospital_id'); $t->string('hospital_name'); $t->string('hospital_address');
            $t->integer('referral_type_id'); $t->softDeletes();
        });
        Schema::create('referrals', function (Blueprint $t): void {
            $t->bigIncrements('referral_id'); $t->integer('patient_id'); $t->integer('patient_histories_id')->nullable();
            $t->unsignedBigInteger('parent_referral_id')->nullable(); $t->integer('hospital_id')->nullable(); $t->integer('reason_id');
            $t->string('referral_number'); $t->string('status'); $t->integer('confirmed_by')->nullable(); $t->integer('created_by');
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('referral_letters', function (Blueprint $t): void {
            $t->bigIncrements('referral_letter_id'); $t->unsignedBigInteger('referral_id'); $t->string('referral_letter_code')->unique();
            $t->text('letter_text'); $t->string('start_date')->nullable(); $t->string('end_date')->nullable(); $t->integer('created_by');
            $t->boolean('is_printed')->default(false); $t->timestamp('printed_at')->nullable(); $t->integer('printed_by')->nullable();
            $t->integer('print_count')->default(0); $t->string('last_printed_language')->nullable(); $t->timestamps(); $t->softDeletes();
            $t->foreign('referral_id')->references('referral_id')->on('referrals');
        });
        Schema::create('hospital_letters', function (Blueprint $t): void {
            $t->bigIncrements('letter_id'); $t->unsignedBigInteger('referral_id'); $t->text('content_summary')->nullable();
            $t->string('next_appointment_date')->nullable(); $t->string('letter_file')->nullable(); $t->string('outcome');
            $t->boolean('is_printed')->default(false); $t->timestamp('printed_at')->nullable(); $t->integer('printed_by')->nullable();
            $t->integer('print_count')->default(0); $t->string('last_printed_language')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('followups', function (Blueprint $t): void {
            $t->bigIncrements('followup_id'); $t->integer('patient_id'); $t->unsignedBigInteger('letter_id');
            $t->string('followup_date'); $t->string('followup_status'); $t->text('notes')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('diagnoses', function (Blueprint $t): void {
            $t->bigIncrements('diagnosis_id'); $t->string('diagnosis_code'); $t->string('diagnosis_name'); $t->softDeletes();
        });
        Schema::create('diagnosis_referral', function (Blueprint $t): void {
            $t->integer('referral_id'); $t->integer('diagnosis_id'); $t->timestamps();
        });
        Schema::create('referral_flights', function (Blueprint $t): void {
            $t->bigIncrements('referral_flight_id'); $t->integer('referral_id'); $t->softDeletes();
        });
        Schema::create('letter_print_events', function (Blueprint $t): void {
            $t->bigIncrements('letter_print_event_id'); $t->string('letter_type'); $t->unsignedBigInteger('letter_id');
            $t->string('language'); $t->integer('printed_by')->nullable(); $t->timestamp('printed_at');
            $t->string('ip_address')->nullable(); $t->text('user_agent')->nullable(); $t->timestamps();
        });
        foreach (['patient_lists' => 'patient_list_id', 'patient_files' => 'file_id', 'reasons' => 'reason_id'] as $table => $key) {
            Schema::create($table, function (Blueprint $t) use ($key): void {
                $t->bigIncrements($key); $t->integer('patient_id')->nullable(); $t->softDeletes();
            });
        }
        Schema::create('patient_list_patient', function (Blueprint $t): void {
            $t->integer('patient_list_id'); $t->integer('patient_id'); $t->timestamps();
        });
        Schema::create('history_diagnosis', function (Blueprint $t): void {
            $t->integer('patient_histories_id'); $t->integer('diagnosis_id'); $t->string('added_by');
        });
        Schema::create('boarded_out_letters', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->integer('patient_histories_id'); $t->integer('printed_by')->nullable();
            $t->string('receiver')->nullable(); $t->string('reference_number')->nullable(); $t->date('reference_date')->nullable();
            $t->json('recommendations')->nullable(); $t->boolean('is_printed')->default(false); $t->timestamp('printed_at')->nullable();
            $t->integer('print_count')->default(0); $t->string('last_printed_language')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('bills', function (Blueprint $t): void {
            $t->bigIncrements('bill_id'); $t->integer('referral_id'); $t->softDeletes();
        });
        $migration = require database_path('migrations/2026_10_09_030000_link_transfer_followups_and_create_letters.php');
        $migration->up();

        DB::table('users')->insert(['id' => 1, 'first_name' => 'Transfer', 'last_name' => 'Operator', 'email' => 'transfer@example.test']);
        DB::table('patients')->insert(['patient_id' => 1, 'name' => 'Test Patient', 'created_by' => 1]);
        DB::table('patient_histories')->insert(['patient_histories_id' => 1, 'patient_id' => 1, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('referral_types')->insert([['referral_type_id' => 1, 'referral_type_code' => 'REFTYPE1'], ['referral_type_id' => 2, 'referral_type_code' => 'REFTYPE2']]);
        DB::table('hospitals')->insert([
            ['hospital_id' => 1, 'hospital_name' => 'Original Hospital', 'hospital_address' => 'Old Address', 'referral_type_id' => 1],
            ['hospital_id' => 2, 'hospital_name' => 'Transfer Hospital', 'hospital_address' => 'New Address', 'referral_type_id' => 1],
            ['hospital_id' => 3, 'hospital_name' => 'Overseas Hospital', 'hospital_address' => 'Overseas Address', 'referral_type_id' => 2],
        ]);
        Referral::create(['patient_id' => 1, 'patient_histories_id' => 1, 'hospital_id' => 1, 'reason_id' => 1,
            'referral_number' => 'REF-TEST', 'status' => 'Confirmed', 'confirmed_by' => 1, 'created_by' => 1]);
        ReferralLetter::create(['referral_id' => 1, 'letter_text' => 'Original approved text', 'start_date' => '2026-02-01',
            'created_by' => 1, 'is_printed' => true, 'print_count' => 3, 'printed_at' => now(), 'printed_by' => 1, 'last_printed_language' => 'sw']);
        DB::table('diagnoses')->insert(['diagnosis_id' => 1, 'diagnosis_code' => 'TEST', 'diagnosis_name' => 'Test diagnosis']);
        Referral::find(1)->diagnoses()->attach(1);
        $this->operator = $this->login();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function login(array $denied = []): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'is_blocked' => false]);
        $user->shouldReceive('can')->andReturnUsing(fn ($permission) => ! in_array($permission, $denied, true));
        $user->shouldReceive('canAny')->andReturn(true);
        $user->shouldReceive('hasAnyRole', 'hasRole')->andReturn(false);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['referral_id' => 1, 'outcome' => 'Transferred', 'hospital_id' => 2,
            'next_appointment_date' => '2026-10-12', 'content_summary' => 'Transfer for further treatment',
            'followup_date' => '2026-10-09', 'submission_key' => (string) Str::uuid()], $overrides);
    }

    private function transfer(array $overrides = []): HospitalLetter
    {
        $response = $this->postJson('/api/hospital-letters', $this->payload($overrides))->assertOk();
        return HospitalLetter::findOrFail($response->json('data.letter_id'));
    }

    public function test_transfer_creates_its_own_letter_without_changing_original_or_case_counts(): void
    {
        $original = ReferralLetter::find(1)->getAttributes();
        $source = Referral::find(1)->getAttributes();
        $counts = app(CaseReport::class)->summary([], $this->operator);
        $event = $this->transfer();
        $child = $event->transferredReferral;
        $this->assertSame(1, (int) $child->patient_histories_id);
        $this->assertSame(1, (int) $child->parent_referral_id);
        $this->assertSame(2, (int) $child->hospital_id);
        $this->assertSame('Transferred', $child->status);
        $this->assertSame([1], $child->diagnoses->modelKeys());
        $this->assertNotSame(ReferralLetter::find(1)->referral_letter_code, $child->referralLetters->referral_letter_code);
        $this->assertSame('2026-10-09', $child->referralLetters->start_date);
        $this->assertFalse($child->referralLetters->is_printed);
        $this->assertSame(0, $child->referralLetters->print_count);
        $this->assertSame($original, ReferralLetter::find(1)->getAttributes());
        $this->assertSame($source, Referral::find(1)->getAttributes());
        $this->assertSame($counts, app(CaseReport::class)->summary([], $this->operator));
        $this->assertDatabaseCount('patient_histories', 1);
        $this->assertDatabaseHas('followups', ['letter_id' => $event->getKey(), 'followup_status' => 'Transferred']);
    }

    public function test_transfer_pdf_and_print_tracking_work_from_both_pages(): void
    {
        $event = $this->transfer();
        $id = $event->transferred_referral_id;
        $this->get('/api/letter-documents/referrals/'.$id.'/pdf?language=sw')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $this->get('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf?language=sw')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->postJson('/api/letter-documents/follow-ups/'.$event->getKey().'/print', ['language' => 'sw'])
            ->assertOk()->assertJsonPath('data.letter_type', 'referral')->assertJsonPath('data.print_count', 1);
        $this->postJson('/api/letter-documents/referrals/'.$id.'/print', ['language' => 'sw'])->assertOk()->assertJsonPath('data.print_count', 2);
        $this->assertSame(3, ReferralLetter::find(1)->print_count);
        $this->assertSame(0, $event->fresh()->print_count);
        $this->assertDatabaseCount('letter_print_events', 2);
    }

    public function test_template_and_language_use_the_destination_not_the_original_hospital(): void
    {
        $event = $this->transfer(['hospital_id' => 3]);
        $documents = app(LetterDocumentService::class);
        $letter = $documents->findReferralLetter($event->transferred_referral_id);
        $this->assertSame('en', $documents->defaultReferralLanguage($letter));
        $this->assertSame('Overseas Hospital', $letter->referral->hospital->hospital_name);
        $pdf = $this->get('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf?language=sw')->assertOk();
        $this->assertStringContainsString('-en.pdf', $pdf->headers->get('Content-Disposition'));
        $this->postJson('/api/letter-documents/follow-ups/'.$event->getKey().'/print', ['language' => 'sw'])
            ->assertOk()->assertJsonPath('data.last_printed_language', 'en');
        $this->get('/api/letter-documents/referrals/1/pdf?language=en')->assertOk()->assertHeader('Content-Disposition',
            'inline; filename="referral-letter-'.ReferralLetter::find(1)->referral_letter_code.'-sw.pdf"');
    }

    public function test_lost_response_retry_does_not_duplicate_the_transfer(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/hospital-letters', $payload)->assertOk();
        $this->postJson('/api/hospital-letters', $payload)->assertOk()->assertJsonPath('data.letter_id', $first->json('data.letter_id'));
        $this->assertDatabaseCount('referrals', 2);
        $this->assertDatabaseCount('referral_letters', 2);
        $this->assertDatabaseCount('hospital_letters', 1);
        $this->assertDatabaseCount('followups', 1);
        $payload['hospital_id'] = 3;
        $this->postJson('/api/hospital-letters', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('referrals', 2);
    }

    public function test_multiple_transfer_generations_have_separate_letters_and_safe_links(): void
    {
        $first = $this->transfer();
        $second = $this->transfer(['referral_id' => $first->transferred_referral_id, 'hospital_id' => 3]);
        $this->assertSame([1, 2, 3], app(TransferReferralService::class)->chain($second->transferredReferral)->modelKeys());
        $response = $this->getJson('/api/hospital-letters/followup-by-referral-id/3')->assertOk();
        $response->assertJsonCount(3, 'data.referrals')->assertJsonCount(2, 'data.hospital_letters');
        $this->assertSame([2, 3], array_column($response->json('data.hospital_letters'), 'transferred_referral_id'));
        $this->assertSame([2, 3], array_column(array_column($response->json('data.hospital_letters'), 'transfer_letter'), 'referral_id'));
        $response->assertJsonPath('data.referrals.2.hospital.hospital_name', 'Overseas Hospital');
    }

    public function test_normal_followup_and_finished_outcomes_keep_the_existing_behavior(): void
    {
        $response = $this->postJson('/api/hospital-letters', $this->payload(['outcome' => 'Follow-up', 'hospital_id' => null]))->assertOk();
        $id = $response->json('data.letter_id');
        $this->get('/api/letter-documents/follow-ups/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/letter-documents/follow-ups/'.$id.'/print')->assertOk()->assertJsonPath('data.letter_type', 'follow_up');
        $this->assertSame(1, HospitalLetter::find($id)->print_count);
        $this->postJson('/api/hospital-letters', $this->payload(['outcome' => 'Finished', 'hospital_id' => null]))->assertOk();
        $this->assertSame('Closed', Referral::find(1)->status);
        $this->assertSame('confirmed', DB::table('patient_histories')->value('status'));
        $this->assertDatabaseCount('referrals', 1);
        $this->assertDatabaseCount('referral_letters', 1);
    }

    public function test_additive_journey_logging_preserves_the_transfer_workflow_and_records_the_actor(): void
    {
        (require database_path('migrations/2026_10_10_120000_create_case_journey_events_table.php'))->up();
        $letter = $this->transfer();
        $event = DB::table('case_journey_events')->where('event_kind','Follow-up recorded')->first();
        $this->assertNotNull($event); $this->assertSame(1,(int)$event->actor_id);
        $this->assertSame(1,(int)$event->patient_histories_id); $this->assertSame('Transferred',$event->outcome);
        $this->assertSame('Confirmed',Referral::find(1)->status);
        $this->assertSame('confirmed',DB::table('patient_histories')->value('status'));
        $this->assertNotNull($letter->transferred_referral_id);
        $letter->content_summary = 'Updated transfer notes'; $letter->save();
        $change = DB::table('case_journey_events')->where('event_kind','Follow-up letter updated')->orderByDesc('id')->first();
        $this->assertNotNull($change);
        $this->assertSame('Transfer for further treatment',json_decode($change->snapshot,true)['before']['content_summary']);
        $this->assertSame('Updated transfer notes',json_decode($change->snapshot,true)['after']['content_summary']);
    }

    public function test_transfer_rolls_back_when_followup_record_cannot_be_saved(): void
    {
        FollowUp::creating(fn () => throw new \RuntimeException('Simulated follow-up failure'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/hospital-letters', $this->payload());
            $this->fail('Expected the simulated failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated follow-up failure', $error->getMessage());
        } finally {
            FollowUp::flushEventListeners();
        }
        $this->assertDatabaseCount('referrals', 1);
        $this->assertDatabaseCount('referral_letters', 1);
        $this->assertDatabaseCount('hospital_letters', 0);
        $this->assertSame('Confirmed', Referral::find(1)->status);
    }

    private function legacyChild(array $overrides = []): Referral
    {
        return Referral::create(array_replace(['patient_id' => 1, 'patient_histories_id' => 1, 'parent_referral_id' => 1,
            'hospital_id' => 2, 'reason_id' => 1, 'referral_number' => 'REF-TEST', 'status' => 'Transferred', 'created_by' => 1], $overrides));
    }

    public function test_legacy_repair_is_dry_run_by_default_and_safe_repair_is_idempotent(): void
    {
        $child = $this->legacyChild();
        $event = HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred', 'next_appointment_date' => '2026-10-12']);
        $service = app(TransferReferralService::class);
        $result = $service->repairLegacy();
        $this->assertSame(1, $result['letters_needed']);
        $this->assertSame(1, $result['links_needed']);
        $this->assertSame([], $result['needs_review']);
        $this->assertNull($event->fresh()->transferred_referral_id);
        $this->assertDatabaseCount('referral_letters', 1);
        $this->getJson('/api/letter-documents/referrals/'.$child->getKey().'/pdf')->assertUnprocessable();
        $this->assertDatabaseCount('referral_letters', 1); // GET must not mutate records.
        $service->repairLegacy(true);
        $this->assertSame($child->getKey(), $event->fresh()->transferred_referral_id);
        $this->get('/api/letter-documents/referrals/'.$child->getKey().'/pdf')->assertOk();
        $this->assertSame(['letters_needed' => 0, 'links_needed' => 0, 'needs_review' => []], $service->repairLegacy(true));
        $this->assertSame(3, ReferralLetter::find(1)->print_count);
    }

    public function test_ambiguous_legacy_events_are_not_matched_to_the_wrong_transfer(): void
    {
        $first = $this->legacyChild();
        $second = $this->legacyChild(['hospital_id' => 3]);
        HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred']);
        HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred']);
        $result = app(TransferReferralService::class)->repairLegacy(true);
        $this->assertSame([$first->getKey(), $second->getKey()], $result['needs_review']);
        $this->assertSame(0, $result['links_needed']);
        $this->assertSame(0, HospitalLetter::whereNotNull('transferred_referral_id')->count());
        $this->assertDatabaseCount('referral_letters', 3);
        $this->getJson('/api/letter-documents/follow-ups/1/pdf')->assertUnprocessable();
    }

    public function test_cross_patient_or_case_links_are_never_repaired_or_printed(): void
    {
        $child = $this->legacyChild(['patient_id' => 999]);
        $event = HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred', 'transferred_referral_id' => $child->getKey()]);
        $result = app(TransferReferralService::class)->repairLegacy(true);
        $this->assertSame([$child->getKey()], $result['needs_review']);
        $this->assertDatabaseCount('referral_letters', 1);
        $this->getJson('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf')->assertUnprocessable();
        $this->assertSame([1], app(TransferReferralService::class)->chain(Referral::find(1))->modelKeys());
    }

    public function test_unapproved_or_archived_destination_transfer_cannot_create_a_signed_letter(): void
    {
        Referral::find(1)->update(['status' => 'Pending']);
        $this->postJson('/api/hospital-letters', $this->payload())->assertUnprocessable();
        Referral::find(1)->update(['status' => 'Confirmed']);
        DB::table('hospitals')->where('hospital_id', 2)->update(['deleted_at' => now()]);
        $this->postJson('/api/hospital-letters', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('referrals', 1);
        $this->assertDatabaseCount('referral_letters', 1);
        $this->assertDatabaseCount('hospital_letters', 0);
    }

    public function test_editing_a_transfer_does_not_duplicate_or_redirect_its_letter(): void
    {
        $event = $this->transfer();
        $this->postJson('/api/hospital-letters/update/'.$event->getKey(), ['content_summary' => 'Updated clinical note'])->assertOk();
        $this->assertDatabaseCount('referrals', 2);
        $this->assertDatabaseCount('referral_letters', 2);
        $this->postJson('/api/hospital-letters/update/'.$event->getKey(), ['hospital_id' => 3])->assertUnprocessable();
        $this->assertSame(2, (int) $event->fresh()->transferredReferral->hospital_id);
        $this->postJson('/api/hospital-letters/update/'.$event->getKey(), ['outcome' => 'Finished'])->assertUnprocessable();
    }

    public function test_followup_permission_does_not_bypass_referral_letter_permission(): void
    {
        $event = $this->transfer();
        $this->login(['View ReferralLetter']);
        $this->getJson('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf')->assertForbidden();
        $this->postJson('/api/letter-documents/follow-ups/'.$event->getKey().'/print')->assertForbidden();
        $this->assertDatabaseCount('letter_print_events', 0);
        $this->getJson('/api/letter-documents/referrals/999/pdf')->assertForbidden();
    }

    public function test_migration_prepares_an_existing_transfer_and_preserves_the_original_letter(): void
    {
        $migration = require database_path('migrations/2026_10_09_030000_link_transfer_followups_and_create_letters.php');
        $migration->down();
        $original = ReferralLetter::find(1)->getAttributes();
        $child = $this->legacyChild();
        $event = HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred', 'content_summary' => 'Legacy transfer']);
        $migration->up();
        $this->assertSame($child->getKey(), $event->fresh()->transferred_referral_id);
        $this->assertDatabaseCount('referral_letters', 2);
        $this->assertSame($original, ReferralLetter::find(1)->getAttributes());
        $this->get('/api/letter-documents/referrals/'.$child->getKey().'/pdf')->assertOk();
        $this->get('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf')->assertOk();
    }

    public function test_repair_does_not_recreate_an_archived_transfer_letter(): void
    {
        $event = $this->transfer();
        $event->transferredReferral->referralLetters->delete();
        $this->assertSame(0, app(TransferReferralService::class)->repairLegacy(true)['letters_needed']);
        $this->assertSame(2, ReferralLetter::withTrashed()->count());
        $this->getJson('/api/letter-documents/referrals/'.$event->transferred_referral_id.'/pdf')->assertUnprocessable();
        $this->assertSame(2, ReferralLetter::withTrashed()->count());
    }

    public function test_reusing_a_removed_submission_key_returns_a_clear_validation_error(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/api/hospital-letters', $payload)->assertOk();
        HospitalLetter::find($response->json('data.letter_id'))->delete();
        $this->postJson('/api/hospital-letters', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('referrals', 2);
        $this->assertSame(1, HospitalLetter::withTrashed()->count());
    }

    public function test_editing_a_normal_followup_to_transferred_creates_one_complete_transfer(): void
    {
        $response = $this->postJson('/api/hospital-letters', $this->payload(['outcome' => 'Follow-up', 'hospital_id' => null]))->assertOk();
        $id = $response->json('data.letter_id');
        $this->postJson('/api/hospital-letters/update/'.$id, ['outcome' => 'Transferred', 'hospital_id' => 2,
            'next_appointment_date' => '2026-10-12'])->assertOk();
        $this->assertSame(2, HospitalLetter::find($id)->transferred_referral_id);
        $this->assertDatabaseCount('referrals', 2);
        $this->assertDatabaseCount('referral_letters', 2);
        $this->assertDatabaseCount('followups', 1);
        $this->assertSame('confirmed', DB::table('patient_histories')->value('status'));
    }

    public function test_another_case_for_the_same_patient_is_not_treated_as_this_transfer(): void
    {
        DB::table('patient_histories')->insert(['patient_histories_id' => 2, 'patient_id' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $child = $this->legacyChild(['patient_histories_id' => 2]);
        $event = HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Transferred', 'transferred_referral_id' => $child->getKey()]);
        $this->assertSame([$child->getKey()], app(TransferReferralService::class)->repairLegacy(true)['needs_review']);
        $this->getJson('/api/letter-documents/follow-ups/'.$event->getKey().'/pdf')->assertUnprocessable();
        $this->assertSame([1], app(TransferReferralService::class)->chain(Referral::find(1))->modelKeys());
        $this->assertDatabaseCount('referral_letters', 1);
    }

    public function test_record_details_identify_the_original_hospital_across_multiple_transfers(): void
    {
        $first = $this->transfer();
        $second = $this->transfer(['referral_id' => $first->transferred_referral_id, 'hospital_id' => 3]);
        foreach ([1, $first->transferred_referral_id, $second->transferred_referral_id] as $id) {
            $this->getJson('/api/referrals/'.$id)->assertOk()
                ->assertJsonPath('data.original_referral.referral_id', 1)
                ->assertJsonPath('data.original_referral.hospital.hospital_name', 'Original Hospital')
                ->assertJsonPath('data.original_referral.hospital.referral_type.referral_type_code', 'REFTYPE1');
        }
        $this->postJson('/api/letter-documents/referrals/1/print')->assertOk()->assertJsonPath('data.print_count', 4);
        $this->assertSame(0, $second->transferredReferral->referralLetters->fresh()->print_count);
        $this->postJson('/api/letter-documents/follow-ups/'.$second->getKey().'/print')->assertOk()->assertJsonPath('data.print_count', 1);
        $this->assertSame(4, ReferralLetter::find(1)->print_count);
    }

    public function test_patient_history_view_keeps_the_original_print_target_when_latest_referral_is_a_transfer(): void
    {
        Carbon::setTestNow('2026-10-09 13:00:00');
        $event = $this->transfer(['hospital_id' => 3]);
        $this->getJson('/api/referrals/1?type=history')->assertOk()
            ->assertJsonPath('data.referral_id', $event->transferred_referral_id)
            ->assertJsonPath('data.hospital.hospital_name', 'Overseas Hospital')
            ->assertJsonPath('data.patient.name', 'Test Patient')
            ->assertJsonPath('data.patient.patient_histories.0.patient_histories_id', 1)
            ->assertJsonPath('data.case_history.patient_histories_id', 1)
            ->assertJsonPath('data.case_status', 'confirmed')
            ->assertJsonPath('data.original_referral.referral_id', 1)
            ->assertJsonPath('data.original_referral.hospital.hospital_name', 'Original Hospital');
    }

    public function test_original_print_target_is_not_guessed_for_broken_or_cross_case_parent_links(): void
    {
        DB::table('patient_histories')->insert(['patient_histories_id' => 2, 'patient_id' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $child = $this->legacyChild(['patient_histories_id' => 2]);
        $this->getJson('/api/referrals/'.$child->getKey())->assertOk()->assertJsonPath('data.original_referral', null);
        $child->update(['patient_histories_id' => 1, 'parent_referral_id' => 999]);
        $this->assertNull(app(TransferReferralService::class)->originalReferral($child));
        $child->update(['parent_referral_id' => $child->getKey()]);
        $this->assertNull(app(TransferReferralService::class)->originalReferral($child));
    }

    public function test_cached_pdf_still_checks_permission_and_active_letter_without_recording_a_print(): void
    {
        $first = $this->get('/api/letter-documents/referrals/1/pdf')->assertOk();
        $second = $this->get('/api/letter-documents/referrals/1/pdf')->assertOk();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertStringContainsString('no-store', $second->headers->get('Cache-Control'));
        $this->assertSame(3, ReferralLetter::find(1)->print_count);
        $this->assertDatabaseCount('letter_print_events', 0);
        $this->login(['View ReferralLetter']);
        $this->getJson('/api/letter-documents/referrals/1/pdf')->assertForbidden();
        $this->login();
        ReferralLetter::find(1)->delete();
        $this->getJson('/api/letter-documents/referrals/1/pdf')->assertNotFound();
    }

    public function test_all_letter_preview_endpoints_reuse_current_pdf_and_keep_destinations_separate(): void
    {
        $event = $this->transfer(['hospital_id' => 3]);
        $normal = HospitalLetter::create(['referral_id' => 1, 'outcome' => 'Follow-up', 'next_appointment_date' => '2026-10-12']);
        $boarded = \App\Models\BoardedOutLetter::create(['patient_histories_id' => 1, 'receiver' => 'Test Director, Zanzibar',
            'reference_number' => 'TEST-BO', 'reference_date' => '2026-10-01', 'recommendations' => ['Test recommendation']]);
        $paths = ['/api/letter-documents/referrals/1/pdf',
            '/api/letter-documents/follow-ups/' . $normal->getKey() . '/pdf',
            '/api/letter-documents/boarded-out/1/pdf',
            '/api/letter-documents/follow-ups/' . $event->getKey() . '/pdf'];
        $contents = [];
        foreach ($paths as $path) {
            $start = hrtime(true);
            $first = $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $coldMs = (hrtime(true) - $start) / 1e6;
            $start = hrtime(true);
            $second = $this->get($path)->assertOk();
            $warmMs = (hrtime(true) - $start) / 1e6;
            $this->assertSame($first->getContent(), $second->getContent());
            $contents[] = $first->getContent();
            if (getenv('LETTER_PREVIEW_BENCHMARK')) {
                fwrite(STDOUT, sprintf("\nLetter preview: cold %.1f ms; repeated %.1f ms; PDF %d bytes\n", $coldMs, $warmMs, strlen($first->getContent())));
            }
        }
        $this->assertNotSame($contents[0], $contents[3]);
        $this->assertSame(3, ReferralLetter::find(1)->print_count);
        $this->assertSame(0, $event->transferredReferral->referralLetters->print_count);
        $this->assertSame(0, $normal->fresh()->print_count);
        $this->assertSame(0, $boarded->fresh()->print_count);
        $this->assertDatabaseCount('letter_print_events', 0);
    }
}
