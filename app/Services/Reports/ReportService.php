<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Pagination;
use App\Support\ReportDataScope;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    public function __construct(
        private readonly ReportDefinitionRegistry $definitions,
        private readonly ReportFilterNormalizer $normalizer,
        private readonly ReportTitleBuilder $titleBuilder,
        private readonly ReportDataScope $scope,
        private readonly DynamicReferralReport $dynamicReferralReport,
        private readonly CaseReport $caseReport,
    ) {
    }

    public function definitions(): array
    {
        return array_values($this->definitions->all());
    }

    public function normalize(array $input): array
    {
        return $this->normalizer->normalize($input);
    }

    public function generate(array $input, User $user, bool $paginate = true): array
    {
        $filters = $this->normalize($input);
        $labels = $this->resolveFilterLabels($filters, $user);
        $definition = $this->definitions->get($filters['report_type']);
        $title = $this->titleBuilder->title($filters['report_type'], $filters, $labels);

        $result = match ($filters['report_type']) {
            ReportDefinitionRegistry::TOP_DIAGNOSES => $this->topDiagnoses($filters, $user, $paginate),
            ReportDefinitionRegistry::REFERRALS_BY_HOSPITAL => $this->dynamicReferralReport->generate($filters, $user, $paginate),
            default => $this->caseReport->generate($filters, $user, $paginate),
        };

        $result['key'] = $filters['report_type'];
        $result['name'] = $definition['name'];
        $result['title'] = $title;
        $result['period'] = $this->titleBuilder->periodLabel($filters);
        $result['filters'] = $this->displayFilters($filters, $labels);
        $result['metric'] = $definition['metric'];
        $generatedAt = now();
        $result['generated_at'] = $generatedAt->toIso8601String();
        $result['generated_at_label'] = $generatedAt->format('d F Y H:i');
        $result['generated_by'] = $this->userName($user);
        $result['export_formats'] = $definition['exports'];
        $result['confidential'] = in_array($filters['report_type'], [
            ReportDefinitionRegistry::CASE_WORKFLOW, ReportDefinitionRegistry::BOARDED_OUT_CASES, ReportDefinitionRegistry::PATIENT_SUMMARY,
        ], true) || ($filters['detail_level'] ?? 'breakdown') === 'details';
        $result['filename_base'] = pathinfo(
            $this->titleBuilder->filename($filters['report_type'], $filters, 'xlsx', $labels),
            PATHINFO_FILENAME,
        );

        return $result;
    }

    public function exportFilename(array $input, string $format, User $user): string
    {
        $filters = $this->normalize($input);
        $labels = $this->resolveFilterLabels($filters, $user);

        return $this->titleBuilder->filename($filters['report_type'], $filters, $format, $labels);
    }

    public function filterOptions(User $user): array
    {
        $hospitalQuery = DB::table('hospitals')
            ->whereNull('hospitals.deleted_at')
            ->select('hospital_id', 'hospital_name', 'referral_type_id')
            ->orderBy('hospital_name');

        $allowedHospitalIds = $this->scope->hospitalIds($user);
        if ($allowedHospitalIds !== null) {
            $hospitalQuery->whereIn('hospital_id', $allowedHospitalIds);
        }

        $hospitals = $hospitalQuery->get()->map(static fn ($hospital): array => [
            'value' => (int) $hospital->hospital_id,
            'label' => $hospital->hospital_name,
        ])->values()->all();

        $locations = DB::table('geographical_locations')
            ->whereNull('deleted_at')
            ->select('location_id', 'location_name', 'parent_id', 'label')
            ->orderBy('location_name')
            ->get()
            ->map(static fn ($location): array => [
                'value' => $location->location_id,
                'label' => $location->label ?: $location->location_name,
                'parent_id' => $location->parent_id,
            ])->values()->all();

        $referralTypes = DB::table('referral_types')
            ->whereNull('deleted_at')
            ->select('referral_type_id', 'referral_type_name', 'referral_type_code')
            ->orderBy('referral_type_name')
            ->get()
            ->map(static fn ($type): array => [
                'value' => (int) $type->referral_type_id,
                'label' => $type->referral_type_name.' ('.$type->referral_type_code.')',
            ])->values()->all();

        return [
            'hospitals' => $hospitals,
            // The source and destination selectors use the same authorized
            // hospital catalogue, but remain separate so the request and
            // applied-filter labels are unambiguous.
            'source_hospitals' => $hospitals,
            'locations' => $locations,
            'referral_types' => $referralTypes,
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'other', 'label' => 'Other / unknown'],
            ],
            'referral_statuses' => collect(['Pending', 'Confirmed', 'Death', 'Cancelled', 'Transferred', 'Expired', 'Closed', 'Requested', 'BoardedOut'])
                ->map(static fn (string $status): array => ['value' => $status, 'label' => $status])
                ->all(),
            'patient_history_statuses' => collect(['pending', 'reviewed', 'assigned', 'requested', 'approved', 'confirmed', 'boarded_out', 'rejected', 'under_review'])
                ->map(static fn (string $status): array => [
                    'value' => $status,
                    'label' => $status === 'boarded_out' ? 'Boarded Out' : ucfirst(str_replace('_', ' ', $status)),
                ])
                ->all(),
            'age_groups' => collect($this->normalizer->ageGroups())
                ->map(static fn (string $group): array => ['value' => $group, 'label' => $group.' years'])
                ->all(),
            'top_limits' => collect([5, 10, 20, 50, 100])
                ->map(static fn (int $limit): array => ['value' => $limit, 'label' => 'Top '.$limit])
                ->all(),
            'result_limits' => collect(['all', 5, 10, 20, 50, 100])
                ->map(static fn (int|string $limit): array => [
                    'value' => $limit,
                    'label' => $limit === 'all' ? 'All results' : 'Top '.$limit,
                ])->all(),
        ];
    }

    private function topDiagnoses(array $filters, User $user, bool $paginate): array
    {
        $pairs = $this->diagnosisPairs($filters, $user);
        $totalPairs = (int) DB::query()->fromSub(clone $pairs, 'diagnosis_pairs')->count();
        $referredCounts = $this->topDiagnosisReferredCounts($pairs, $filters, $user);

        $aggregateQuery = DB::query()
            ->fromSub(clone $pairs, 'diagnosis_pairs')
            ->select('diagnosis_id', 'diagnosis_code', 'diagnosis_name')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(gender, ''))) IN ('male', 'm') THEN 1 ELSE 0 END) AS male")
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(gender, ''))) IN ('female', 'f') THEN 1 ELSE 0 END) AS female")
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(gender, ''))) NOT IN ('male', 'm', 'female', 'f') THEN 1 ELSE 0 END) AS other")
            ->groupBy('diagnosis_id', 'diagnosis_code', 'diagnosis_name')
            ->orderByDesc('total')
            ->orderBy('diagnosis_name');

        if (($filters['result_limit'] ?? $filters['top']) !== 'all') {
            $aggregateQuery->limit((int) ($filters['result_limit'] ?? $filters['top']));
        }

        $aggregates = $aggregateQuery->get();

        $displayedPairs = (int) $aggregates->sum('total');

        $rows = $aggregates->values()->map(function ($row, int $index) use ($displayedPairs, $referredCounts): array {
            $total = (int) $row->total;
            $referred = (int) ($referredCounts[(int) $row->diagnosis_id] ?? 0);

            return [
                'rank' => $index + 1,
                'diagnosis_id' => (int) $row->diagnosis_id,
                'diagnosis_code' => $row->diagnosis_code,
                'diagnosis' => $row->diagnosis_name,
                'male' => (int) $row->male,
                'female' => (int) $row->female,
                'other' => (int) $row->other,
                'total' => $total,
                'percentage' => $displayedPairs > 0 ? round(($total / $displayedPairs) * 100, 2) : 0,
                'referred' => $referred,
                'referral_percentage' => $total > 0 ? round(($referred / $total) * 100, 2) : 0,
            ];
        })->all();

        $columns = [
            ['key' => 'rank', 'label' => 'Rank', 'type' => 'integer'],
            ['key' => 'diagnosis_code', 'label' => 'Diagnosis code', 'type' => 'text'],
            ['key' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text'],
            ['key' => 'male', 'label' => 'Male', 'type' => 'integer'],
            ['key' => 'female', 'label' => 'Female', 'type' => 'integer'],
            ['key' => 'other', 'label' => 'Other / unknown', 'type' => 'integer'],
            ['key' => 'total', 'label' => 'Patients', 'type' => 'integer'],
            ['key' => 'percentage', 'label' => 'Percentage', 'type' => 'percentage'],
            ['key' => 'referred', 'label' => 'Referred', 'type' => 'integer'],
            ['key' => 'referral_percentage', 'label' => 'Referral percentage', 'type' => 'percentage'],
        ];

        $detail = $filters['detail_level'] ?? 'breakdown';
        $sections = [
            [
                'key' => 'executive_summary',
                'title' => 'Executive Summary',
                'kind' => 'text',
                'text' => 'This report ranks '.$totalPairs.' unique patient/diagnosis pair'.($totalPairs === 1 ? '' : 's').' from medical-board diagnoses for '.$this->titleBuilder->periodLabel($filters).'.',
            ],
            [
                'key' => 'overview',
                'title' => 'Report Overview',
                'kind' => 'metrics',
                'metrics' => [
                    ['key' => 'patient_diagnosis_pairs', 'label' => 'Filtered patient/diagnosis pairs', 'value' => $totalPairs, 'type' => 'integer'],
                    ['key' => 'diagnoses_returned', 'label' => 'Diagnoses returned', 'value' => count($rows), 'type' => 'integer'],
                    ['key' => 'referred_pairs', 'label' => 'Referred patient/diagnosis pairs', 'value' => array_sum(array_column($rows, 'referred')), 'type' => 'integer'],
                ],
            ],
        ];

        if ($detail !== 'summary') {
            $sections[] = [
                'key' => 'diagnosis_distribution',
                'title' => 'Diagnosis Distribution',
                'kind' => 'table',
                'columns' => $columns,
                'rows' => $rows,
            ];
            $sections[] = [
                'key' => 'interpretation_notes',
                'title' => 'Data Quality and Interpretation Notes',
                'kind' => 'list',
                'items' => [
                    'Counts are based on unique patient/diagnosis pairs from medical-board diagnoses.',
                    'A referral percentage is the share of patients in each diagnosis group with a matching referral in the selected period.',
                    'Gender values are normalized; null and other values are retained under Other / unknown.',
                    'Diagnosis descriptions are reported as recorded and are not clinically recoded or merged.',
                ],
            ];
        }

        if ($detail === 'details') {
            $diagnosisIds = ($filters['result_limit'] ?? 'all') === 'all'
                ? null
                : $aggregates->pluck('diagnosis_id')->map(fn ($id): int => (int) $id)->all();
            $details = $this->topDiagnosisDetails($pairs, $filters, $user, $diagnosisIds, $paginate);
            $sections[] = [
                'key' => 'patient_details',
                'title' => 'Diagnosis-Level Patient Details',
                'kind' => 'table',
                'columns' => $details['columns'],
                'rows' => $details['rows'],
                'pagination' => $details['pagination'],
            ];
        }

        return [
            'summary' => [
                'filtered_patient_diagnosis_pairs' => $totalPairs,
                'diagnoses_returned' => count($rows),
                'top_result_pairs' => array_sum(array_column($rows, 'total')),
                'top_male_pairs' => array_sum(array_column($rows, 'male')),
                'top_female_pairs' => array_sum(array_column($rows, 'female')),
                'top_other_pairs' => array_sum(array_column($rows, 'other')),
                'top_referred_pairs' => array_sum(array_column($rows, 'referred')),
            ],
            'columns' => $columns,
            'rows' => $rows,
            'sections' => $sections,
            'pagination' => [
                'current_page' => 1,
                'per_page' => ($filters['result_limit'] ?? 'all') === 'all'
                    ? max(count($rows), 1)
                    : (int) ($filters['result_limit'] ?? $filters['top']),
                'from' => $rows === [] ? null : 1,
                'to' => count($rows),
                'total' => count($rows),
                'last_page' => 1,
                'has_more_pages' => false,
            ],
            'notes' => [
                'Counts are based on unique patient/diagnosis pairs from medical-board diagnoses.',
                'The report period is applied to patient history creation; referral filters additionally use referral creation time.',
                'Male and female values are normalized from the stored gender values; null and other values are retained under Other / unknown.',
                'Percentages use the displayed diagnosis result set as the denominator; selecting All uses all filtered unique patient/diagnosis pairs.',
            ],
        ];
    }

    private function topDiagnosisReferredCounts(Builder $pairs, array $filters, User $user): array
    {
        $query = DB::query()
            ->fromSub($this->reportSubquery($pairs), 'dp')
            ->join('diagnosis_referral as dr', function ($join): void {
                $join->on('dr.diagnosis_id', '=', 'dp.diagnosis_id');
            })
            ->join('referrals as r', function ($join) use ($filters): void {
                $join->on('r.referral_id', '=', 'dr.referral_id')
                    ->on('r.patient_id', '=', 'dp.patient_id')
                    ->whereNull('r.deleted_at')
                    ->where('r.created_at', '>=', $this->startAt($filters))
                    ->where('r.created_at', '<', $this->endExclusive($filters));
            })
            ->leftJoin('hospitals as h', function ($join): void {
                $join->on('h.hospital_id', '=', 'r.hospital_id')
                    ->whereNull('h.deleted_at');
            });

        $this->applyTopReferralFilters($query, $filters, $user, 'r', 'h');

        return $query
            ->select('dp.diagnosis_id')
            ->selectRaw('COUNT(DISTINCT dp.patient_id) AS referred')
            ->groupBy('dp.diagnosis_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->diagnosis_id => (int) $row->referred])
            ->all();
    }

    private function topDiagnosisDetails(Builder $pairs, array $filters, User $user, ?array $diagnosisIds, bool $paginate): array
    {
        if ($diagnosisIds === []) {
            return [
                'columns' => [
                    ['key' => 'no', 'label' => 'No.', 'type' => 'integer'],
                    ['key' => 'patient_name', 'label' => 'Patient name', 'type' => 'text'],
                    ['key' => 'gender', 'label' => 'Gender', 'type' => 'text'],
                    ['key' => 'patient_id', 'label' => 'Patient ID', 'type' => 'integer'],
                    ['key' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text'],
                    ['key' => 'destination_hospital', 'label' => 'Receiving hospital', 'type' => 'text'],
                    ['key' => 'referred_date', 'label' => 'Referral date', 'type' => 'date'],
                ],
                'rows' => [],
                'pagination' => $this->emptyPagination(),
            ];
        }

        $referralDetails = DB::table('diagnosis_referral as detail_dr')
            ->join('referrals as detail_r', function ($join) use ($filters): void {
                $join->on('detail_r.referral_id', '=', 'detail_dr.referral_id')
                    ->whereNull('detail_r.deleted_at')
                    ->where('detail_r.created_at', '>=', $this->startAt($filters))
                    ->where('detail_r.created_at', '<', $this->endExclusive($filters));
            })
            ->leftJoin('hospitals as detail_h', function ($join): void {
                $join->on('detail_h.hospital_id', '=', 'detail_r.hospital_id')
                    ->whereNull('detail_h.deleted_at');
            })
            ->select('detail_dr.diagnosis_id', 'detail_r.patient_id')
            ->selectRaw($this->hospitalAggregationSql('detail_h.hospital_name'))
            ->selectRaw('MIN(detail_r.created_at) AS referred_date')
            ->selectRaw($this->statusAggregationSql('detail_r.status'))
            ->groupBy('detail_dr.diagnosis_id', 'detail_r.patient_id');

        $this->applyTopReferralFilters($referralDetails, $filters, $user, 'detail_r', 'detail_h');

        $query = DB::query()
            ->fromSub($this->reportSubquery($pairs), 'dp')
            ->leftJoinSub($referralDetails, 'detail_referral', function ($join): void {
                $join->on('detail_referral.diagnosis_id', '=', 'dp.diagnosis_id')
                    ->on('detail_referral.patient_id', '=', 'dp.patient_id');
            });

        if ($diagnosisIds !== null) {
            $query->whereIn('dp.diagnosis_id', $diagnosisIds);
        }

        $query->select(
            'dp.patient_name',
            'dp.gender',
            'dp.patient_id',
            'dp.diagnosis_name',
            'detail_referral.destination_hospital',
            'detail_referral.referred_date',
            'detail_referral.referral_status',
        )
            ->orderBy('dp.diagnosis_name')
            ->orderBy('dp.patient_name')
            ->orderByRaw('detail_referral.referred_date NULLS LAST');

        $total = (int) (clone $query)->count();
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? Pagination::DEFAULT_PER_PAGE), 1), Pagination::MAX_PER_PAGE);
        $collection = $paginate ? $query->forPage($page, $perPage)->get() : $query->get();

        $rows = $collection->values()->map(function ($row, int $index) use ($page, $perPage, $paginate): array {
            return [
                'no' => ($paginate ? (($page - 1) * $perPage) : 0) + $index + 1,
                'patient_name' => $row->patient_name,
                'gender' => $this->topGenderLabel($row->gender),
                'patient_id' => (int) $row->patient_id,
                'diagnosis' => $row->diagnosis_name,
                'destination_hospital' => $row->destination_hospital ?: 'Not recorded',
                'referred_date' => $this->reportDate($row->referred_date),
                'referral_status' => $row->referral_status ?: 'Not recorded',
            ];
        })->all();

        return [
            'columns' => [
                ['key' => 'no', 'label' => 'No.', 'type' => 'integer'],
                ['key' => 'patient_name', 'label' => 'Patient name', 'type' => 'text'],
                ['key' => 'gender', 'label' => 'Gender', 'type' => 'text'],
                ['key' => 'patient_id', 'label' => 'Patient ID', 'type' => 'integer'],
                ['key' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text'],
                ['key' => 'destination_hospital', 'label' => 'Receiving hospital', 'type' => 'text'],
                ['key' => 'referred_date', 'label' => 'Referral date', 'type' => 'date'],
                ['key' => 'referral_status', 'label' => 'Referral status', 'type' => 'text'],
            ],
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginate ? $page : 1,
                'per_page' => $paginate ? $perPage : max($total, 1),
                'from' => $total === 0 ? null : (($paginate ? $page - 1 : 0) * ($paginate ? $perPage : 0)) + 1,
                'to' => $total === 0 ? null : (($paginate ? $page - 1 : 0) * ($paginate ? $perPage : 0)) + count($rows),
                'total' => $total,
                'last_page' => $paginate ? max(1, (int) ceil($total / $perPage)) : 1,
                'has_more_pages' => $paginate && $page * $perPage < $total,
            ],
        ];
    }

    private function applyTopReferralFilters(Builder $query, array $filters, User $user, string $referralAlias, string $hospitalAlias): void
    {
        $destinationIds = $this->effectiveHospitalIds($filters, $user);
        if ($destinationIds !== null) {
            if ($destinationIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn($referralAlias.'.hospital_id', $destinationIds);
            }
        }

        $sourceIds = $this->effectiveSourceHospitalIds($filters, $user);
        if ($sourceIds !== null) {
            if ($sourceIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $this->applyCreatedByHospitalConstraint($query, $sourceIds, $referralAlias.'.created_by');
            }
        }

        if (($filters['referral_status'] ?? null) !== null) {
            $query->where($referralAlias.'.status', $filters['referral_status']);
        }

        if (($filters['referral_type_id'] ?? null) !== null) {
            $query->where($hospitalAlias.'.referral_type_id', $filters['referral_type_id']);
        }
    }

    private function reportSubquery(Builder $query): Builder
    {
        $subquery = clone $query;
        $subquery->reorder();

        return $subquery;
    }

    private function emptyPagination(): array
    {
        return [
            'current_page' => 1,
            'per_page' => 1,
            'from' => null,
            'to' => null,
            'total' => 0,
            'last_page' => 1,
            'has_more_pages' => false,
        ];
    }

    private function reportDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $date)->format('d F Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private function topGenderLabel(mixed $gender): string
    {
        return match (strtolower(trim((string) ($gender ?? '')))) {
            'male', 'm' => 'Male',
            'female', 'f' => 'Female',
            default => 'Other / unknown',
        };
    }

    private function referralsByHospital(array $filters, User $user, bool $paginate): array
    {
        $query = $this->referralRowsQuery($filters, $user);
        $summaryQuery = DB::query()
            ->fromSub((clone $query)->reorder(), 'report_rows')
            ->selectRaw('COUNT(*) AS referrals')
            ->selectRaw('COUNT(DISTINCT patient_id) AS patients')
            ->selectRaw('COUNT(DISTINCT hospital_id) AS hospitals')
            ->first();
        $total = (int) ($summaryQuery->referrals ?? 0);

        $perPage = min(max((int) ($filters['per_page'] ?? Pagination::DEFAULT_PER_PAGE), 1), Pagination::MAX_PER_PAGE);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $rows = $paginate
            ? $query->forPage($page, $perPage)->get()
            : $query->cursor();

        $rows = $rows->map(static fn ($row): array => [
            'hospital' => $row->hospital_name,
            'referral_id' => (int) $row->referral_id,
            'referral_number' => $row->referral_number,
            'referral_status' => $row->referral_status,
            'referred_date' => $row->referred_date,
            'patient_id' => (int) $row->patient_id,
            'patient_name' => $row->patient_name,
            'gender' => $row->gender,
            'diagnoses' => $row->diagnoses ?: 'No diagnosis recorded',
        ]);

        if ($paginate) {
            $rows = $rows->values()->all();
        }

        $visibleRows = $paginate ? count($rows) : $total;
        $from = $visibleRows === 0 ? null : (($paginate ? $page - 1 : 0) * ($paginate ? $perPage : 0)) + 1;
        $to = $visibleRows === 0 ? null : (($paginate ? $page - 1 : 0) * ($paginate ? $perPage : 0)) + ($paginate ? count($rows) : $total);

        return [
            'summary' => [
                'referrals' => $total,
                'patients' => (int) ($summaryQuery->patients ?? 0),
                'hospitals' => (int) ($summaryQuery->hospitals ?? 0),
            ],
            'columns' => [
                ['key' => 'hospital', 'label' => 'Destination hospital', 'type' => 'text'],
                ['key' => 'referral_id', 'label' => 'Referral ID', 'type' => 'integer'],
                ['key' => 'referral_number', 'label' => 'Referral number', 'type' => 'text'],
                ['key' => 'referral_status', 'label' => 'Referral status', 'type' => 'text'],
                ['key' => 'referred_date', 'label' => 'Referred date', 'type' => 'date'],
                ['key' => 'patient_id', 'label' => 'Patient ID', 'type' => 'integer'],
                ['key' => 'patient_name', 'label' => 'Patient name', 'type' => 'text'],
                ['key' => 'gender', 'label' => 'Gender', 'type' => 'text'],
                ['key' => 'diagnoses', 'label' => 'Diagnoses', 'type' => 'text'],
            ],
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginate ? $page : 1,
                'per_page' => $paginate ? $perPage : max($total, 1),
                'from' => $from,
                'to' => $to,
                'total' => $total,
                'last_page' => $paginate ? (int) max(1, ceil($total / $perPage)) : 1,
                'has_more_pages' => $paginate ? ($page * $perPage < $total) : false,
            ],
            'notes' => [
                'One row represents one non-deleted referral to a destination hospital.',
                'The report period is applied to referral creation time.',
                'Diagnosis names are aggregated from the referral diagnosis relationship and are not used to duplicate referral rows.',
            ],
        ];
    }

    private function diagnosisPairs(array $filters, User $user): Builder
    {
        $query = DB::table('diagnoses as d')
            ->join('history_diagnosis as hd', 'hd.diagnosis_id', '=', 'd.diagnosis_id')
            ->join('patient_histories as ph', 'ph.patient_histories_id', '=', 'hd.patient_histories_id')
            ->join('patients as p', 'p.patient_id', '=', 'ph.patient_id')
            ->whereNull('d.deleted_at')
            ->whereNull('ph.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('hd.added_by', 'medical_board')
            ->where('ph.created_at', '>=', $this->startAt($filters))
            ->where('ph.created_at', '<', $this->endExclusive($filters));

        $query->select('d.diagnosis_id', 'd.diagnosis_code', 'd.diagnosis_name', 'p.patient_id', 'p.name as patient_name', 'p.gender')
            ->distinct();

        $this->scope->applyPatientScope($query, $user, 'p');
        $this->applyPatientFilters($query, $filters, 'p');

        if ($filters['patient_history_status'] === 'under_review') {
            $query->whereIn('ph.status', CaseReport::OPEN_STATUSES);
        } elseif ($filters['patient_history_status'] !== null) {
            $query->where('ph.status', $filters['patient_history_status']);
        }

        if ($filters['diagnosis_id'] !== null) {
            $query->where('d.diagnosis_id', $filters['diagnosis_id']);
        }

        $this->applyReferralConstraint($query, $filters, $user, 'p.patient_id', 'd.diagnosis_id');

        return $query;
    }

    private function referralRowsQuery(array $filters, User $user): Builder
    {
        $query = DB::table('referrals as r')
            ->join('patients as p', 'p.patient_id', '=', 'r.patient_id')
            ->join('hospitals as h', 'h.hospital_id', '=', 'r.hospital_id')
            ->leftJoin('diagnosis_referral as dr', 'dr.referral_id', '=', 'r.referral_id')
            ->leftJoin('diagnoses as d', function ($join): void {
                $join->on('d.diagnosis_id', '=', 'dr.diagnosis_id')
                    ->whereNull('d.deleted_at');
            })
            ->whereNull('r.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('h.deleted_at')
            ->where('r.created_at', '>=', $this->startAt($filters))
            ->where('r.created_at', '<', $this->endExclusive($filters));

        $this->scope->applyPatientScope($query, $user, 'p');

        $sourceHospitalIds = $this->effectiveSourceHospitalIds($filters, $user);
        if ($sourceHospitalIds !== null) {
            if ($sourceHospitalIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $this->applyCreatedByHospitalConstraint($query, $sourceHospitalIds, 'r.created_by');
            }
        }

        $query->select(
            'h.hospital_id',
            'h.hospital_name',
            'r.referral_id',
            'r.referral_number',
            'r.status as referral_status',
            'r.created_at as referred_date',
            'p.patient_id',
            'p.name as patient_name',
            'p.gender',
        );

        $query->selectRaw($this->diagnosisAggregationSql());
        $query->groupBy(
            'h.hospital_id',
            'h.hospital_name',
            'r.referral_id',
            'r.referral_number',
            'r.status',
            'r.created_at',
            'p.patient_id',
            'p.name',
            'p.gender',
        );

        $selectedHospitals = $this->effectiveHospitalIds($filters, $user);
        if ($selectedHospitals !== null) {
            if ($selectedHospitals === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('r.hospital_id', $selectedHospitals);
            }
        }

        if ($filters['referral_status'] !== null) {
            $query->where('r.status', $filters['referral_status']);
        }

        if ($filters['referral_type_id'] !== null) {
            $query->where('h.referral_type_id', $filters['referral_type_id']);
        }

        $this->applyPatientFilters($query, $filters, 'p');

        if ($filters['patient_search'] !== null) {
            $term = mb_strtolower($filters['patient_search']);
            $query->where(function (Builder $search) use ($term): void {
                $search->whereRaw('LOWER(p.name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(p.phone) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(p.matibabu_card) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(p.zan_id) LIKE ?', [$term.'%']);
            });
        }

        if ($filters['diagnosis_id'] !== null) {
            $query->whereExists(function (Builder $exists) use ($filters): void {
                $exists->selectRaw('1')
                    ->from('diagnosis_referral as diagnosis_filter')
                    ->whereColumn('diagnosis_filter.referral_id', 'r.referral_id')
                    ->where('diagnosis_filter.diagnosis_id', $filters['diagnosis_id']);
            });
        }

        return $query
            ->orderBy('r.created_at')
            ->orderBy('p.name')
            ->orderBy('r.referral_id');
    }

    private function applyPatientFilters(Builder $query, array $filters, string $alias): void
    {
        if ($filters['gender'] !== null) {
            $gender = strtolower($filters['gender']);
            if ($gender === 'male') {
                $query->whereRaw("LOWER(TRIM(COALESCE({$alias}.gender, ''))) IN ('male', 'm')");
            } elseif ($gender === 'female') {
                $query->whereRaw("LOWER(TRIM(COALESCE({$alias}.gender, ''))) IN ('female', 'f')");
            } else {
                $query->whereRaw("LOWER(TRIM(COALESCE({$alias}.gender, ''))) NOT IN ('male', 'm', 'female', 'f')");
            }
        }

        if ($filters['location_id'] !== null) {
            $query->where("{$alias}.location_id", $filters['location_id']);
        }

        if ($filters['age_from'] !== null || $filters['age_to'] !== null) {
            $expression = $this->ageExpression($alias);
            if ($filters['age_from'] !== null) {
                $query->whereRaw("{$expression} >= ?", [$filters['age_from']]);
            }
            if ($filters['age_to'] !== null) {
                $query->whereRaw("{$expression} <= ?", [$filters['age_to']]);
            }
        }
    }

    private function applyReferralConstraint(
        Builder $query,
        array $filters,
        User $user,
        string $patientColumn,
        string $diagnosisColumn,
    ): void
    {
        $hospitalIds = $this->effectiveHospitalIds($filters, $user);
        $sourceHospitalIds = $this->effectiveSourceHospitalIds($filters, $user);
        $hasConstraint = $hospitalIds !== null
            || $sourceHospitalIds !== null
            || $filters['referral_status'] !== null
            || $filters['referral_type_id'] !== null;

        if (! $hasConstraint) {
            return;
        }

        $query->whereExists(function (Builder $exists) use ($filters, $hospitalIds, $sourceHospitalIds, $patientColumn, $diagnosisColumn): void {
            $exists->selectRaw('1')
                ->from('referrals as scoped_r')
                ->join('diagnosis_referral as scoped_dr', function ($join) use ($diagnosisColumn): void {
                    $join->on('scoped_dr.referral_id', '=', 'scoped_r.referral_id')
                        ->whereColumn('scoped_dr.diagnosis_id', $diagnosisColumn);
                })
                ->join('hospitals as scoped_h', 'scoped_h.hospital_id', '=', 'scoped_r.hospital_id')
                ->whereColumn('scoped_r.patient_id', $patientColumn)
                ->whereNull('scoped_r.deleted_at')
                ->whereNull('scoped_h.deleted_at')
                ->where('scoped_r.created_at', '>=', $this->startAt($filters))
                ->where('scoped_r.created_at', '<', $this->endExclusive($filters));

            if ($hospitalIds !== null) {
                if ($hospitalIds === []) {
                    $exists->whereRaw('1 = 0');
                } else {
                    $exists->whereIn('scoped_r.hospital_id', $hospitalIds);
                }
            }

            if ($sourceHospitalIds !== null) {
                if ($sourceHospitalIds === []) {
                    $exists->whereRaw('1 = 0');
                } else {
                    $this->applyCreatedByHospitalConstraint($exists, $sourceHospitalIds, 'scoped_r.created_by');
                }
            }

            if ($filters['referral_status'] !== null) {
                $exists->where('scoped_r.status', $filters['referral_status']);
            }

            if ($filters['referral_type_id'] !== null) {
                $exists->where('scoped_h.referral_type_id', $filters['referral_type_id']);
            }
        });
    }

    private function effectiveHospitalIds(array $filters, User $user): ?array
    {
        $selected = $filters['hospital_ids'];
        $allowed = $this->scope->hospitalIds($user);

        if ($allowed === null) {
            return $selected === [] ? null : $selected;
        }

        if ($selected === []) {
            return $allowed;
        }

        return array_values(array_intersect($selected, $allowed));
    }

    private function effectiveSourceHospitalIds(array $filters, User $user): ?array
    {
        $selected = $filters['source_hospital_ids'];
        $allowed = $this->scope->hospitalIds($user);

        if ($allowed === null) {
            return $selected === [] ? null : $selected;
        }

        if ($selected === []) {
            return $allowed;
        }

        return array_values(array_intersect($selected, $allowed));
    }

    private function applyCreatedByHospitalConstraint(Builder $query, array $hospitalIds, string $createdByColumn): void
    {
        $query->whereExists(function (Builder $source) use ($hospitalIds, $createdByColumn): void {
            $source->selectRaw('1')
                ->from('hospital_user as source_hospital_user')
                ->join('hospitals as source_hospital', 'source_hospital.hospital_id', '=', 'source_hospital_user.hospital_id')
                ->whereColumn('source_hospital_user.user_id', $createdByColumn)
                ->whereNull('source_hospital.deleted_at')
                ->whereIn('source_hospital_user.hospital_id', $hospitalIds);
        });
    }

    private function startAt(array $filters): string
    {
        return Carbon::parse($filters['start_date'])->startOfDay()->toDateTimeString();
    }

    private function endExclusive(array $filters): string
    {
        $end = Carbon::parse($filters['end_date'])->addDay()->startOfDay();

        return $end->greaterThan(now()) ? now()->toDateTimeString() : $end->toDateTimeString();
    }

    private function ageExpression(string $alias): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "CASE WHEN {$alias}.date_of_birth ~ '^\\d{4}-\\d{2}-\\d{2}$' THEN EXTRACT(YEAR FROM AGE(CURRENT_DATE, NULLIF({$alias}.date_of_birth, '')::date)) END";
        }

        return "TIMESTAMPDIFF(YEAR, STR_TO_DATE(NULLIF({$alias}.date_of_birth, ''), '%Y-%m-%d'), CURDATE())";
    }

    private function diagnosisAggregationSql(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "STRING_AGG(DISTINCT CONCAT_WS(' — ', d.diagnosis_code, d.diagnosis_name), '; ') AS diagnoses";
        }

        return "GROUP_CONCAT(DISTINCT CONCAT_WS(' — ', d.diagnosis_code, d.diagnosis_name) ORDER BY d.diagnosis_name SEPARATOR '; ') AS diagnoses";
    }

    private function hospitalAggregationSql(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "STRING_AGG(DISTINCT COALESCE(NULLIF(TRIM({$column}), ''), 'Not recorded'), '; ') AS destination_hospital";
        }

        return "GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM({$column}), ''), 'Not recorded') ORDER BY {$column} SEPARATOR '; ') AS destination_hospital";
    }

    private function statusAggregationSql(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "STRING_AGG(DISTINCT COALESCE(NULLIF(TRIM({$column}), ''), 'Not recorded'), '; ') AS referral_status";
        }

        return "GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM({$column}), ''), 'Not recorded') ORDER BY {$column} SEPARATOR '; ') AS referral_status";
    }

    private function resolveFilterLabels(array $filters, User $user): array
    {
        $hospitalIds = $this->effectiveHospitalIds($filters, $user) ?? [];
        $hospitalNames = $hospitalIds === []
            ? []
            : DB::table('hospitals')->whereIn('hospital_id', $hospitalIds)->orderBy('hospital_name')->pluck('hospital_name')->all();
        $sourceHospitalIds = $this->effectiveSourceHospitalIds($filters, $user) ?? [];
        $sourceHospitalNames = $sourceHospitalIds === []
            ? []
            : DB::table('hospitals')->whereIn('hospital_id', $sourceHospitalIds)->orderBy('hospital_name')->pluck('hospital_name')->all();

        $locationName = $filters['location_id'] === null
            ? null
            : DB::table('geographical_locations')->where('location_id', $filters['location_id'])->value('label');
        $diagnosisName = $filters['diagnosis_id'] === null
            ? null
            : DB::table('diagnoses')->where('diagnosis_id', $filters['diagnosis_id'])->value('diagnosis_name');
        $referralTypeName = $filters['referral_type_id'] === null
            ? null
            : DB::table('referral_types')->where('referral_type_id', $filters['referral_type_id'])->value('referral_type_name');

        return compact('hospitalNames', 'sourceHospitalNames', 'locationName', 'diagnosisName', 'referralTypeName');
    }

    private function displayFilters(array $filters, array $labels): array
    {
        $display = [
            'Reporting period' => $this->titleBuilder->periodLabel($filters),
        ];

        if (in_array($filters['report_type'], ['case_workflow', 'boarded_out_cases', 'patient_summary'], true)) {
            $display['Date basis'] = 'Case submission date';
            $display['Submitting hospital'] = empty($labels['sourceHospitalNames']) ? 'All authorized hospitals' : implode(', ', $labels['sourceHospitalNames']);
            $display['Case status'] = $filters['patient_history_status']
                ? ucwords(str_replace('_', ' ', $filters['patient_history_status'])) : 'All';
            $display['Archives'] = $filters['include_archived'] ? 'Included' : 'Excluded';
            if ($filters['patient_search']) $display['Patient search'] = $filters['patient_search'];
            return $display;
        }

        if ($filters['report_type'] === ReportDefinitionRegistry::TOP_DIAGNOSES) {
            $display['Top results'] = 'Top '.$filters['top'];
        }

        if (($filters['result_limit'] ?? null) !== null) {
            $display['Result limit'] = $filters['result_limit'] === 'all'
                ? 'All results'
                : 'Top '.$filters['result_limit'];
        }

        if (($filters['group_by'] ?? null) !== null) {
            $display['Group by'] = ucwords(str_replace('_', ' ', $filters['group_by']));
        }

        if (($filters['detail_level'] ?? null) !== null) {
            $display['Detail level'] = match ($filters['detail_level']) {
                'summary' => 'Summary only',
                'details' => 'Summary + patient details',
                default => 'Summary + breakdown',
            };
        }

        $display['Gender'] = $filters['gender'] === null ? 'All' : ucfirst($filters['gender']);
        $display['Age'] = $filters['age_group'] ?? (($filters['age_from'] ?? null) !== null || ($filters['age_to'] ?? null) !== null
            ? (($filters['age_from'] ?? 0).'–'.($filters['age_to'] ?? '150').' years')
            : 'All');
        $display['Location'] = $labels['locationName'] ?? 'All';
        $display['Diagnosis'] = $labels['diagnosisName'] ?? 'All';

        if ($filters['report_type'] === ReportDefinitionRegistry::TOP_DIAGNOSES || $filters['hospital_ids'] !== []) {
            $display['Destination hospital'] = $labels['hospitalNames'] === [] ? 'All' : implode(', ', $labels['hospitalNames']);
        }

        if ($filters['source_hospital_ids'] !== [] || in_array($filters['report_type'], [
            ReportDefinitionRegistry::TOP_DIAGNOSES,
            ReportDefinitionRegistry::REFERRALS_BY_HOSPITAL,
        ], true)) {
            $display['Source hospital'] = $labels['sourceHospitalNames'] === []
                ? 'All'
                : implode(', ', $labels['sourceHospitalNames']);
        }

        if ($filters['referral_status'] !== null || $filters['report_type'] === ReportDefinitionRegistry::REFERRALS_BY_HOSPITAL) {
            $display['Referral status'] = $filters['referral_status'] ?? 'All';
        }

        if ($filters['referral_type_id'] !== null || $filters['report_type'] === ReportDefinitionRegistry::REFERRALS_BY_HOSPITAL) {
            $display['Referral type'] = $labels['referralTypeName'] ?? 'All';
        }

        if ($filters['patient_history_status'] !== null || $filters['report_type'] === ReportDefinitionRegistry::TOP_DIAGNOSES) {
            $display['Patient history status'] = $filters['patient_history_status'] ?? 'All';
        }

        if ($filters['patient_search'] !== null) {
            $display['Patient search'] = $filters['patient_search'];
        }

        return $display;
    }

    private function userName(User $user): string
    {
        $name = trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name])));

        return $name !== '' ? $name : (string) $user->email;
    }
}
