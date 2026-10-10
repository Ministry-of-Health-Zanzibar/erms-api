<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\ReportDataScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SearchReferralReport
{
    public function __construct(private readonly ReportDataScope $scope) {}

    public function generate(array $filters, User $user): array
    {
        // One row per referral. Source is the submitting patient creator, not
        // the director/board member who happened to create the referral.
        $sourceNames = $this->sources('hospital_name');
        $sourceAddresses = $this->sources('hospital_address');
        $q = DB::table('referrals as r')->join('patients as p', 'p.patient_id', '=', 'r.patient_id')
            ->leftJoin('patient_histories as ph', function ($join): void {
                $join->on('ph.patient_histories_id', '=', 'r.patient_histories_id')->on('ph.patient_id', '=', 'r.patient_id');
            })
            ->leftJoin('referrals as parent', function ($join): void {
                $join->on('parent.referral_id', '=', 'r.parent_referral_id')->on('parent.patient_id', '=', 'r.patient_id')
                    ->on('parent.patient_histories_id', '=', 'r.patient_histories_id');
            })
            ->leftJoin('hospitals as previous_hospital', 'previous_hospital.hospital_id', '=', 'parent.hospital_id')
            ->leftJoin('hospitals as destination', 'destination.hospital_id', '=', 'r.hospital_id')
            ->leftJoin('reasons', 'reasons.reason_id', '=', 'r.reason_id')
            ->whereNull('r.deleted_at')->whereNull('p.deleted_at')
            ->select('r.referral_id', 'r.patient_histories_id', 'r.parent_referral_id', 'r.referral_number', 'r.created_at', 'r.status as referral_status',
                'p.patient_id', 'p.name as patient_name', 'p.matibabu_card', 'ph.patient_histories_id as verified_case_id',
                'previous_hospital.hospital_name as transfer_source_name', 'previous_hospital.hospital_address as transfer_source_address',
                'destination.hospital_id as to_hospital_id', 'destination.hospital_name as to_hospital_name', 'destination.hospital_address as to_hospital_address', 'reasons.referral_reason_name')
            ->selectSub($sourceNames, 'submitting_hospitals')->selectSub($sourceAddresses, 'submitting_addresses');
        $this->scope->applyPatientScope($q, $user, 'p');
        foreach (['insurance_provider_name'] as $field) $q->selectSub(DB::table('insurances')->whereColumn('patient_id', 'p.patient_id')->whereNull('deleted_at')->orderByDesc('insurance_id')->select($field)->limit(1), $field);
        foreach (['start_date', 'end_date'] as $field) $q->selectSub(DB::table('referral_letters')->whereColumn('referral_id', 'r.referral_id')->whereNull('deleted_at')->orderByDesc('referral_letter_id')->select($field)->limit(1), $field);
        foreach (['patient_name' => 'p.name', 'to_hospital_name' => 'destination.hospital_name', 'to_hospital_address' => 'destination.hospital_address', 'referral_reason_name' => 'reasons.referral_reason_name'] as $filter => $column) {
            if (! empty($filters[$filter])) $q->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($filters[$filter]).'%']);
        }
        // Keep older clients' hospital_name as a destination filter.
        if (! empty($filters['hospital_name']) && empty($filters['to_hospital_name'])) $q->whereRaw('LOWER(destination.hospital_name) LIKE ?', ['%'.mb_strtolower($filters['hospital_name']).'%']);
        foreach (['from_hospital_name' => 'hospital_name', 'from_hospital_address' => 'hospital_address'] as $filter => $field) {
            if (empty($filters[$filter])) continue;
            $term = '%'.mb_strtolower($filters[$filter]).'%';
            $q->where(function (Builder $source) use ($field, $term): void {
                $source->whereRaw('LOWER(previous_hospital.'.$field.') LIKE ?', [$term])
                    ->orWhere(function (Builder $original) use ($field, $term): void {
                        $original->whereNull('r.parent_referral_id')->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('hospital_user as hu')
                            ->join('hospitals as h', 'h.hospital_id', '=', 'hu.hospital_id')->whereColumn('hu.user_id', 'p.created_by')->whereRaw('LOWER(h.'.$field.') LIKE ?', [$term]));
                    });
            });
        }
        if (! empty($filters['start_date']) || ! empty($filters['end_date'])) {
            $q->whereExists(function (Builder $letter) use ($filters): void {
                $letter->selectRaw('1')->from('referral_letters as period_letter')->whereColumn('period_letter.referral_id', 'r.referral_id')->whereNull('period_letter.deleted_at');
                if (! empty($filters['start_date'])) $letter->where('period_letter.start_date', '>=', $filters['start_date']);
                if (! empty($filters['end_date'])) $letter->where('period_letter.start_date', '<=', $filters['end_date']);
            });
        }
        $rows = $q->orderByDesc('r.created_at')->orderByDesc('r.referral_id')->get();
        // Batch diagnoses for exact case IDs: never merge all histories of a patient.
        $diagnoses = DB::table('history_diagnosis as hd')->join('diagnoses as d', 'd.diagnosis_id', '=', 'hd.diagnosis_id')
            ->whereIn('hd.patient_histories_id', $rows->pluck('verified_case_id')->filter()->unique()->all())->where('hd.added_by', 'medical_board')
            ->whereNull('d.deleted_at')->select('hd.patient_histories_id', 'd.diagnosis_id', 'd.diagnosis_code', 'd.diagnosis_name')->distinct()->get()->groupBy('patient_histories_id');
        return $rows->map(static function ($row) use ($diagnoses): object {
            $row->from_hospital_name = $row->parent_referral_id ? ($row->transfer_source_name ?: 'Transfer source needs review') : ($row->submitting_hospitals ?: 'Not recorded');
            $row->from_hospital_address = $row->parent_referral_id ? $row->transfer_source_address : $row->submitting_addresses;
            $row->board_diagnoses = $diagnoses->get($row->verified_case_id, collect())->map(static fn ($d) => ['diagnosis_id' => $d->diagnosis_id, 'diagnosis_code' => $d->diagnosis_code, 'diagnosis_name' => $d->diagnosis_name])->values()->all();
            $row->record_warning = $row->verified_case_id ? null : 'Case link needs review; submitting hospital follows patient registration';
            unset($row->submitting_hospitals, $row->submitting_addresses, $row->transfer_source_name, $row->transfer_source_address);
            return $row;
        })->all();
    }

    private function sources(string $field): Builder
    {
        $names = DB::table('hospital_user as hu')->join('hospitals as h', 'h.hospital_id', '=', 'hu.hospital_id')
            ->whereColumn('hu.user_id', 'p.created_by')->select('h.'.$field.' as value')->distinct()->orderBy('h.'.$field);
        $aggregate = match (DB::getDriverName()) {
            'pgsql' => "STRING_AGG(source_names.value, '; ' ORDER BY source_names.value)",
            'sqlite' => "GROUP_CONCAT(source_names.value, '; ')",
            default => "GROUP_CONCAT(source_names.value ORDER BY source_names.value SEPARATOR '; ')",
        };
        return DB::query()->fromSub($names, 'source_names')->selectRaw($aggregate);
    }
}
