<?php

namespace App\Services\Reports;

use Carbon\Carbon;

final class ReportTitleBuilder
{
    public function title(string $reportType, array $filters, array $labels = []): string
    {
        if ($reportType === ReportDefinitionRegistry::CASE_JOURNEY) {
            return 'CASE JOURNEY AND OUTCOMES REPORT';
        }
        if (in_array($reportType, ['case_workflow', 'boarded_out_cases', 'patient_summary'], true)) {
            return strtoupper(str_replace('_', ' ', $reportType)).' REPORT';
        }
        if ($reportType === ReportDefinitionRegistry::TOP_DIAGNOSES) {
            $limit = $filters['result_limit'] ?? ($filters['top'] ?? 10);
            $title = $limit === 'all'
                ? 'DIAGNOSIS DISTRIBUTION REPORT'
                : 'TOP '.$limit.' DIAGNOSES REPORT';
            $context = [];

            if ($filters['gender'] !== null) {
                $context[] = strtoupper($this->genderLabel($filters['gender'])).' PATIENTS';
            }

            $sourceHospitalNames = $labels['sourceHospitalNames'] ?? [];
            if (count($sourceHospitalNames) === 1) {
                $context[] = 'FROM '.strtoupper((string) $sourceHospitalNames[0]);
            }

            if ($context !== []) {
                $title .= ' – '.implode(' – ', $context);
            }

            return $title;
        }

        $title = match ($filters['group_by'] ?? 'destination') {
            'month' => 'MONTHLY REFERRAL REPORT',
            'quarter' => 'QUARTERLY REFERRAL REPORT',
            'year' => 'ANNUAL REFERRAL REPORT',
            'gender' => 'REFERRAL REPORT BY GENDER',
            'diagnosis' => 'DIAGNOSIS DISTRIBUTION REPORT',
            'status' => 'REFERRAL STATUS REPORT',
            default => 'REFERRAL ANALYSIS REPORT',
        };
        $hospitalNames = $labels['hospitalNames'] ?? [];
        $sourceHospitalNames = $labels['sourceHospitalNames'] ?? [];
        $context = [];

        if (count($hospitalNames) === 1) {
            $context[] = strtoupper((string) $hospitalNames[0]);
        }

        if (count($sourceHospitalNames) === 1) {
            $context[] = 'FROM '.strtoupper((string) $sourceHospitalNames[0]);
        }

        if ($context !== []) {
            $title .= ' – '.implode(' – ', $context);
        }

        return $title;
    }

    public function filename(string $reportType, array $filters, string $format, array $labels = []): string
    {
        if ($reportType === ReportDefinitionRegistry::CASE_JOURNEY) {
            return 'case_journey_'.$filters['start_date'].'_to_'.$filters['end_date'].'.'.$format;
        }
        if (in_array($reportType, ['case_workflow', 'boarded_out_cases', 'patient_summary'], true)) {
            return $reportType.'_'.$filters['start_date'].'_to_'.$filters['end_date'].'.'.$format;
        }
        $base = $reportType === ReportDefinitionRegistry::TOP_DIAGNOSES
            ? (($filters['result_limit'] ?? ($filters['top'] ?? 10)) === 'all'
                ? 'diagnosis_distribution_report'
                : 'top_'.($filters['result_limit'] ?? $filters['top']).'_diagnoses')
            : match ($filters['group_by'] ?? 'destination') {
                'month' => 'monthly_referral_report',
                'quarter' => 'quarterly_referral_report',
                'year' => 'annual_referral_report',
                'gender' => 'referral_report_by_gender',
                'diagnosis' => 'diagnosis_distribution_report',
                'status' => 'referral_status_report',
                default => 'referral_analysis_report',
            };

        if (($labels['hospitalNames'] ?? []) !== [] && count($labels['hospitalNames']) === 1) {
            $base .= '_'.strtolower($this->slug((string) $labels['hospitalNames'][0]));
        }

        if (($labels['sourceHospitalNames'] ?? []) !== [] && count($labels['sourceHospitalNames']) === 1) {
            $base .= '_from_'.strtolower($this->slug((string) $labels['sourceHospitalNames'][0]));
        }

        return $base.'_'.$filters['start_date'].'_to_'.$filters['end_date'].'.'.$format;
    }

    public function periodLabel(array $filters): string
    {
        return Carbon::parse($filters['start_date'])->format('d F Y')
            .' – '.Carbon::parse($filters['end_date'])->format('d F Y');
    }

    private function genderLabel(string $gender): string
    {
        return match (strtolower($gender)) {
            'male', 'm' => 'Male',
            'female', 'f' => 'Female',
            default => 'Other',
        };
    }

    private function slug(string $value): string
    {
        $value = preg_replace('/[^a-z0-9]+/i', '_', trim($value)) ?? 'hospital';

        return trim(strtolower($value), '_');
    }
}
