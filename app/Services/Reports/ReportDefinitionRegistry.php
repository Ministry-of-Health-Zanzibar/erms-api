<?php

namespace App\Services\Reports;

use InvalidArgumentException;

final class ReportDefinitionRegistry
{
    public const TOP_DIAGNOSES = 'top_diagnoses';

    public const REFERRALS_BY_HOSPITAL = 'referrals_by_hospital';
    public const CASE_WORKFLOW = 'case_workflow';
    public const BOARDED_OUT_CASES = 'boarded_out_cases';
    public const PATIENT_SUMMARY = 'patient_summary';
    public const CASE_JOURNEY = 'case_journey';

    /**
     * The public report catalogue. Keep database names out of this structure;
     * it is also returned to the frontend to build the filter experience.
     */
    public function all(): array
    {
        $reports = [
            self::TOP_DIAGNOSES => [
                'key' => self::TOP_DIAGNOSES,
                'name' => 'Top Diagnoses',
                'description' => 'Rank medical-board diagnoses with optional referral and patient-level analysis.',
                'metric' => 'Unique patients per medical-board diagnosis. A patient is counted once per diagnosis in the selected period.',
                'filters' => [
                    'date_range',
                    'result_limit',
                    'gender',
                    'age',
                    'location',
                    'diagnosis',
                    'source_hospital',
                    'referral_hospital',
                    'referral_status',
                    'referral_type',
                    'patient_history_status',
                    'detail_level',
                ],
                'group_by' => [
                    ['value' => 'diagnosis', 'label' => 'Diagnosis'],
                ],
                'detail_levels' => [
                    ['value' => 'summary', 'label' => 'Summary only'],
                    ['value' => 'breakdown', 'label' => 'Summary + breakdown'],
                    ['value' => 'details', 'label' => 'Summary + patient details'],
                ],
                'exports' => ['xlsx', 'pdf', 'docx'],
            ],
            self::REFERRALS_BY_HOSPITAL => [
                'key' => self::REFERRALS_BY_HOSPITAL,
                'name' => 'Referral Analysis Report',
                'description' => 'Generate a dynamic Ministry-style referral analysis from the selected period and filters.',
                'metric' => 'One filtered referral record is counted once. Patient, diagnosis, destination, trend, gender and status sections use definitions appropriate to each measure.',
                'filters' => [
                    'date_range',
                    'result_limit',
                    'group_by',
                    'detail_level',
                    'source_hospital',
                    'referral_hospital',
                    'referral_status',
                    'referral_type',
                    'gender',
                    'age',
                    'location',
                    'diagnosis',
                    'patient_search',
                ],
                'group_by' => [
                    ['value' => 'destination', 'label' => 'Receiving hospital'],
                    ['value' => 'month', 'label' => 'Month'],
                    ['value' => 'quarter', 'label' => 'Quarter'],
                    ['value' => 'year', 'label' => 'Year'],
                    ['value' => 'gender', 'label' => 'Gender'],
                    ['value' => 'diagnosis', 'label' => 'Diagnosis'],
                    ['value' => 'status', 'label' => 'Referral status'],
                ],
                'detail_levels' => [
                    ['value' => 'summary', 'label' => 'Summary only'],
                    ['value' => 'breakdown', 'label' => 'Summary + breakdown'],
                    ['value' => 'details', 'label' => 'Summary + patient details'],
                ],
                'exports' => ['xlsx', 'pdf', 'docx'],
            ],
        ];
        foreach ([
            self::CASE_WORKFLOW => ['Case Workflow Report', 'One row per medical history case, with its current workflow status.'],
            self::BOARDED_OUT_CASES => ['Boarded-out Case Report', 'One row per boarded-out case, with its letter and linked hospital where available.'],
            self::PATIENT_SUMMARY => ['Patient Summary', 'One row per patient, showing their latest eligible case in the selected period.'],
        ] as $key => [$name, $description]) {
            $reports[$key] = [
                'key' => $key, 'name' => $name, 'description' => $description,
                'metric' => $key === self::PATIENT_SUMMARY ? 'One patient counted once using their latest eligible case.' : 'One medical history ID counted once; dates use case submission date.',
                'filters' => ['date_range', 'source_hospital', 'patient_history_status', 'patient_search', 'include_archived'],
                'exports' => ['xlsx', 'pdf', 'docx'],
            ];
        }
        $reports[self::CASE_JOURNEY] = [
            'key' => self::CASE_JOURNEY, 'name' => 'Case Journey and Outcomes',
            'description' => 'Case movements, hospital transfers and recorded follow-up outcomes, separate from approval tracking.',
            'metric' => 'One medical history is one case. Dates select recorded activities, not only case submissions. Outcomes belong to individual hospital referrals.',
            'filters' => ['date_range', 'source_hospital', 'referral_hospital', 'patient_history_status', 'patient_search', 'referral_search', 'outcome', 'include_archived'],
            'detail_levels' => [['value' => 'summary', 'label' => 'Case summary'], ['value' => 'details', 'label' => 'Complete case journey']],
            'exports' => ['xlsx', 'pdf', 'docx'],
        ];
        return $reports;
    }

    public function get(string $key): array
    {
        $definition = $this->all()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Unsupported report type.');
        }

        return $definition;
    }
}
