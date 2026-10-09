<?php

namespace Tests\Unit\Services\Reports;

use App\Http\Requests\Reports\GenerateReportRequest;
use App\Services\Reports\ReportDefinitionRegistry;
use App\Services\Reports\ReportFilterNormalizer;
use App\Services\Reports\ReportTitleBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReportModuleTest extends TestCase
{
    public function test_report_catalogue_contains_the_requested_report_families_and_exports(): void
    {
        $definitions = (new ReportDefinitionRegistry)->all();

        $this->assertArrayHasKey('top_diagnoses', $definitions);
        $this->assertArrayHasKey('referrals_by_hospital', $definitions);
        $this->assertSame(['xlsx', 'pdf', 'docx'], $definitions['top_diagnoses']['exports']);
        $this->assertContains('diagnosis', $definitions['top_diagnoses']['filters']);
        $this->assertContains('source_hospital', $definitions['top_diagnoses']['filters']);
        foreach (['case_workflow', 'boarded_out_cases', 'patient_summary'] as $type) {
            $this->assertArrayHasKey($type, $definitions);
            $this->assertContains('include_archived', $definitions[$type]['filters']);
        }
    }

    public function test_filter_normalization_removes_empty_values_and_clamps_report_limits(): void
    {
        $filters = (new ReportFilterNormalizer)->normalize([
            'report_type' => 'top_diagnoses',
            'start_date' => '2026-03-01',
            'end_date' => '2026-09-24',
            'top' => 1000,
            'age_group' => '65+',
            'hospital_ids' => ['', 4, 4, -2],
            'source_hospital_ids' => ['5', 5, -1],
            'gender' => '',
            'patient_search' => '  ',
            'per_page' => 1000,
        ]);

        $this->assertSame(10, $filters['top']);
        $this->assertSame([4], $filters['hospital_ids']);
        $this->assertSame([5], $filters['source_hospital_ids']);
        $this->assertSame(65, $filters['age_from']);
        $this->assertNull($filters['age_to']);
        $this->assertNull($filters['gender']);
        $this->assertNull($filters['patient_search']);
        $this->assertSame(100, $filters['per_page']);
    }

    public function test_title_and_filename_reflect_report_parameters(): void
    {
        $filters = [
            'top' => 20,
            'gender' => 'female',
            'start_date' => '2026-03-01',
            'end_date' => '2026-09-24',
        ];
        $labels = ['hospitalNames' => []];
        $builder = new ReportTitleBuilder;

        $this->assertSame(
            'TOP 20 DIAGNOSES REPORT – FEMALE PATIENTS',
            $builder->title('top_diagnoses', $filters, $labels),
        );
        $this->assertSame(
            'top_20_diagnoses_2026-03-01_to_2026-09-24.pdf',
            $builder->filename('top_diagnoses', $filters, 'pdf', $labels),
        );
    }

    public function test_report_request_normalizes_numeric_multi_select_ids_before_validation(): void
    {
        $request = GenerateReportRequest::create('/api/reports/generate', 'POST', [
            'hospital_ids' => ['12', '34'],
            'source_hospital_ids' => ['90', '91'],
            'diagnosis_id' => '56',
            'referral_type_id' => '78',
        ]);

        $prepare = new ReflectionMethod(GenerateReportRequest::class, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $this->assertSame([12, 34], $request->input('hospital_ids'));
        $this->assertSame([90, 91], $request->input('source_hospital_ids'));
        $this->assertSame(56, $request->input('diagnosis_id'));
        $this->assertSame(78, $request->input('referral_type_id'));
    }
}
