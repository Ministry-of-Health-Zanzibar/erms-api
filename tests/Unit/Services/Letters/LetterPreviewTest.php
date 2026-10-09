<?php

namespace Tests\Unit\Services\Letters;

use App\Models\BoardedOutLetter;
use App\Models\Hospital;
use App\Models\HospitalLetter;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\PatientList;
use App\Models\Referral;
use App\Models\ReferralFlight;
use App\Models\ReferralLetter;
use App\Services\Letters\LetterBrandingService;
use App\Services\Letters\LetterDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class LetterPreviewTest extends TestCase
{
    private LetterDocumentService $documents;
    private ReferralLetter $referralLetter;
    private HospitalLetter $followUp;
    private BoardedOutLetter $boardedOut;
    private array $assets;
    private int $renders = 0;
    private array $renderedHtml = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 12:00:00');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        Cache::flush();
        $this->assets = ['signatureData' => '', 'stampData' => ''];
        $branding = Mockery::mock(LetterBrandingService::class);
        $branding->shouldReceive('effectiveAssets')->andReturnUsing(fn () => $this->assets);
        $this->documents = new LetterDocumentService($branding);
        Pdf::shouldReceive('loadHTML')->andReturnUsing(function (string $html) {
            $this->renders++;
            $this->renderedHtml[] = $html;
            $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
            $pdf->shouldReceive('setPaper')->with('a4', 'portrait')->andReturnSelf();
            $pdf->shouldReceive('setOptions')->andReturnSelf();
            $pdf->shouldReceive('output')->andReturn('%PDF-test-' . hash('sha256', $html));
            return $pdf;
        });

        $patient = (new Patient())->forceFill(['patient_id' => 1, 'name' => 'Preview Patient', 'date_of_birth' => '1990-10-10']);
        $patient->setRelation('patientList', collect([(new PatientList())->forceFill(['board_date' => '2026-10-01'])]));
        $hospital = (new Hospital())->forceFill(['hospital_id' => 1, 'hospital_name' => 'Original Hospital', 'hospital_address' => 'Test Address']);
        $referral = (new Referral())->forceFill(['referral_id' => 1, 'hospital_id' => 1]);
        $referral->setRelation('patient', $patient)->setRelation('hospital', $hospital);
        $referral->setRelation('referralFlights', collect([(new ReferralFlight())->forceFill(['flight_number' => 'TEST-1'])]));
        $this->referralLetter = (new ReferralLetter())->setDateFormat('Y-m-d H:i:s')->forceFill(['referral_letter_id' => 1, 'start_date' => '2026-10-09']);
        $this->referralLetter->setRelation('referral', $referral);
        $this->followUp = (new HospitalLetter())->forceFill(['letter_id' => 1, 'next_appointment_date' => '2026-10-12']);
        $this->followUp->setRelation('referral', $referral);
        $history = (new PatientHistory())->forceFill(['patient_histories_id' => 1]);
        $history->setRelation('patient', $patient);
        $this->boardedOut = (new BoardedOutLetter())->setDateFormat('Y-m-d H:i:s')->forceFill(['id' => 1, 'receiver' => 'Test Director, Zanzibar',
            'reference_number' => 'TEST-BO', 'reference_date' => '2026-10-01', 'created_at' => now(), 'recommendations' => ['Test recommendation']]);
        $this->boardedOut->setRelation('patientHistory', $history);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function cachedPdfs(): array
    {
        $storage = (new \ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore());
        return array_filter($storage, fn ($key) => str_starts_with($key, 'letter-pdf:'), ARRAY_FILTER_USE_KEY);
    }

    public function test_all_letter_types_reuse_unchanged_pdfs_and_encrypt_cached_bytes(): void
    {
        foreach ([['renderReferral', $this->referralLetter], ['renderFollowUp', $this->followUp], ['renderBoardedOut', $this->boardedOut]] as [$method, $letter]) {
            $first = $this->documents->$method($letter, 'sw');
            $this->assertSame($first, $this->documents->$method($letter, 'sw'));
        }
        $this->assertSame(3, $this->renders);
        $this->assertCount(3, $this->cachedPdfs());
        foreach ($this->cachedPdfs() as $item) {
            $this->assertStringNotContainsString('%PDF-', $item['value']);
            $this->assertStringNotContainsString('Preview Patient', $item['value']);
            $this->assertStringStartsWith('%PDF-', Crypt::decryptString($item['value']));
        }
    }

    public function test_referral_patient_hospital_flight_language_and_branding_changes_regenerate_pdf(): void
    {
        $render = fn () => $this->documents->renderReferral($this->referralLetter, 'en');
        $previous = $render();
        $changes = [
            fn () => $this->referralLetter->referral->patient->name = 'Updated Patient',
            fn () => $this->referralLetter->referral->hospital->hospital_name = 'Updated Hospital',
            fn () => $this->referralLetter->referral->referralFlights->first()->flight_number = 'TEST-2',
            fn () => $this->assets['signatureData'] = 'data:image/png;base64,new-signature',
        ];
        foreach ($changes as $change) {
            $change();
            $current = $render();
            $this->assertNotSame($previous, $current);
            $previous = $current;
        }
        $this->assertNotSame($previous, $this->documents->renderReferral($this->referralLetter, 'sw'));
        $this->assertSame(6, $this->renders);
    }

    public function test_followup_appointment_and_boarded_out_decision_changes_are_not_stale(): void
    {
        $followup = $this->documents->renderFollowUp($this->followUp, 'sw');
        $this->followUp->next_appointment_date = '2026-10-20';
        $this->assertNotSame($followup, $this->documents->renderFollowUp($this->followUp, 'sw'));
        $boarded = $this->documents->renderBoardedOut($this->boardedOut, 'sw');
        $this->boardedOut->recommendations = ['Changed recommendation'];
        $this->assertNotSame($boarded, $updated = $this->documents->renderBoardedOut($this->boardedOut, 'sw'));
        $this->boardedOut->patientHistory->patient->patientList->first()->board_date = '2026-10-05';
        $this->assertNotSame($updated, $this->documents->renderBoardedOut($this->boardedOut, 'sw'));
        $this->assertSame(5, $this->renders);
    }

    public function test_print_audit_updates_do_not_regenerate_unchanged_content(): void
    {
        $first = $this->documents->renderReferral($this->referralLetter, 'sw');
        $this->referralLetter->forceFill(['is_printed' => true, 'print_count' => 5, 'printed_at' => now(), 'updated_at' => now()]);
        $this->assertSame($first, $this->documents->renderReferral($this->referralLetter, 'sw'));
        $this->assertSame(1, $this->renders);
    }

    public function test_corrupt_or_expired_cache_is_safely_regenerated(): void
    {
        $first = $this->documents->renderReferral($this->referralLetter, 'sw');
        $key = array_key_first($this->cachedPdfs());
        Cache::put($key, 'invalid-ciphertext', 600);
        $this->assertSame($first, $this->documents->renderReferral($this->referralLetter, 'sw'));
        $this->assertSame(2, $this->renders);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame($first, $this->documents->renderReferral($this->referralLetter, 'sw'));
        $this->assertSame(3, $this->renders);
    }

    public function test_cache_failure_does_not_prevent_preview(): void
    {
        Cache::shouldReceive('get', 'put')->andThrow(new \RuntimeException('Test cache unavailable'));
        $this->assertStringStartsWith('%PDF-', $this->documents->renderReferral($this->referralLetter, 'sw'));
        $this->assertSame(1, $this->renders);
    }

    public function test_larger_stamp_fits_the_signature_area_in_every_letter_and_language(): void
    {
        foreach ([['renderReferral', $this->referralLetter], ['renderFollowUp', $this->followUp], ['renderBoardedOut', $this->boardedOut]] as [$method, $letter]) {
            foreach (['sw', 'en'] as $language) {
                $this->documents->$method($letter, $language);
                $html = end($this->renderedHtml);
                $this->assertMatchesRegularExpression('/\.stamp-cell\s*\{[^}]*top: 3mm;[^}]*width: 45mm; height: 27mm;/s', $html);
                $this->assertMatchesRegularExpression('/\.stamp\s*\{[^}]*width: 45mm; height: 27mm;/s', $html);
                $this->assertStringContainsString('min-height: 33mm', $html);
                $this->assertStringContainsString('class="stamp"', $html);
            }
        }
        $this->assertSame(6, $this->renders);
    }
}
