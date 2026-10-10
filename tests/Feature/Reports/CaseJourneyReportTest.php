<?php

namespace Tests\Feature\Reports;

use App\Models\HospitalLetter;
use App\Models\User;
use App\Services\CaseJourneyRecorder;
use App\Services\Reports\CaseJourneyReport;
use App\Services\Reports\ReportFilterNormalizer;
use App\Services\Reports\SearchReferralReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CaseJourneyReportTest extends TestCase
{
    private ?string $testSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('CASE_REPORT_TEST_PG_SOCKET');
        if ($socket) {
            $this->assertStringStartsWith('/private/tmp/eris-case-tests.', $socket);
            $this->testSchema = 'journey_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'journey_tests', 'database.connections.journey_tests' => [
                'driver' => 'pgsql', 'host' => $socket, 'port' => 55439, 'database' => 'postgres', 'username' => 'case_tests',
                'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->testSchema,
            ]]);
            DB::purge('journey_tests'); DB::statement('CREATE SCHEMA "'.$this->testSchema.'"');
        } else {
            if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires isolated SQLite or temporary test PostgreSQL.');
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        }
        Schema::create('patients', function (Blueprint $t): void {
            $t->integer('patient_id')->primary(); $t->string('name'); $t->string('matibabu_card')->nullable(); $t->integer('created_by'); $t->softDeletes();
        });
        Schema::create('patient_histories', function (Blueprint $t): void {
            $t->integer('patient_histories_id')->primary(); $t->integer('patient_id'); $t->string('status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('hospitals', function (Blueprint $t): void {
            $t->integer('hospital_id')->primary(); $t->string('hospital_name'); $t->string('hospital_address')->nullable(); $t->integer('referral_type_id')->default(3); $t->softDeletes();
        });
        Schema::create('hospital_user', function (Blueprint $t): void { $t->integer('user_id'); $t->integer('hospital_id'); });
        Schema::create('geographical_locations', function (Blueprint $t): void { $t->string('location_id'); $t->string('location_name'); $t->string('parent_id')->nullable(); $t->string('label')->nullable(); $t->softDeletes(); });
        Schema::create('referral_types', function (Blueprint $t): void { $t->integer('referral_type_id'); $t->string('referral_type_name'); $t->string('referral_type_code'); $t->softDeletes(); });
        Schema::create('referrals', function (Blueprint $t): void {
            $t->integer('referral_id')->primary(); $t->integer('patient_id'); $t->integer('patient_histories_id')->nullable();
            $t->integer('parent_referral_id')->nullable(); $t->integer('hospital_id')->nullable(); $t->integer('reason_id')->nullable();
            $t->integer('created_by')->default(99); $t->string('referral_number'); $t->string('status'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('hospital_letters', function (Blueprint $t): void {
            $t->integer('letter_id')->primary(); $t->integer('referral_id'); $t->integer('transferred_referral_id')->nullable();
            $t->string('outcome'); $t->text('content_summary')->nullable(); $t->string('next_appointment_date')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('followups', function (Blueprint $t): void {
            $t->integer('followup_id')->primary(); $t->integer('letter_id'); $t->integer('patient_id'); $t->string('followup_date')->nullable();
            $t->text('notes')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('patient_history_workflow_events', function (Blueprint $t): void {
            $t->integer('id')->primary(); $t->integer('patient_histories_id'); $t->string('action'); $t->string('from_status'); $t->string('to_status');
            $t->integer('actor_id')->nullable(); $t->timestamp('undone_at')->nullable(); $t->integer('undone_by')->nullable(); $t->text('undo_reason')->nullable(); $t->timestamps();
        });
        Schema::create('boarded_out_letters', function (Blueprint $t): void {
            $t->integer('id')->primary(); $t->integer('patient_histories_id'); $t->string('reference_number')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        foreach (['reasons' => ['reason_id', 'referral_reason_name'], 'diagnoses' => ['diagnosis_id', 'diagnosis_name']] as $table => [$id, $name]) {
            Schema::create($table, function (Blueprint $t) use ($id, $name, $table): void { $t->integer($id)->primary(); $t->string($name); if ($table === 'diagnoses') $t->string('diagnosis_code'); $t->softDeletes(); });
        }
        Schema::create('history_diagnosis', function (Blueprint $t): void { $t->integer('patient_histories_id'); $t->integer('diagnosis_id'); $t->string('added_by'); });
        Schema::create('insurances', function (Blueprint $t): void { $t->integer('insurance_id')->primary(); $t->integer('patient_id'); $t->string('insurance_provider_name'); $t->softDeletes(); });
        Schema::create('referral_letters', function (Blueprint $t): void { $t->integer('referral_letter_id')->primary(); $t->integer('referral_id'); $t->string('start_date'); $t->string('end_date'); $t->timestamps(); $t->softDeletes(); });
        (require database_path('migrations/2026_10_10_120000_create_case_journey_events_table.php'))->up();
        DB::table('patients')->insert([
            ['patient_id' => 1, 'name' => 'Patient One', 'matibabu_card' => '0001', 'created_by' => 10],
            ['patient_id' => 2, 'name' => 'Patient Two', 'matibabu_card' => '0002', 'created_by' => 20],
        ]);
        DB::table('hospitals')->insert(collect([1 => 'Source One', 2 => 'Source Two', 3 => 'Hospital A', 4 => 'Hospital B', 5 => 'Hospital C'])->map(fn ($name, $id) => ['hospital_id' => $id, 'hospital_name' => $name])->values()->all());
        DB::table('hospital_user')->insert([['user_id' => 10, 'hospital_id' => 1], ['user_id' => 20, 'hospital_id' => 2], ['user_id' => 99, 'hospital_id' => 1]]);
        foreach ([[1, 1, 'confirmed', '2026-01-01'], [2, 1, 'reviewed', '2026-10-08'], [3, 2, 'confirmed', '2026-01-02']] as [$id, $patient, $status, $date]) {
            DB::table('patient_histories')->insert(['patient_histories_id' => $id, 'patient_id' => $patient, 'status' => $status, 'created_at' => $date, 'updated_at' => $date]);
        }
        foreach ([[11,1,1,3,null,'Closed','2026-01-03'],[12,1,1,4,11,'Transferred','2026-06-01'],[13,1,1,5,12,'Closed','2026-08-01'],[21,1,2,3,null,'Pending','2026-10-08'],[31,2,3,3,null,'Closed','2026-01-04'],[91,1,null,5,null,'Closed','2026-09-10'],[92,2,1,5,null,'Closed','2026-09-10']] as [$id,$p,$case,$h,$parent,$status,$date]) {
            DB::table('referrals')->insert(['referral_id'=>$id,'patient_id'=>$p,'patient_histories_id'=>$case,'hospital_id'=>$h,'parent_referral_id'=>$parent,'status'=>$status,'referral_number'=>'REF-'.$case,'created_at'=>$date,'updated_at'=>$date]);
        }
        foreach ([[101,11,12,'Transferred',1,'2026-06-01'],[102,12,13,'Transferred',1,'2026-08-01'],[103,13,null,'Finished',1,'2026-09-05'],[104,31,null,'Death',2,'2026-09-06'],[191,91,null,'Death',1,'2026-09-10'],[192,92,null,'Death',2,'2026-09-10']] as [$id,$r,$child,$outcome,$p,$date]) {
            DB::table('hospital_letters')->insert(['letter_id'=>$id,'referral_id'=>$r,'transferred_referral_id'=>$child,'outcome'=>$outcome,'content_summary'=>'Private clinical note','created_at'=>$date,'updated_at'=>$date]);
            DB::table('followups')->insert(['followup_id'=>$id,'letter_id'=>$id,'patient_id'=>$p,'followup_date'=>$date,'created_at'=>$date,'updated_at'=>$date]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->testSchema) DB::statement('DROP SCHEMA "'.$this->testSchema.'" CASCADE');
        parent::tearDown();
    }

    private function user(bool $clinical = false): User
    {
        $u = Mockery::mock(User::class)->makePartial(); $u->shouldReceive('hasRole')->andReturn(false); $u->shouldReceive('hasAnyRole')->andReturn(false);
        $u->shouldReceive('can')->andReturnUsing(fn ($permission) => $clinical && in_array($permission, ['View FollowUp', 'View Hospital Letter'], true)); return $u;
    }

    private function filters(array $extra = []): array
    {
        return (new ReportFilterNormalizer)->normalize($extra + ['report_type'=>'case_journey','start_date'=>'2026-09-01','end_date'=>'2026-09-30','detail_level'=>'summary','per_page'=>25]);
    }

    public function test_activity_period_separates_finished_and_death_and_excludes_bad_case_links(): void
    {
        $r = app(CaseJourneyReport::class)->generate($this->filters(), $this->user(), true);
        $this->assertSame(2, $r['summary']['cases']); $this->assertSame(2, $r['summary']['follow_up_visits_in_period']);
        $this->assertSame(1, $r['summary']['finished_visits_in_period']); $this->assertSame(1, $r['summary']['death_visits_in_period']);
        $this->assertSame([3,1], array_column($r['rows'],'case_id'));
        $this->assertStringContainsString('Finished', $r['rows'][1]['latest_outcomes']);
        $this->assertSame('Confirmed', $r['rows'][1]['status_label']);
        $this->assertSame([3], array_column(app(CaseJourneyReport::class)->generate($this->filters(['outcome'=>'Death']),$this->user(),true)['rows'],'case_id'));
    }

    public function test_complete_journey_retains_repeated_transfers_and_context_without_mixing_histories(): void
    {
        $r = app(CaseJourneyReport::class)->generate($this->filters(['case_id'=>1,'detail_level'=>'details']), $this->user(true), true);
        $events = collect($r['sections'])->firstWhere('key','movements')['rows'];
        $this->assertCount(3, array_filter($events,fn ($e) => $e['activity']==='Follow-up visit'));
        $this->assertSame([1], array_values(array_unique(array_column($events,'case_id'))));
        $hospitalRows = collect($r['sections'])->firstWhere('key','hospital_outcomes')['rows'];
        $this->assertSame(['Hospital A','Hospital B','Hospital C'], array_column($hospitalRows,'destination_hospital'));
        $this->assertSame(['Source One','Hospital A','Hospital B'], array_column($hospitalRows,'source_hospital'));
        $this->assertContains('Earlier / later context', array_column($events,'period_context'));
        $this->assertSame(1, $r['summary']['follow_up_visits_in_period']);
        $this->assertStringNotContainsString('Death', json_encode($events));
    }

    public function test_all_pages_exports_have_the_same_cases_and_redact_clinical_notes(): void
    {
        $preview = app(CaseJourneyReport::class)->generate($this->filters(['per_page'=>1]),$this->user(),true);
        $export = app(CaseJourneyReport::class)->generate($this->filters(['per_page'=>1]),$this->user(),false);
        $this->assertCount(1,$preview['rows']); $this->assertCount(2,$export['rows']); $this->assertSame($preview['summary'],$export['summary']);
        $this->assertCount(2,collect($export['sections'])->firstWhere('key','case_summary')['rows']);
        $this->assertStringNotContainsString('Private clinical note',json_encode($export));
        $this->assertStringContainsString('restricted by permissions',json_encode($export));
    }

    public function test_archived_visits_and_duplicate_followups_do_not_inflate_current_outcome_counts(): void
    {
        DB::table('followups')->insert(['followup_id'=>999,'letter_id'=>103,'patient_id'=>1,'followup_date'=>'2026-09-05']);
        $this->assertSame(2, app(CaseJourneyReport::class)->generate($this->filters(),$this->user(),true)['summary']['follow_up_visits_in_period']);
        DB::table('hospital_letters')->where('letter_id',104)->update(['deleted_at'=>'2026-09-20']);
        $this->assertSame(0, app(CaseJourneyReport::class)->generate($this->filters(),$this->user(),true)['summary']['death_visits_in_period']);
        $this->assertSame(1, app(CaseJourneyReport::class)->generate($this->filters(['include_archived'=>true]),$this->user(),true)['summary']['death_visits_in_period']);
    }

    public function test_search_sources_do_not_follow_the_director_and_diagnoses_do_not_mix_cases(): void
    {
        DB::table('diagnoses')->insert([['diagnosis_id'=>1,'diagnosis_code'=>'A1','diagnosis_name'=>'First case'],['diagnosis_id'=>2,'diagnosis_code'=>'A2','diagnosis_name'=>'Second case']]);
        DB::table('history_diagnosis')->insert([['patient_histories_id'=>1,'diagnosis_id'=>1,'added_by'=>'medical_board'],['patient_histories_id'=>2,'diagnosis_id'=>2,'added_by'=>'medical_board']]);
        $rows = collect(app(SearchReferralReport::class)->generate([], $this->user()));
        $this->assertSame('Source Two',$rows->firstWhere('referral_id',31)->from_hospital_name);
        $this->assertSame('Hospital A',$rows->firstWhere('referral_id',12)->from_hospital_name);
        $this->assertSame('Hospital B',$rows->firstWhere('referral_id',12)->to_hospital_name);
        $this->assertSame(['First case'],array_column($rows->firstWhere('referral_id',11)->board_diagnoses,'diagnosis_name'));
        $this->assertSame(['Second case'],array_column($rows->firstWhere('referral_id',21)->board_diagnoses,'diagnosis_name'));
        $this->assertCount(1,app(SearchReferralReport::class)->generate(['from_hospital_name'=>'Source Two','to_hospital_name'=>'Hospital A'], $this->user()));
    }

    public function test_multiple_source_assignments_are_listed_without_duplicate_referral_rows(): void
    {
        DB::table('hospital_user')->insert(['user_id'=>20,'hospital_id'=>1]);
        $rows = app(SearchReferralReport::class)->generate(['patient_name'=>'Patient Two'], $this->user());
        $this->assertCount(2,$rows); // one good referral and one wrong-case referral, explicitly warned
        $this->assertSame('Source One; Source Two',$rows[0]->from_hospital_name);
        $this->assertNotNull($rows[0]->record_warning);
    }

    public function test_future_outcome_corrections_retain_before_and_after_without_rewriting_old_data(): void
    {
        $letter = new HospitalLetter;
        $letter->setRawAttributes((array) DB::table('hospital_letters')->where('letter_id',103)->first(), true);
        $letter->outcome = 'Follow-up'; $letter->syncChanges();
        app(CaseJourneyRecorder::class)->record($letter,'Follow-up letter updated');
        $event = DB::table('case_journey_events')->first();
        $snapshot = json_decode($event->snapshot,true);
        $this->assertSame('Finished',$snapshot['before']['outcome']); $this->assertSame('Follow-up',$snapshot['after']['outcome']);
        $this->assertSame('Finished',DB::table('hospital_letters')->where('letter_id',103)->value('outcome'));
        $this->assertSame(1,(int)$event->patient_histories_id);
    }

    public function test_hospital_users_cannot_request_another_hospitals_case_or_export_it(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('ROLE HOSPITAL USER')->andReturn(true); $user->shouldReceive('hasAnyRole')->andReturn(false); $user->shouldReceive('can')->andReturn(false);
        $relation = Mockery::mock(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
        $relation->shouldReceive('pluck')->with('hospitals.hospital_id')->andReturn(collect([2])); $user->shouldReceive('hospitals')->andReturn($relation);
        foreach ([true,false] as $paginate) {
            $this->assertSame([3], array_column(app(CaseJourneyReport::class)->generate($this->filters(), $user, $paginate)['rows'],'case_id'));
            $this->assertSame([],app(CaseJourneyReport::class)->generate($this->filters(['case_id'=>1]),$user,$paginate)['rows']);
            $search = app(SearchReferralReport::class)->generate(['from_hospital_name'=>'Source Two'], $user);
            $this->assertCount(2,$search); // own patient's unresolved referral remains separately warned
            $this->assertSame([2],array_values(array_unique(array_column($search,'patient_id'))));
        }
        $options = app(\App\Services\Reports\ReportService::class)->filterOptions($user,'case_journey');
        $this->assertSame([2],array_column($options['source_hospitals'],'value'));
        $this->assertSame([3],array_column($options['hospitals'],'value')); // only verified destinations of their own cases
    }

    public function test_destination_filter_keeps_the_complete_transfer_chain_as_context(): void
    {
        $report = app(CaseJourneyReport::class)->generate($this->filters(['hospital_ids'=>[5],'case_id'=>1]),$this->user(),true);
        $hospitalRows = collect($report['sections'])->firstWhere('key','hospital_outcomes')['rows'];
        $this->assertCount(3,$hospitalRows); $this->assertSame(1,$report['summary']['hospital_referrals']);
        $this->assertSame(1,$report['summary']['finished_visits_in_period']); $this->assertEmpty($report['data_quality_notes']);
    }

    public function test_invalid_dates_and_missing_visit_records_are_not_false_outcome_counts(): void
    {
        DB::table('followups')->where('letter_id',103)->update(['followup_date'=>'2026-02-30']);
        $report = app(CaseJourneyReport::class)->generate($this->filters(['case_id'=>1]),$this->user(),true);
        $this->assertSame(1,$report['summary']['finished_visits_in_period']);
        $this->assertStringContainsString('invalid historical visit date',implode(' ',$report['data_quality_notes']));
        DB::table('followups')->where('letter_id',104)->update(['deleted_at'=>'2026-09-20']);
        $this->assertSame(0,app(CaseJourneyReport::class)->generate($this->filters(),$this->user(),true)['summary']['death_visits_in_period']);
    }

    public function test_undone_workflow_actions_remain_visible_but_do_not_change_current_approval(): void
    {
        DB::table('patient_history_workflow_events')->insert(['id'=>1,'patient_histories_id'=>1,'action'=>'dg_confirm','from_status'=>'approved','to_status'=>'confirmed',
            'actor_id'=>null,'created_at'=>'2026-08-10','updated_at'=>'2026-08-10','undone_at'=>'2026-09-12','undo_reason'=>'Correction']);
        DB::table('patient_histories')->where('patient_histories_id',1)->update(['status'=>'approved']);
        $report = app(CaseJourneyReport::class)->generate($this->filters(['case_id'=>1]),$this->user(true),true);
        $events = collect($report['sections'])->firstWhere('key','movements')['rows'];
        $this->assertContains('Undone — not the current decision',array_column($events,'record_state'));
        $this->assertContains('Workflow action undone',array_column($events,'activity'));
        $this->assertSame('Approved by DCS',$report['rows'][0]['status_label']);
    }

    public function test_new_report_exports_generate_valid_pdf_excel_and_word_with_all_case_rows(): void
    {
        $report = app(CaseJourneyReport::class)->generate($this->filters(['per_page'=>1]),$this->user(),false) + [
            'title'=>'CASE JOURNEY AND OUTCOMES REPORT','period'=>'September 2026','generated_at'=>'2026-10-10','generated_by'=>'Test operator','filters'=>['Patient search'=>'=SUM(1,1)'],'confidential'=>true];
        $excel = app(\App\Services\Reports\Exports\ReportExcelExporter::class)->download($report,'journey.xlsx');
        $word = app(\App\Services\Reports\Exports\ReportWordExporter::class)->download($report,'journey.docx');
        foreach ([$excel,$word] as $response) {
            ob_start(); $response->sendContent(); $bytes = ob_get_clean(); $this->assertStringStartsWith('PK',$bytes);
            $file = tempnam(sys_get_temp_dir(),'journey-export-'); file_put_contents($file,$bytes);
            try {
                $zip = new \ZipArchive; $this->assertTrue($zip->open($file));
                $content = '';
                for ($i=0;$i<$zip->numFiles;$i++) if (str_ends_with($zip->getNameIndex($i),'.xml')) $content .= $zip->getFromIndex($i);
                $this->assertStringContainsString('Patient One',$content); $this->assertStringContainsString('Patient Two',$content);
                $this->assertStringContainsString('Source hospital',$content); $this->assertStringContainsString('Destination hospital',$content); $zip->close();
                if ($response === $excel) $this->assertStringNotContainsString('<f>',$content);
            } finally { unlink($file); }
        }
        $pdf = app(\App\Services\Reports\Exports\ReportPdfExporter::class)->download($report,'journey.pdf');
        $this->assertStringStartsWith('%PDF',$pdf->getContent());
    }

    public function test_word_export_escapes_clinical_text_so_the_document_is_readable(): void
    {
        DB::table('hospital_letters')->where('letter_id',103)->update(['content_summary'=>'Blood pressure < 100 & needs follow-up > urgent']);
        $report = app(CaseJourneyReport::class)->generate($this->filters(['case_id'=>1]),$this->user(true),false) + [
            'title'=>'CASE JOURNEY AND OUTCOMES REPORT','period'=>'September 2026','generated_at'=>'2026-10-10','generated_by'=>'Test operator','filters'=>[]];
        $response = app(\App\Services\Reports\Exports\ReportWordExporter::class)->download($report,'journey.docx');
        ob_start(); $response->sendContent(); $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(),'journey-word-'); file_put_contents($file,$bytes);
        try {
            $zip = new \ZipArchive; $this->assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml'); $zip->close();
            $dom = new \DOMDocument; $this->assertTrue($dom->loadXML($xml));
            $this->assertStringContainsString('Blood pressure < 100 & needs follow-up > urgent',$dom->textContent);
        } finally { unlink($file); }
    }

    public function test_report_endpoint_accepts_the_new_contract_and_checks_permissions(): void
    {
        $payload = ['report_type'=>'case_journey','start_date'=>'2026-09-01','end_date'=>'2026-09-30','per_page'=>1,'hospital_ids'=>[5]];
        $this->postJson('/api/reports/generate',$payload)->assertUnauthorized();
        $denied = $this->user(); $denied->forceFill(['id'=>100,'is_blocked'=>false]);
        $this->actingAs($denied,'sanctum')->postJson('/api/reports/generate',$payload)->assertForbidden();
        $allowed = Mockery::mock(User::class)->makePartial(); $allowed->forceFill(['id'=>101,'is_blocked'=>false,'first_name'=>'Report','last_name'=>'Operator']);
        $allowed->shouldReceive('hasRole','hasAnyRole')->andReturn(false);
        $allowed->shouldReceive('can')->andReturnUsing(fn ($p)=>$p==='View Report');
        $this->actingAs($allowed,'sanctum')->postJson('/api/reports/generate',$payload)->assertOk()
            ->assertJsonPath('data.key','case_journey')->assertJsonPath('data.summary.cases',1)
            ->assertJsonPath('data.rows.0.matibabu_card','0001')->assertJsonPath('data.confidential',true);
        $this->postJson('/api/reports/generate',array_replace($payload,['outcome'=>'Closed']))->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->postJson('/api/reports/searchReferralReport',['from_hospital_name'=>'Source Two','to_hospital_name'=>'Hospital A'])
            ->assertOk()->assertJsonPath('data.0.from_hospital_name','Source Two')->assertJsonPath('data.0.to_hospital_name','Hospital A');
        $this->postJson('/api/reports/searchReferralReport',['end_date'=>'2026-09-30'])->assertOk();
    }
}
