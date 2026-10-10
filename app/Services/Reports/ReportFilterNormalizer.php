<?php

namespace App\Services\Reports;

use Carbon\Carbon;

final class ReportFilterNormalizer
{
    private const TOP_LIMITS = [5, 10, 20, 50, 100];

    private const AGE_GROUPS = [
        '0-4' => [0, 4],
        '5-14' => [5, 14],
        '15-24' => [15, 24],
        '25-34' => [25, 34],
        '35-44' => [35, 44],
        '45-54' => [45, 54],
        '55-64' => [55, 64],
        '65+' => [65, null],
    ];

    public function normalize(array $input): array
    {
        $reportType = (string) ($input['report_type'] ?? ReportDefinitionRegistry::TOP_DIAGNOSES);
        $resultLimit = $this->resultLimit(
            $input['result_limit'] ?? ($input['top'] ?? null),
            $reportType === ReportDefinitionRegistry::TOP_DIAGNOSES ? 10 : 'all',
        );

        $ageGroup = isset($input['age_group']) && $input['age_group'] !== ''
            ? (string) $input['age_group']
            : null;

        $ageFrom = $this->nullableInteger($input['age_from'] ?? null);
        $ageTo = $this->nullableInteger($input['age_to'] ?? null);

        if ($ageGroup !== null && isset(self::AGE_GROUPS[$ageGroup])) {
            [$ageFrom, $ageTo] = self::AGE_GROUPS[$ageGroup];
        }

        return [
            'report_type' => $reportType,
            'start_date' => Carbon::parse((string) $input['start_date'])->toDateString(),
            'end_date' => Carbon::parse((string) $input['end_date'])->toDateString(),
            'top' => $this->topLimit($input['top'] ?? 10),
            'result_limit' => $resultLimit,
            'group_by' => $this->nullableString($input['group_by'] ?? ($reportType === ReportDefinitionRegistry::TOP_DIAGNOSES ? 'diagnosis' : 'destination')),
            'detail_level' => $this->nullableString($input['detail_level'] ?? 'breakdown'),
            'gender' => $this->nullableString($input['gender'] ?? null),
            'age_from' => $ageFrom,
            'age_to' => $ageTo,
            'age_group' => $ageGroup,
            'location_id' => $this->nullableString($input['location_id'] ?? null),
            'diagnosis_id' => $this->nullableInteger($input['diagnosis_id'] ?? null),
            'hospital_ids' => $this->hospitalIds($input['hospital_ids'] ?? ($input['hospital_id'] ?? [])),
            'source_hospital_ids' => $this->hospitalIds($input['source_hospital_ids'] ?? []),
            'referral_status' => $this->nullableString($input['referral_status'] ?? null),
            'referral_type_id' => $this->nullableInteger($input['referral_type_id'] ?? null),
            'patient_history_status' => $this->nullableString($input['patient_history_status'] ?? null),
            'patient_search' => $this->nullableString($input['patient_search'] ?? null),
            'referral_search' => $this->nullableString($input['referral_search'] ?? null),
            'outcome' => $this->nullableString($input['outcome'] ?? null),
            'case_id' => $this->nullableInteger($input['case_id'] ?? null),
            'include_archived' => filter_var($input['include_archived'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'page' => max((int) ($input['page'] ?? 1), 1),
            'per_page' => min(max((int) ($input['per_page'] ?? 25), 1), 100),
        ];
    }

    public function topLimit(mixed $value): int
    {
        $value = (int) $value;

        return in_array($value, self::TOP_LIMITS, true) ? $value : 10;
    }

    public function resultLimit(mixed $value, int|string $default = 'all'): int|string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && strtolower(trim($value)) === 'all') {
            return 'all';
        }

        $value = (int) $value;

        return in_array($value, self::TOP_LIMITS, true) ? $value : $default;
    }

    public function ageGroups(): array
    {
        return array_keys(self::AGE_GROUPS);
    }

    private function hospitalIds(mixed $value): array
    {
        $hospitalIds = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $hospitalIds),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
