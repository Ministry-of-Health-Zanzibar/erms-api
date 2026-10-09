<?php

namespace Tests\Feature\Reports;

use App\Models\PatientHistory;
use App\Models\User;
use App\Services\ReferralCaseLinker;
use App\Services\PatientHistoryWorkflowService;
use App\Services\Reports\CaseReport;
use App\Services\Reports\ReportFilterNormalizer;
use App\Services\Reports\ReportService;
use App\Services\Reports\Exports\ReportExcelExporter;
use App\Services\Reports\Exports\ReportPdfExporter;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CaseReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00'));
        // Never use the application's database. SQLite is the default; a
        // disposable PostgreSQL cluster can be supplied on PostgreSQL-only PHP.
        $socket = getenv('CASE_REPORT_TEST_PG_SOCKET');
        if ($socket) {
            $this->assertStringStartsWith('/private/tmp/eris-case-tests.', $socket);
            $schema = 'case_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'case_tests', 'database.connections.case_tests' => [
                'driver' => 'pgsql', 'host' => $socket, 'port' => 55439,
                'database' => 'postgres', 'username' => 'case_tests', 'password' => '',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema,
            ]]);
            DB::purge('case_tests');
            DB::statement('CREATE SCHEMA "'.$schema.'"');
        } else {
            if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                $this->markTestSkipped('Requires PDO SQLite or CASE_REPORT_TEST_PG_SOCKET pointing to a disposable test cluster.');
            }
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
        Schema::create('patients', function (Blueprint $t): void {
            $t->integer('patient_id')->primary(); $t->string('name'); $t->integer('created_by');
            $t->string('matibabu_card')->nullable(); $t->string('zan_id')->nullable(); $t->string('phone')->nullable(); $t->softDeletes();
        });
        Schema::create('patient_histories', function (Blueprint $t): void {
            $t->integer('patient_histories_id')->primary(); $t->integer('patient_id'); $t->string('status');
            $t->string('case_type')->default('Emergency'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('referrals', function (Blueprint $t): void {
            $t->integer('referral_id')->primary(); $t->integer('patient_id'); $t->integer('patient_histories_id')->nullable();
            $t->integer('parent_referral_id')->nullable(); $t->integer('hospital_id')->nullable(); $t->string('status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('hospital_user', function (Blueprint $t): void { $t->integer('user_id'); $t->integer('hospital_id'); });
        Schema::create('hospitals', function (Blueprint $t): void { $t->integer('hospital_id'); $t->string('hospital_name'); });
        Schema::create('boarded_out_letters', function (Blueprint $t): void {
            $t->integer('id')->primary(); $t->integer('patient_histories_id'); $t->integer('referral_id')->nullable();
            $t->string('reference_number')->nullable(); $t->string('receiver')->nullable(); $t->softDeletes();
        });
        Schema::create('patient_history_workflow_events', function (Blueprint $t): void {
            $t->integer('id')->primary(); $t->integer('patient_histories_id'); $t->string('action'); $t->text('metadata'); $t->timestamp('undone_at')->nullable();
        });
        DB::table('patients')->insert([
            ['patient_id' => 1, 'name' => 'Sample One', 'created_by' => 10, 'deleted_at' => null],
            ['patient_id' => 2, 'name' => 'Sample Two', 'created_by' => 20, 'deleted_at' => null],
            ['patient_id' => 3, 'name' => 'Archived Sample', 'created_by' => 10, 'deleted_at' => '2026-10-01'],
        ]);
        foreach ([[1, 1, 'confirmed', '2026-05-01'], [2, 1, 'pending', '2026-10-08'], [3, 2, 'boarded_out', '2026-05-02'], [4, 3, 'boarded_out', '2026-05-03']] as [$id, $patient, $status, $date]) {
            DB::table('patient_histories')->insert(['patient_histories_id' => $id, 'patient_id' => $patient, 'status' => $status, 'created_at' => $date, 'updated_at' => $date]);
        }
        DB::table('referrals')->insert([
            ['referral_id' => 1, 'patient_id' => 1, 'patient_histories_id' => 1, 'status' => 'Confirmed'],
            ['referral_id' => 2, 'patient_id' => 1, 'patient_histories_id' => 1, 'status' => 'Confirmed'],
        ]);
        DB::table('hospital_user')->insert([['user_id' => 10, 'hospital_id' => 1], ['user_id' => 20, 'hospital_id' => 2]]);
        DB::table('hospitals')->insert([['hospital_id' => 1, 'hospital_name' => 'Hospital One'], ['hospital_id' => 2, 'hospital_name' => 'Hospital Two']]);
    }

    private function user(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->andReturn(false);
        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_multiple_hospital_referrals_count_as_one_case_and_archives_are_explicit(): void
    {
        $cases = app(CaseReport::class);
        $summary = $cases->summary([], $this->user());
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['under_review']);
        $this->assertSame(1, $summary['confirmed']);
        $this->assertSame(1, $summary['boarded_out']);
        $this->assertSame(4, $cases->summary(['include_archived' => true], $this->user())['total']);
        $this->assertSame($summary['total'], array_sum(array_column($summary['statuses'], 'count')));
    }

    public function test_case_report_and_dashboard_share_filters_and_export_all_pages(): void
    {
        $cases = app(CaseReport::class);
        $filters = (new ReportFilterNormalizer)->normalize(['report_type' => 'case_workflow', 'start_date' => '2026-01-01', 'end_date' => '2026-10-09', 'per_page' => 1]);
        $report = $cases->generate($filters, $this->user(), true);
        $export = $cases->generate($filters, $this->user(), false);
        $this->assertSame($cases->summary($filters, $this->user())['total'], $report['pagination']['total']);
        $this->assertCount(1, $report['rows']);
        $this->assertCount(3, $export['rows']);
        $filters['source_hospital_ids'] = [2];
        $this->assertSame(1, $cases->summary($filters, $this->user())['total']);
        $filters['patient_history_status'] = 'pending';
        $this->assertSame(0, $cases->summary($filters, $this->user())['total']);
    }

    public function test_report_columns_show_matibabu_card_and_hospital_names_for_the_exact_case(): void
    {
        DB::table('patients')->where('patient_id', 1)->update(['matibabu_card' => '001234567890']);
        DB::table('referrals')->where('referral_id', 1)->update(['hospital_id' => 1]);
        DB::table('referrals')->where('referral_id', 2)->update(['hospital_id' => 2]);
        foreach ([[3, 1, 1, 1, null], [4, 1, 2, 1, null], [5, 1, 2, 2, '2026-10-01'], [6, 2, 2, 2, null]] as [$id, $patient, $case, $hospital, $archived]) {
            DB::table('referrals')->insert(['referral_id' => $id, 'patient_id' => $patient,
                'patient_histories_id' => $case, 'hospital_id' => $hospital, 'status' => 'Confirmed', 'deleted_at' => $archived]);
        }
        $filters = (new ReportFilterNormalizer)->normalize(['report_type' => 'case_workflow',
            'start_date' => '2026-01-01', 'end_date' => '2026-10-09', 'patient_history_status' => 'confirmed']);
        foreach ([true, false] as $paginate) {
            $report = app(CaseReport::class)->generate($filters, $this->user(), $paginate);
            $this->assertCount(1, $report['rows']);
            $this->assertSame('001234567890', $report['rows'][0]['matibabu_card']);
            $this->assertSame('Hospital One; Hospital Two', $report['rows'][0]['referred_hospitals']);
            $this->assertSame([
                'matibabu_card', 'patient', 'source_hospital', 'submitted_at', 'status_label', 'referred_hospitals', 'archive',
            ], array_column($report['columns'], 'key'));
            $this->assertSame('text', $report['columns'][0]['type']);
        }
        $filters['patient_history_status'] = 'pending';
        $pending = app(CaseReport::class)->generate($filters, $this->user(), false);
        $this->assertSame('Hospital One', $pending['rows'][0]['referred_hospitals']);
        $filters['patient_history_status'] = 'boarded_out';
        $boardedOut = app(CaseReport::class)->generate($filters, $this->user(), false);
        $this->assertNull($boardedOut['rows'][0]['matibabu_card']);
        $this->assertNull($boardedOut['rows'][0]['referred_hospitals']);
        $this->assertSame(3, app(CaseReport::class)->summary([], $this->user())['total']);
    }

    public function test_new_case_does_not_replace_an_old_referral_and_progress_contract_is_preserved(): void
    {
        $linker = app(ReferralCaseLinker::class);
        $this->assertSame(1, $linker->historyId(DB::table('referrals')->where('referral_id', 1)->first()));
        $history = new PatientHistory(['status' => 'pending']);
        $this->assertSame('17%', $history->progress_percentage);
        $this->assertSame(1, $history->status_tracking['stage']);
        $summary = app(CaseReport::class)->summary(['start_date' => '2026-10-01'], $this->user());
        $this->assertSame(1, $summary['total']);
        $pending = collect($summary['statuses'])->firstWhere('status', 'pending');
        $this->assertSame(100.0, $pending['case_percentage']);
        $this->assertSame(17, $pending['progress_percentage']);
    }

    public function test_legacy_links_use_evidence_and_leave_ambiguous_records_unassigned(): void
    {
        DB::table('referrals')->insert(['referral_id' => 3, 'patient_id' => 1, 'status' => 'BoardedOut']);
        $linker = app(ReferralCaseLinker::class);
        $referral = DB::table('referrals')->where('referral_id', 3)->first();
        $this->assertNull($linker->historyId($referral));
        DB::table('patient_history_workflow_events')->insert(['id' => 1, 'patient_histories_id' => 1,
            'action' => 'medical_board_referral_decision', 'metadata' => json_encode(['context' => ['referral_id' => 3]])]);
        $this->assertSame(1, $linker->historyId($referral));
        DB::table('boarded_out_letters')->insert(['id' => 1, 'patient_histories_id' => 2, 'referral_id' => 3]);
        $this->assertNull($linker->historyId($referral));
    }

    public function test_patient_summary_counts_each_patient_once(): void
    {
        $filters = (new ReportFilterNormalizer)->normalize(['report_type' => 'patient_summary', 'start_date' => '2026-01-01', 'end_date' => '2026-10-09']);
        $report = app(CaseReport::class)->generate($filters, $this->user(), false);
        $this->assertSame(2, $report['summary']['total_records']);
        $this->assertSame([3, 2], array_column($report['rows'], 'case_id'));
    }

    public function test_boarded_out_report_keeps_cases_without_hospital_referrals(): void
    {
        DB::table('boarded_out_letters')->insert(['id' => 1, 'patient_histories_id' => 3,
            'reference_number' => 'BO-SAMPLE', 'receiver' => 'Sample Receiver']);
        $filters = (new ReportFilterNormalizer)->normalize(['report_type' => 'boarded_out_cases', 'start_date' => '2026-01-01', 'end_date' => '2026-10-09']);
        $report = app(CaseReport::class)->generate($filters, $this->user(), false);
        $this->assertSame(1, $report['summary']['total_records']);
        $this->assertSame('BO-SAMPLE', $report['rows'][0]['letter_reference']);
        $this->assertNull($report['rows'][0]['linked_hospital']);
    }

    public function test_hospital_scope_applies_to_dashboard_and_case_report(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('ROLE HOSPITAL USER')->andReturn(true);
        $user->shouldReceive('hasAnyRole')->andReturn(false);
        $hospitals = Mockery::mock(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
        $hospitals->shouldReceive('pluck')->with('hospitals.hospital_id')->andReturn(collect([2]));
        $user->shouldReceive('hospitals')->andReturn($hospitals);
        $cases = app(CaseReport::class);
        $this->assertSame(1, $cases->summary([], $user)['total']);
        $filters = (new ReportFilterNormalizer)->normalize(['report_type' => 'case_workflow', 'start_date' => '2026-01-01', 'end_date' => '2026-10-09']);
        $this->assertSame([3], array_column($cases->generate($filters, $user, false)['rows'], 'case_id'));
        $this->assertSame(0, $cases->summary(['source_hospital_ids' => [1]], $user)['total']);
    }

    public function test_workflow_snapshot_does_not_include_another_case_for_the_same_patient(): void
    {
        DB::table('referrals')->insert(['referral_id' => 3, 'patient_id' => 1, 'patient_histories_id' => 2, 'status' => 'Pending']);
        $snapshot = app(PatientHistoryWorkflowService::class)->snapshot(PatientHistory::findOrFail(1));
        $this->assertSame([1, 2], $snapshot['referral_ids']);
        $this->assertSame([1, 2], array_column($snapshot['tables']['referrals']['rows'], 'referral_id'));
    }

    public function test_case_link_migration_preserves_uncertain_links(): void
    {
        Schema::table('referrals', fn (Blueprint $t) => $t->dropColumn('patient_histories_id'));
        DB::table('patient_history_workflow_events')->insert(['id' => 1, 'patient_histories_id' => 1,
            'action' => 'auto_approved_registration', 'metadata' => json_encode(['context' => ['referral_id' => 1]])]);
        $migration = require database_path('migrations/2026_10_09_020000_link_referrals_to_medical_history_cases.php');
        $migration->up();
        $this->assertSame(1, (int) DB::table('referrals')->where('referral_id', 1)->value('patient_histories_id'));
        $this->assertNull(DB::table('referrals')->where('referral_id', 2)->value('patient_histories_id'));
    }

    public function test_dashboard_cache_refresh_and_individual_progress_contract(): void
    {
        $user = $this->user();
        $user->id = 1;
        $user->shouldReceive('can')->with('View Referral Dashboard')->andReturn(true);
        $this->actingAs($user, 'sanctum');
        $url = '/api/reports/caseStatusTracking?start_date=2026-10-01';
        $this->getJson($url)->assertOk()->assertJsonPath('data.medical_history.total', 1)
            ->assertJsonPath('data.medical_history.statuses.0.progress_percentage', 17)
            ->assertJsonPath('data.medical_history.statuses.0.case_percentage', 100);
        DB::table('patient_histories')->insert(['patient_histories_id' => 5, 'patient_id' => 2,
            'status' => 'reviewed', 'created_at' => '2026-10-09', 'updated_at' => '2026-10-09']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.medical_history.total', 1);
        $this->getJson($url.'&refresh=1')->assertOk()->assertJsonPath('data.medical_history.total', 2);
        $this->getJson('/api/reports/caseStatusTracking?start_date=2026-10-09&end_date=2026-01-01')->assertUnprocessable();
    }

    public function test_case_exporters_receive_all_filtered_rows(): void
    {
        DB::table('patients')->where('patient_id', 1)->update(['matibabu_card' => '001234567890']);
        DB::table('referrals')->where('referral_id', 1)->update(['hospital_id' => 1]);
        DB::table('referrals')->where('referral_id', 2)->update(['hospital_id' => 2]);
        $input = ['report_type' => 'case_workflow', 'start_date' => '2026-01-01', 'end_date' => '2026-10-09', 'per_page' => 1];
        $report = app(ReportService::class)->generate($input, $this->user(), false);
        $this->assertCount(3, $report['rows']);
        $this->assertTrue($report['confidential']);
        $pdf = app(ReportPdfExporter::class)->download($report, 'cases.pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        ob_start();
        app(ReportExcelExporter::class)->download($report, 'cases.xlsx')->sendContent();
        $xlsx = ob_get_clean();
        $this->assertStringStartsWith('PK', $xlsx);
        $path = tempnam(sys_get_temp_dir(), 'case-export-');
        try {
            file_put_contents($path, $xlsx);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $strings = $zip->getFromName('xl/sharedStrings.xml');
            $this->assertStringContainsString('Sample One', $strings);
            $this->assertStringContainsString('Sample Two', $strings);
            $this->assertStringNotContainsString('Archived Sample', $strings);
            $this->assertStringContainsString('Matibabu card', $strings);
            $this->assertStringContainsString('001234567890', $strings);
            $this->assertStringContainsString('Referred hospital', $strings);
            $this->assertStringContainsString('Hospital One; Hospital Two', $strings);
            $this->assertStringNotContainsString('Case ID', $strings);
            $this->assertStringNotContainsString('Patient ID', $strings);
            $zip->close();
        } finally {
            unlink($path);
        }
    }
}
