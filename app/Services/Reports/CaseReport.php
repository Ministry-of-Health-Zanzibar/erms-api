<?php

namespace App\Services\Reports;

use App\Models\PatientHistory;
use App\Models\User;
use App\Support\Pagination;
use App\Support\ReportDataScope;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CaseReport
{
    public const OPEN_STATUSES = ['pending', 'reviewed', 'assigned', 'requested', 'approved'];

    public function __construct(private readonly ReportDataScope $scope) {}

    public function query(array $filters, User $user): Builder
    {
        $query = DB::table('patient_histories as ph')
            ->join('patients as p', 'p.patient_id', '=', 'ph.patient_id');

        if (! ($filters['include_archived'] ?? false)) {
            $query->whereNull('ph.deleted_at')->whereNull('p.deleted_at');
        }

        $this->scope->applyPatientScope($query, $user, 'p');

        if (! empty($filters['start_date'])) {
            $query->where('ph.created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }
        if (! empty($filters['end_date'])) {
            $query->where('ph.created_at', '<', Carbon::parse($filters['end_date'])->addDay()->startOfDay());
        }
        $status = $filters['patient_history_status'] ?? null;
        if ($status === 'under_review') {
            $query->whereIn('ph.status', self::OPEN_STATUSES);
        } elseif ($status !== null) {
            $query->where('ph.status', $status);
        }

        if (! empty($filters['source_hospital_ids'])) {
            $query->whereExists(function (Builder $source) use ($filters): void {
                $source->selectRaw('1')->from('hospital_user as case_source')
                    ->whereColumn('case_source.user_id', 'p.created_by')
                    ->whereIn('case_source.hospital_id', $filters['source_hospital_ids']);
            });
        }
        if (! empty($filters['patient_search'])) {
            $term = '%'.mb_strtolower($filters['patient_search']).'%';
            $query->where(function (Builder $search) use ($term): void {
                $search->whereRaw('LOWER(p.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(p.matibabu_card) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(p.zan_id) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(p.phone) LIKE ?', [$term]);
            });
        }

        return $query;
    }

    public function summary(array $filters, User $user): array
    {
        $counts = $this->query($filters, $user)->selectRaw('ph.status, COUNT(*) as total')
            ->groupBy('ph.status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $statuses = collect(PatientHistory::STATUS_MAP)->map(function (array $tracking, string $status) use ($counts, $total): array {
            $count = (int) ($counts[$status] ?? 0);
            return [
                'status' => $status,
                ...$tracking,
                // Existing individual workflow panels retain their stage progress.
                'progress_percentage' => (int) round(($tracking['stage'] / 6) * 100),
                'case_percentage' => $total > 0 ? round($count / $total * 100, 2) : 0,
                'count' => $count,
            ];
        })->values()->all();

        return [
            'total' => $total,
            'under_review' => (int) $counts->only(self::OPEN_STATUSES)->sum(),
            'confirmed' => (int) ($counts['confirmed'] ?? 0),
            'boarded_out' => (int) ($counts['boarded_out'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'statuses' => $statuses,
        ];
    }

    public function generate(array $filters, User $user, bool $paginate): array
    {
        $query = $this->query($filters, $user);
        $type = $filters['report_type'];
        if ($type === 'boarded_out_cases') {
            $query->where('ph.status', 'boarded_out');
        }
        if ($type === 'patient_summary') {
            // Choose the latest eligible case in the period, not the newest case
            // belonging to a different period or archive scope.
            $latest = $this->query($filters, $user)->selectRaw('MAX(ph.patient_histories_id)')->groupBy('ph.patient_id');
            $query->whereIn('ph.patient_histories_id', $latest);
        }

        $counts = (clone $query)->selectRaw('ph.status, COUNT(*) as total')->groupBy('ph.status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $hospitalNames = DB::table('referrals as r')
            ->join('hospitals as destination', 'destination.hospital_id', '=', 'r.hospital_id')
            ->whereColumn('r.patient_histories_id', 'ph.patient_histories_id')
            ->whereColumn('r.patient_id', 'ph.patient_id')
            ->whereNull('r.deleted_at')
            ->select('destination.hospital_name')->distinct()->orderBy('destination.hospital_name');
        $rowsQuery = $query->select([
            'ph.patient_histories_id as case_id', 'p.patient_id', 'p.matibabu_card', 'p.name as patient',
            'ph.created_at as submitted_at', 'ph.status', 'ph.case_type',
            'ph.deleted_at as case_archived_at', 'p.deleted_at as patient_archived_at',
        ])->selectSub(
            DB::table('hospital_user as hu')->join('hospitals as h', 'h.hospital_id', '=', 'hu.hospital_id')
                ->whereColumn('hu.user_id', 'p.created_by')->orderBy('hu.hospital_id')->select('h.hospital_name')->limit(1),
            'source_hospital',
        )->selectSub(
            DB::query()->fromSub($hospitalNames, 'case_destinations')->selectRaw($this->hospitalNamesAggregationSql()),
            'referred_hospitals',
        )->orderByDesc('ph.patient_histories_id');

        $columns = [
            ['key' => 'matibabu_card', 'label' => 'Matibabu card', 'type' => 'text'],
            ['key' => 'patient', 'label' => 'Patient', 'type' => 'text'],
            ['key' => 'source_hospital', 'label' => 'Submitting hospital', 'type' => 'text'],
            ['key' => 'submitted_at', 'label' => 'Case submission date', 'type' => 'date'],
            ['key' => 'status_label', 'label' => 'Case status', 'type' => 'text'],
            ['key' => 'referred_hospitals', 'label' => 'Referred hospital', 'type' => 'text'],
            ['key' => 'archive', 'label' => 'Record', 'type' => 'text'],
        ];

        if ($type === 'boarded_out_cases') {
            $letter = DB::table('boarded_out_letters as bo')
                ->whereColumn('bo.patient_histories_id', 'ph.patient_histories_id')
                ->whereNull('bo.deleted_at')->orderByDesc('bo.id');
            $rowsQuery->selectSub((clone $letter)->select('bo.reference_number')->limit(1), 'letter_reference')
                ->selectSub((clone $letter)->select('bo.receiver')->limit(1), 'receiver')
                ->selectSub((clone $letter)->leftJoin('referrals as linked_ref', 'linked_ref.referral_id', '=', 'bo.referral_id')
                    ->leftJoin('hospitals as destination', 'destination.hospital_id', '=', 'linked_ref.hospital_id')
                    ->select('destination.hospital_name')->limit(1), 'linked_hospital');
            foreach (['letter_reference' => 'Letter reference', 'receiver' => 'Receiver', 'linked_hospital' => 'Linked hospital'] as $key => $label) {
                $columns[] = ['key' => $key, 'label' => $label, 'type' => 'text'];
            }
        }

        $pagination = ['current_page' => 1, 'per_page' => $total, 'from' => $total ? 1 : null,
            'to' => $total ?: null, 'total' => $total, 'last_page' => 1, 'has_more_pages' => false];
        if ($paginate) {
            $page = $rowsQuery->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
            $rows = collect($page->items());
            $pagination = Pagination::meta($page);
        } else {
            $rows = $rowsQuery->get();
        }
        $rows = $rows->map(function ($row): array {
            $row = (array) $row;
            $row['status_label'] = PatientHistory::STATUS_MAP[$row['status']]['label'] ?? $row['status'];
            $row['archive'] = $row['case_archived_at'] || $row['patient_archived_at'] ? 'Archived' : 'Active';
            return $row;
        })->all();

        $notes = [
            'Dates filter the medical history submission date. Statuses show the current decision for those cases.',
            $type === 'patient_summary' ? 'One patient is counted once, using their latest eligible case in the selected period.' : 'One medical history ID is counted once. Several hospital referrals do not create additional cases.',
            'Individual workflow progress is separate from the percentage of cases in each stage.',
            'The source hospital follows the patient creator\'s hospital assignment in the existing records.',
            'Referred hospitals list the distinct destinations linked to this exact case.',
            'Exports include every matching row, not only the preview page.',
        ];

        return [
            'summary' => ['total_records' => $total, 'under_review' => (int) $counts->only(self::OPEN_STATUSES)->sum(),
                'confirmed' => (int) ($counts['confirmed'] ?? 0), 'boarded_out' => (int) ($counts['boarded_out'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0)],
            'columns' => $columns, 'rows' => $rows, 'pagination' => $pagination, 'notes' => $notes,
            'data_quality_notes' => ['Legacy referrals without a verified case link are not attributed to another case.'],
        ];
    }

    private function hospitalNamesAggregationSql(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "STRING_AGG(case_destinations.hospital_name, '; ' ORDER BY case_destinations.hospital_name)",
            'sqlite' => "GROUP_CONCAT(case_destinations.hospital_name, '; ')",
            default => "GROUP_CONCAT(case_destinations.hospital_name ORDER BY case_destinations.hospital_name SEPARATOR '; ')",
        };
    }
}
