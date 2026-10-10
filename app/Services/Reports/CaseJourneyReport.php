<?php

namespace App\Services\Reports;

use App\Models\PatientHistory;
use App\Models\User;
use App\Support\Pagination;
use App\Support\ReportDataScope;
use App\Support\SuperAdminAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only: a verified medical-history link is required for every movement. */
final class CaseJourneyReport
{
    public function __construct(private readonly ReportDataScope $scope) {}

    public function generate(array $filters, User $user, bool $paginate): array
    {
        $cases = $this->cases($filters, $user);
        $total = (clone $cases)->count();
        $selectedIds = (clone $cases)->select('ph.patient_histories_id');
        $visits = $this->visits($filters)->whereIn('r.patient_histories_id', $selectedIds);
        $periodVisits = (clone $visits)->whereNotNull('fu.followup_id')->whereBetween(DB::raw($this->visitDate()), [$filters['start_date'], $filters['end_date']]);
        $outcomes = (clone $periodVisits)->select('hl.outcome')->selectRaw('COUNT(DISTINCT hl.letter_id) as total')
            ->groupBy('hl.outcome')->pluck('total', 'hl.outcome')->all();
        $referralCount = $this->referrals($filters)->whereIn('r.patient_histories_id', clone $selectedIds)->count();
        $transferCount = $this->referrals($filters)->whereIn('r.patient_histories_id', clone $selectedIds)
            ->whereNotNull('r.parent_referral_id')->whereBetween(DB::raw($this->dateSql('r.created_at')), [$filters['start_date'], $filters['end_date']])
            ->whereExists($this->verifiedParent())->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('hospital_letters as transfer_evidence')
                ->whereColumn('transfer_evidence.referral_id', 'r.parent_referral_id')->whereColumn('transfer_evidence.transferred_referral_id', 'r.referral_id')
                ->where('transfer_evidence.outcome', 'Transferred')->whereNull('transfer_evidence.deleted_at'))->count();
        $summary = ['cases' => $total, 'hospital_referrals' => $referralCount,
            'follow_up_visits_in_period' => array_sum($outcomes), 'transfers_in_period' => $transferCount,
            'finished_visits_in_period' => (int) ($outcomes['Finished'] ?? 0), 'death_visits_in_period' => (int) ($outcomes['Death'] ?? 0)];

        $query = $cases->select('ph.patient_histories_id', 'ph.patient_id', 'ph.status', 'ph.created_at', 'ph.deleted_at',
            'p.name as patient', 'p.matibabu_card', 'p.created_by as patient_creator', 'p.deleted_at as patient_archived_at')
            ->orderByDesc('ph.patient_histories_id');
        if ($paginate) {
            $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
            $records = collect($page->items());
            $pagination = Pagination::meta($page);
        } else {
            $records = $query->get();
            $pagination = ['current_page' => 1, 'per_page' => $total, 'from' => $total ? 1 : null, 'to' => $total ?: null,
                'total' => $total, 'last_page' => 1, 'has_more_pages' => false];
        }
        $rows = []; $movements = []; $hospitalRows = []; $quality = [];
        // Bound relation hydration even for an all-pages export.
        foreach ($records->chunk(200) as $batch) {
            [$batchRows, $batchMovements, $batchHospitals, $batchQuality] = $this->hydrate($batch, $filters, $user);
            array_push($rows, ...$batchRows); array_push($movements, ...$batchMovements);
            array_push($hospitalRows, ...$batchHospitals); array_push($quality, ...$batchQuality);
        }
        usort($movements, fn ($a, $b) => [$a['occurred_at'] ?: '9999', $a['recorded_at'] ?: '', $a['event_key']] <=> [$b['occurred_at'] ?: '9999', $b['recorded_at'] ?: '', $b['event_key']]);
        $columns = $this->columns(['patient' => 'Patient', 'matibabu_card' => 'Matibabu card', 'submitted_at' => 'Case submission date', 'source_hospital' => 'Submitting hospital',
            'destination_hospitals' => 'Destination hospitals', 'referral_numbers' => 'Referral numbers', 'status_label' => 'Approval status',
            'latest_outcomes' => 'Latest outcome at each hospital', 'followup_count' => 'Recorded visits', 'last_activity' => 'Last recorded activity', 'record_state' => 'Record']);
        $movementColumns = $this->columns(['patient' => 'Patient', 'matibabu_card' => 'Matibabu card', 'occurred_at' => 'Activity date',
            'activity' => 'Activity', 'source_hospital' => 'Source hospital', 'destination_hospital' => 'Destination hospital',
            'outcome' => 'Outcome / status', 'notes' => 'Details', 'actor' => 'Recorded by', 'record_state' => 'Record', 'period_context' => 'Period']);
        $notes = [
            'Approval status and hospital follow-up outcome are different. Confirmed does not mean treatment is finished.',
            'Finished and Death use the recorded follow-up outcome, not the referral status Closed.',
            'Cases are counted once. Visit totals count distinct follow-up letters in the selected activity period, not edits or duplicated follow-up rows.',
            'Latest outcomes show the latest active visit for each individual hospital referral, ordered by visit date. Different hospital outcomes are not combined into one case outcome.',
            'Destination and referral-number filters select cases. Case summaries and complete journeys retain all verified referrals for those cases; period visit totals use the selected destination hospitals.',
            'View journey shows the complete case record, including context outside the selected period. Summary exports include movements in the period.',
            'Historical actors, decision dates and earlier values are shown only when recorded. Missing information is not guessed.',
            'Submitting hospital follows the patient creator’s current hospital assignments; multiple assignments are listed, not reduced to one hospital.',
            'Unlinked or wrong-patient referrals are not assigned to a case. Archived movements appear only when archives are included.',
            'Clinical notes, financial records and print history require their existing permissions. Exports use the same permissions and include all matching cases.',
        ];
        $sections = [
            ['key' => 'overview', 'title' => 'How to read this report', 'kind' => 'list', 'items' => $notes],
            ['key' => 'case_summary', 'title' => 'Case summary', 'kind' => 'table', 'columns' => $columns, 'rows' => $rows],
        ];
        if (! $paginate || $filters['case_id']) {
            $sections[] = ['key' => 'hospital_outcomes', 'title' => 'Latest recorded outcome by hospital referral', 'kind' => 'table',
                'columns' => $this->columns(['patient' => 'Patient', 'referral_number' => 'Referral number', 'source_hospital' => 'Source hospital',
                    'destination_hospital' => 'Destination hospital', 'referral_status' => 'Referral status', 'outcome' => 'Latest active follow-up outcome', 'visit_date' => 'Visit date', 'record_state' => 'Record']), 'rows' => $hospitalRows];
            $sections[] = ['key' => 'movements', 'title' => 'Case journey and recorded movements', 'kind' => 'table', 'columns' => $movementColumns, 'rows' => $movements];
        }
        $quality = array_values(array_unique($quality));
        $sections[] = ['key' => 'data_quality', 'title' => 'Record checks', 'kind' => 'list', 'items' => $quality ?: ['No link warnings found in the displayed verified records. This does not prove historical records are complete.']];
        return compact('summary', 'columns', 'rows', 'pagination', 'sections', 'notes') + ['data_quality_notes' => $quality];
    }

    private function cases(array $f, User $user): Builder
    {
        $query = DB::table('patient_histories as ph')->join('patients as p', 'p.patient_id', '=', 'ph.patient_id');
        if (! $f['include_archived']) $query->whereNull('ph.deleted_at')->whereNull('p.deleted_at');
        $this->scope->applyPatientScope($query, $user, 'p');
        if ($f['case_id']) $query->where('ph.patient_histories_id', $f['case_id']);
        if ($f['patient_history_status'] === 'under_review') $query->whereIn('ph.status', CaseReport::OPEN_STATUSES);
        elseif ($f['patient_history_status']) $query->where('ph.status', $f['patient_history_status']);
        if ($f['patient_search']) {
            $term = '%'.mb_strtolower($f['patient_search']).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(p.name) LIKE ?', [$term])->orWhereRaw('LOWER(p.matibabu_card) LIKE ?', [$term]));
        }
        if ($f['source_hospital_ids']) $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('hospital_user as source')
            ->whereColumn('source.user_id', 'p.created_by')->whereIn('source.hospital_id', $f['source_hospital_ids']));
        if ($f['hospital_ids'] || $f['referral_search']) {
            $r = $this->referrals($f)->whereColumn('r.patient_histories_id', 'ph.patient_histories_id')->whereColumn('r.patient_id', 'ph.patient_id');
            if ($f['referral_search']) $r->whereRaw('LOWER(r.referral_number) LIKE ?', ['%'.mb_strtolower($f['referral_search']).'%']);
            $query->whereExists($r->selectRaw('1'));
        }
        if ($f['outcome']) {
            $v = $this->visits($f)->whereColumn('r.patient_histories_id', 'ph.patient_histories_id')->whereColumn('r.patient_id', 'ph.patient_id')
                ->whereNotNull('fu.followup_id')->where('hl.outcome', $f['outcome'])->whereBetween(DB::raw($this->visitDate()), [$f['start_date'], $f['end_date']]);
            $query->whereExists($v->selectRaw('1'));
        } else {
            $activity = $this->activityIndex($f, $user);
            $query->whereIn('ph.patient_histories_id', DB::query()->fromSub($activity, 'activity')->select('case_id')
                ->whereBetween('activity_date', [$f['start_date'], $f['end_date']]));
        }
        return $query;
    }

    private function referrals(array $f): Builder
    {
        $r = DB::table('referrals as r')->join('patient_histories as verified_case', 'verified_case.patient_histories_id', '=', 'r.patient_histories_id')
            ->whereColumn('verified_case.patient_id', 'r.patient_id');
        if (! $f['include_archived']) $r->whereNull('r.deleted_at');
        if ($f['hospital_ids']) $r->whereIn('r.hospital_id', $f['hospital_ids']);
        return $r;
    }

    private function visits(array $f): Builder
    {
        $q = $this->referrals($f)->join('hospital_letters as hl', 'hl.referral_id', '=', 'r.referral_id')
            ->leftJoin('followups as fu', function ($j) use ($f): void {
                $j->on('fu.letter_id', '=', 'hl.letter_id')->on('fu.patient_id', '=', 'r.patient_id');
                if (! $f['include_archived']) $j->whereNull('fu.deleted_at');
            });
        if (! $f['include_archived']) $q->whereNull('hl.deleted_at');
        return $q;
    }

    private function visitDate(): string
    {
        // followup_date is a legacy string field. Compare ISO day strings without
        // casting malformed historical values to PostgreSQL dates.
        $day = 'SUBSTR(fu.followup_date, 1, 10)';
        $pattern = match (DB::getDriverName()) {
            'pgsql' => "$day ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'",
            'sqlite' => "$day GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'",
            default => "$day REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'",
        };
        $fallback = $this->dateSql('hl.created_at');
        $yearType = DB::getDriverName() === 'mysql' ? 'SIGNED' : 'INTEGER';
        $year = "CAST(SUBSTR(fu.followup_date, 1, 4) AS $yearType)";
        $lastDay = "CASE WHEN SUBSTR(fu.followup_date, 6, 2) IN ('04', '06', '09', '11') THEN '30' WHEN SUBSTR(fu.followup_date, 6, 2) = '02' THEN CASE WHEN ($year % 400 = 0 OR ($year % 4 = 0 AND $year % 100 != 0)) THEN '29' ELSE '28' END ELSE '31' END";
        return "CASE WHEN $pattern THEN CASE WHEN SUBSTR(fu.followup_date, 1, 4) != '0000' AND SUBSTR(fu.followup_date, 6, 2) BETWEEN '01' AND '12' AND SUBSTR(fu.followup_date, 9, 2) BETWEEN '01' AND $lastDay THEN $day ELSE $fallback END ELSE $fallback END";
    }

    private function dateSql(string $field): string
    {
        $type = DB::getDriverName() === 'mysql' ? 'CHAR' : 'VARCHAR';
        return "SUBSTR(CAST($field AS $type), 1, 10)";
    }

    private function verifiedParent(): \Closure
    {
        return static fn (Builder $q) => $q->selectRaw('1')->from('referrals as parent')
            ->whereColumn('parent.referral_id', 'r.parent_referral_id')->whereColumn('parent.referral_id', '!=', 'r.referral_id')->whereColumn('parent.patient_id', 'r.patient_id')
            ->whereColumn('parent.patient_histories_id', 'r.patient_histories_id');
    }

    private function activityIndex(array $f, User $user): Builder
    {
        $index = DB::table('patient_histories')->selectRaw('patient_histories_id as case_id, '.$this->dateSql('created_at').' as activity_date');
        $index->unionAll($this->referrals($f)->selectRaw('r.patient_histories_id as case_id, '.$this->dateSql('r.created_at').' as activity_date'));
        $index->unionAll($this->visits($f)->selectRaw('r.patient_histories_id as case_id, '.$this->visitDate().' as activity_date'));
        foreach (['patient_history_workflow_events' => 'created_at', 'boarded_out_letters' => 'created_at', 'case_journey_events' => 'occurred_at'] as $table => $date) {
            if (! Schema::hasTable($table)) continue;
            $q = DB::table($table)->selectRaw('patient_histories_id as case_id, '.$this->dateSql($date).' as activity_date');
            if ($table === 'case_journey_events') {
                $q = DB::table('case_journey_events as journal')->join('patient_histories as journal_case', 'journal_case.patient_histories_id', '=', 'journal.patient_histories_id')
                    ->whereColumn('journal_case.patient_id', 'journal.patient_id')->selectRaw('journal.patient_histories_id as case_id, '.$this->dateSql('journal.occurred_at').' as activity_date');
            }
            if ($table === 'boarded_out_letters' && ! $f['include_archived']) $q->whereNull('deleted_at');
            $index->unionAll($q);
            if ($table === 'patient_history_workflow_events') $index->unionAll(DB::table($table)->whereNotNull('undone_at')
                ->selectRaw('patient_histories_id as case_id, '.$this->dateSql('undone_at').' as activity_date'));
        }
        foreach ($this->supportingTables($user) as $table => $definition) {
            if (! Schema::hasTable($table)) continue;
            $q = $this->referrals($f)->join($table.' as support', 'support.referral_id', '=', 'r.referral_id');
            if (! $f['include_archived'] && Schema::hasColumn($table, 'deleted_at')) $q->whereNull('support.deleted_at');
            $index->unionAll($q->selectRaw('r.patient_histories_id as case_id, '.$this->dateSql('support.created_at').' as activity_date'));
        }
        if ($this->printHistoryAllowed($user) && Schema::hasTable('letter_print_events')) {
            foreach (['referral' => ['referral_letters', 'referral_letter_id'], 'follow_up' => ['hospital_letters', 'letter_id']] as $type => [$table, $id]) {
                if (! Schema::hasTable($table)) continue;
                $q = $this->referrals($f)->join($table.' as printed_letter', 'printed_letter.referral_id', '=', 'r.referral_id')
                    ->join('letter_print_events as print', 'print.letter_id', '=', 'printed_letter.'.$id)->where('print.letter_type', $type);
                if (! $f['include_archived']) $q->whereNull('printed_letter.deleted_at');
                $index->unionAll($q->selectRaw('r.patient_histories_id as case_id, '.$this->dateSql('print.printed_at').' as activity_date'));
            }
            $q = DB::table('boarded_out_letters as bo')->join('letter_print_events as print', 'print.letter_id', '=', 'bo.id')->where('print.letter_type', 'boarded_out');
            if (! $f['include_archived']) $q->whereNull('bo.deleted_at');
            $index->unionAll($q->selectRaw('bo.patient_histories_id as case_id, '.$this->dateSql('print.printed_at').' as activity_date'));
        }
        if (SuperAdminAccess::allowed($user, 'View Bill') && SuperAdminAccess::allowed($user, 'View Payment') && Schema::hasTable('bills') && Schema::hasTable('bill_payments')) {
            $q = $this->referrals($f)->join('bills as b', 'b.referral_id', '=', 'r.referral_id')->join('bill_payments as allocation', 'allocation.bill_id', '=', 'b.bill_id');
            if (! $f['include_archived']) $q->whereNull('b.deleted_at')->whereNull('allocation.deleted_at');
            $index->unionAll($q->selectRaw('r.patient_histories_id as case_id, '.$this->dateSql('allocation.allocation_date').' as activity_date'));
        }
        // Corrections and archived records are real dated activities, but never
        // treated as another visit or as a previous unrecorded clinical outcome.
        $index->unionAll($this->visits($f)->whereColumn('hl.updated_at', '!=', 'hl.created_at')
            ->selectRaw('r.patient_histories_id as case_id, '.$this->dateSql('hl.updated_at').' as activity_date'));
        return $index;
    }

    private function hydrate(Collection $cases, array $f, User $user): array
    {
        $ids = $cases->pluck('patient_histories_id')->all();
        $contextFilters = $f; $contextFilters['hospital_ids'] = [];
        $refs = $this->referrals($contextFilters)->whereIn('r.patient_histories_id', $ids)->select('r.*')->get();
        $refIds = $refs->pluck('referral_id')->all(); $refById = $refs->keyBy('referral_id');
        $hospitals = DB::table('hospitals')->pluck('hospital_name', 'hospital_id');
        $sources = DB::table('hospital_user')->whereIn('user_id', $cases->pluck('patient_creator')->all())
            ->join('hospitals', 'hospitals.hospital_id', '=', 'hospital_user.hospital_id')->select('user_id', 'hospital_name')->distinct()->get()->groupBy('user_id');
        $visits = $this->visits($contextFilters)->whereIn('r.referral_id', $refIds)
            ->select('hl.*', 'fu.followup_id', 'fu.followup_date', 'fu.notes as followup_notes', 'fu.deleted_at as visit_archived_at')->get();
        $events = Schema::hasTable('patient_history_workflow_events') ? DB::table('patient_history_workflow_events')->whereIn('patient_histories_id', $ids)->get() : collect();
        $journal = Schema::hasTable('case_journey_events') ? DB::table('case_journey_events')->whereIn('patient_histories_id', $ids)->get() : collect();
        $support = $this->supportingRecords($ids, $refIds, $f, $user);
        $actorIds = $events->pluck('actor_id')->merge($events->pluck('undone_by'))->merge($journal->pluck('actor_id'));
        foreach ($support as $records) $actorIds = $actorIds->merge($records->pluck('created_by'))->merge($records->pluck('printed_by'));
        $users = Schema::hasTable('users') ? DB::table('users')->whereIn('id', $actorIds->filter()->unique()->all())
            ->select('id', 'first_name', 'middle_name', 'last_name')->get()->keyBy('id') : collect();
        $canNotes = SuperAdminAccess::allowed($user, 'View FollowUp') || SuperAdminAccess::allowed($user, 'View Hospital Letter');
        $rows = []; $timeline = []; $hospitalRows = []; $quality = [];
        foreach ($cases as $case) {
            $caseId = (int) $case->patient_histories_id;
            $caseRefs = $refs->where('patient_histories_id', $caseId);
            $source = $sources->get($case->patient_creator, collect())->pluck('hospital_name')->unique()->sort()->implode('; ') ?: 'Not recorded';
            $base = ['case_id' => $caseId, 'patient' => $case->patient, 'matibabu_card' => $case->matibabu_card ?: 'Not recorded'];
            $caseTimeline = [];
            $add = function (string $key, ?string $date, string $activity, array $extra = []) use (&$caseTimeline, $base, $source, $f): void {
                $date = $this->safeDate($date);
                $caseTimeline[$key] = $base + ['event_key' => $key, 'occurred_at' => $date, 'activity' => $activity] + $extra + [
                    'source_hospital' => $source, 'destination_hospital' => '', 'outcome' => '', 'notes' => '', 'actor' => 'Not recorded',
                    'recorded_at' => $date, 'record_state' => 'Recorded',
                ];
                $caseTimeline[$key]['period_context'] = ! $date ? 'Date not recorded' : (substr($date, 0, 10) >= $f['start_date'] && substr($date, 0, 10) <= $f['end_date'] ? 'In period' : 'Earlier / later context');
            };
            $add('case-'.$caseId, $case->created_at, 'Case submitted', ['record_state' => $case->deleted_at || $case->patient_archived_at ? 'Archived' : 'Active']);
            $caseEvents = $events->where('patient_histories_id', $caseId);
            foreach ($caseEvents as $event) {
                $metadata = json_decode($event->metadata ?? '{}', true) ?: [];
                $clinical = [];
                if (SuperAdminAccess::allowed($user, 'View Patient History')) {
                    foreach (['board_comments', 'mkurugenzi_tiba_comments', 'dg_comments'] as $field) {
                        $value = $metadata['after']['patient_history'][$field] ?? null;
                        $before = $metadata['before']['patient_history'][$field] ?? null;
                        if ($value && $value !== $before) $clinical[] = ucwords(str_replace('_', ' ', $field)).': '.$value;
                    }
                }
                $add('workflow-'.$event->id, $event->created_at, ucfirst(str_replace('_', ' ', $event->action)), [
                    'outcome' => PatientHistory::labelForStatus($event->to_status), 'actor' => $this->actor($users, $event->actor_id),
                    'notes' => 'From: '.PatientHistory::labelForStatus($event->from_status).'. To: '.PatientHistory::labelForStatus($event->to_status).($clinical ? ' | '.implode(' | ', $clinical) : ''),
                    'record_state' => $event->undone_at ? 'Undone — not the current decision' : 'Recorded',
                ]);
                if ($event->undone_at) $add('undo-'.$event->id, $event->undone_at, 'Workflow action undone', [
                    'actor' => $this->actor($users, $event->undone_by), 'notes' => $canNotes ? $event->undo_reason : 'Reason restricted by permissions']);
            }
            if (! $caseEvents->whereNull('undone_at')->contains('to_status', $case->status)) {
                $add('current-'.$caseId, null, 'Current approval status', ['outcome' => PatientHistory::labelForStatus($case->status),
                    'notes' => 'Decision date and decision maker were not recorded in the workflow history.']);
            }
            $latestLabels = []; $allVisitIds = [];
            foreach ($caseRefs as $ref) {
                $hospital = $hospitals[$ref->hospital_id] ?? 'Not recorded';
                $parent = $ref->parent_referral_id ? $refById->get($ref->parent_referral_id) : null;
                $verified = $parent && (int) $parent->referral_id !== (int) $ref->referral_id && (int) $parent->patient_id === (int) $ref->patient_id && (int) $parent->patient_histories_id === (int) $ref->patient_histories_id;
                $from = $verified ? ($hospitals[$parent->hospital_id] ?? 'Not recorded') : $source;
                if ($ref->parent_referral_id && ! $verified) {
                    $from = 'Transfer source needs review';
                    $quality[] = 'Case '.$caseId.': the parent link for referral '.$ref->referral_id.' is missing, outside the selected hospital scope, or belongs to another case. No transfer source was guessed.';
                }
                $add('referral-'.$ref->referral_id, $ref->created_at, $ref->parent_referral_id ? 'Transfer referral created' : 'Hospital referral created', [
                    'source_hospital' => $from, 'destination_hospital' => $hospital, 'outcome' => 'Referral created',
                    'notes' => $ref->referral_number.'; current referral status: '.$ref->status,
                    'record_state' => $ref->deleted_at ? 'Archived' : 'Active',
                ]);
                $refVisits = $visits->where('referral_id', $ref->referral_id)->sortBy(fn ($v) => [$this->safeDate($v->followup_date) ?: $v->created_at, $v->letter_id, $v->followup_id]);
                $latest = null;
                foreach ($refVisits as $v) {
                    if ($v->followup_id) $allVisitIds[$v->letter_id] = true;
                    $archived = $v->deleted_at || $v->visit_archived_at;
                    if (! $archived && $v->followup_id) $latest = $v;
                    if (! $v->followup_id) $quality[] = 'Case '.$caseId.': follow-up letter '.$v->letter_id.' has no matching active visit record. It is not counted as a visit.';
                    if ($v->followup_date && ! $this->safeDate($v->followup_date)) $quality[] = 'Case '.$caseId.': follow-up letter '.$v->letter_id.' has an invalid historical visit date. The recorded letter date is used instead.';
                    $destination = $hospital; $detail = $canNotes ? ($v->followup_notes ?: $v->content_summary) : 'Clinical notes restricted by permissions';
                    if ($v->next_appointment_date) $detail .= ' | Next appointment: '.$v->next_appointment_date;
                    if (! empty($v->letter_file) && $canNotes) $detail .= ' | Supporting document recorded';
                    if ($v->outcome === 'Transferred') {
                        $child = $refById->get($v->transferred_referral_id);
                        if ($child && (int) $child->parent_referral_id === (int) $ref->referral_id && (int) $child->patient_histories_id === $caseId && (int) $child->patient_id === (int) $case->patient_id) {
                            $destination = $hospitals[$child->hospital_id] ?? 'Not recorded';
                        } else {
                            $destination = 'Transfer destination needs review';
                            $quality[] = 'Case '.$caseId.': follow-up letter '.$v->letter_id.' has no verified destination link in the selected records.';
                        }
                    }
                    $visitActor = $journal->where('patient_id', $case->patient_id)->where('letter_id', $v->letter_id)->where('event_kind', 'Follow-up recorded')->first();
                    $add('visit-'.$v->letter_id.'-'.($v->followup_id ?: 'missing'), $this->safeDate($v->followup_date) ?: $v->created_at, $v->followup_id ? 'Follow-up visit' : 'Follow-up letter without visit record', [
                        'source_hospital' => $hospital, 'destination_hospital' => $destination, 'outcome' => $v->outcome,
                        'notes' => $detail ?: 'No notes recorded', 'recorded_at' => $v->created_at,
                        'actor' => $this->actor($users, $visitActor->actor_id ?? null), 'record_state' => $archived ? 'Archived' : 'Current stored visit',
                    ]);
                    if ($v->updated_at !== $v->created_at && $journal->where('letter_id', $v->letter_id)->isEmpty()) {
                        $add('visit-edit-'.$v->letter_id, $v->updated_at, 'Follow-up record updated', ['source_hospital' => $hospital,
                            'notes' => 'Earlier values and the editor were not recorded. Current stored outcome: '.$v->outcome]);
                    }
                }
                $outcome = $latest?->outcome ?: 'No follow-up recorded';
                $latestLabels[] = $hospital.' ('.$ref->referral_number.'): '.$outcome;
                $hospitalRows[] = $base + ['referral_number' => $ref->referral_number, 'source_hospital' => $from,
                    'destination_hospital' => $hospital, 'referral_status' => $ref->status, 'outcome' => $outcome,
                    'visit_date' => $latest ? ($this->safeDate($latest->followup_date) ?: $latest->created_at) : null, 'record_state' => $ref->deleted_at ? 'Archived' : 'Active'];
            }
            foreach ($journal->where('patient_histories_id', $caseId)->where('patient_id', $case->patient_id) as $event) {
                if ($event->event_kind === 'Follow-up recorded') {
                    $visit = $visits->where('letter_id', $event->letter_id)->first();
                    if ($visit && substr($this->safeDate($visit->followup_date) ?: $visit->created_at, 0, 10) === substr($event->occurred_at, 0, 10)) continue;
                }
                $snapshot = json_decode($event->snapshot, true) ?: [];
                $add('journal-'.$event->id, $event->occurred_at, $event->event_kind, [
                    'actor' => $this->actor($users, $event->actor_id), 'outcome' => $event->outcome ?: '',
                    'notes' => $canNotes ? $this->changeNotes($snapshot) : 'Change details restricted by permissions',
                    'record_state' => 'Recorded change — not another visit',
                ]);
            }
            $this->supportingMovements($caseId, $caseRefs, $support, $user, $hospitals, $users, $add);
            $dated = array_filter(array_column($caseTimeline, 'occurred_at'));
            $rows[] = $base + ['submitted_at' => $case->created_at, 'source_hospital' => $source, 'destination_hospitals' => $caseRefs->pluck('hospital_id')->unique()->map(fn ($id) => $hospitals[$id] ?? 'Not recorded')->implode('; ') ?: 'No hospital referral recorded',
                'referral_numbers' => $caseRefs->pluck('referral_number')->unique()->implode('; ') ?: 'None', 'status' => $case->status,
                'status_label' => PatientHistory::labelForStatus($case->status), 'latest_outcomes' => implode('; ', $latestLabels) ?: 'No hospital follow-up recorded',
                'followup_count' => count($allVisitIds), 'last_activity' => $dated ? max($dated) : null,
                'record_state' => $case->deleted_at || $case->patient_archived_at ? 'Archived' : 'Active'];
            foreach ($caseTimeline as $movement) {
                if ($f['case_id'] || $movement['period_context'] === 'In period') $timeline[] = $movement;
            }
        }
        return [$rows, $timeline, $hospitalRows, $quality];
    }

    private function supportingTables(User $user): array
    {
        $tables = [];
        if (SuperAdminAccess::allowed($user, 'View ReferralLetter')) $tables['referral_letters'] = ['Referral letter recorded', 'referral_letter_id'];
        if (SuperAdminAccess::allowed($user, 'View Treatment')) $tables['treatments'] = ['Treatment recorded', 'treatment_id'];
        if (SuperAdminAccess::allowed($user, 'View Referral')) $tables['referral_flights'] = ['Travel recorded', 'referral_flight_id'];
        if (SuperAdminAccess::allowed($user, 'View Bill')) $tables['bills'] = ['Bill recorded', 'bill_id'];
        return $tables;
    }

    private function supportingRecords(array $caseIds, array $refIds, array $f, User $user): array
    {
        $records = [];
        foreach ($this->supportingTables($user) as $table => $definition) {
            if (! Schema::hasTable($table)) continue;
            $query = DB::table($table)->whereIn('referral_id', $refIds);
            if (! $f['include_archived'] && Schema::hasColumn($table, 'deleted_at')) $query->whereNull('deleted_at');
            $records[$table] = $query->get();
        }
        $query = DB::table('boarded_out_letters')->whereIn('patient_histories_id', $caseIds);
        if (! $f['include_archived']) $query->whereNull('deleted_at');
        $records['boarded_out_letters'] = $query->get();
        if (isset($records['bills']) && SuperAdminAccess::allowed($user, 'View Payment') && Schema::hasTable('bill_payments')) {
            $query = DB::table('bill_payments')->whereIn('bill_id', $records['bills']->pluck('bill_id')->all());
            if (! $f['include_archived']) $query->whereNull('deleted_at');
            $records['bill_payments'] = $query->get();
        }
        if ($this->printHistoryAllowed($user) && Schema::hasTable('letter_print_events')) {
            $letters = DB::table('referral_letters')->whereIn('referral_id', $refIds);
            $followups = DB::table('hospital_letters')->whereIn('referral_id', $refIds);
            if (! $f['include_archived']) { $letters->whereNull('deleted_at'); $followups->whereNull('deleted_at'); }
            $records['print_referral_letters'] = $letters->get(); $records['print_followup_letters'] = $followups->get();
            $allowed = ['referral' => $records['print_referral_letters']->pluck('referral_letter_id')->all(), 'follow_up' => $records['print_followup_letters']->pluck('letter_id')->all(),
                'boarded_out' => $records['boarded_out_letters']->pluck('id')->all()];
            $records['letter_print_events'] = DB::table('letter_print_events')->where(function (Builder $q) use ($allowed): void {
                foreach ($allowed as $type => $ids) $q->orWhere(fn (Builder $part) => $part->where('letter_type', $type)->whereIn('letter_id', $ids));
            })->get();
        }
        return $records;
    }

    private function supportingMovements(int $caseId, Collection $refs, array $records, User $user, Collection $hospitals, Collection $users, callable $add): void
    {
        $refIds = $refs->pluck('referral_id')->all();
        foreach ($this->supportingTables($user) as $table => [$label, $id]) {
            foreach (($records[$table] ?? collect())->whereIn('referral_id', $refIds) as $record) {
                $detail = [];
                // Explicit allowlist: never expose raw files, private audit snapshots, IPs or user agents.
                foreach (['start_date', 'end_date', 'received_date', 'started_date', 'ended_date', 'treatment_status', 'arrival_date', 'flight_number', 'bill_status', 'total_amount'] as $field) {
                    if (! empty($record->$field)) $detail[] = ucwords(str_replace('_', ' ', $field)).': '.$record->$field;
                }
                $hospital = $hospitals[$refs->firstWhere('referral_id', $record->referral_id)?->hospital_id] ?? 'Not recorded';
                $add($table.'-'.$record->$id, $record->created_at, $label, ['notes' => implode('; ', $detail), 'destination_hospital' => $hospital,
                    'actor' => $this->actor($users, $record->created_by ?? null), 'record_state' => ! empty($record->deleted_at) ? 'Archived' : 'Recorded']);
                if ($table === 'bills') {
                    foreach (($records['bill_payments'] ?? collect())->where('bill_id', $record->bill_id) as $allocation) {
                        $add('payment-'.$allocation->bill_payment_id, $allocation->allocation_date ?: $allocation->created_at, 'Payment allocated',
                            ['notes' => 'Allocated amount: '.$allocation->allocated_amount.'; status: '.$allocation->status]);
                    }
                }
            }
        }
        foreach (($records['boarded_out_letters'] ?? collect())->where('patient_histories_id', $caseId) as $bo) {
            $add('boarded-out-'.$bo->id, $bo->created_at, 'Boarded-out letter recorded', [
                'outcome' => 'Boarded Out', 'notes' => 'Reference: '.($bo->reference_number ?: 'Not recorded'), 'record_state' => $bo->deleted_at ? 'Archived' : 'Recorded']);
        }
        $letters = ($records['print_referral_letters'] ?? collect())->whereIn('referral_id', $refIds)->pluck('referral_letter_id')->all();
        $followups = ($records['print_followup_letters'] ?? collect())->whereIn('referral_id', $refIds)->pluck('letter_id')->all();
        $boarded = ($records['boarded_out_letters'] ?? collect())->where('patient_histories_id', $caseId)->pluck('id')->all();
        foreach ($records['letter_print_events'] ?? [] as $print) {
            $allowedIds = ['referral' => $letters, 'follow_up' => $followups, 'boarded_out' => $boarded][$print->letter_type] ?? [];
            if (! in_array($print->letter_id, $allowedIds)) continue;
            $add('print-'.$print->letter_print_event_id, $print->printed_at, 'Letter printed', [
                'notes' => ucwords(str_replace('_', ' ', $print->letter_type)).'; language: '.$print->language,
                'actor' => $this->actor($users, $print->printed_by)]);
        }
    }

    private function printHistoryAllowed(User $user): bool
    {
        return $user->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN', 'ROLE DG', 'ROLE DIRECTOR GENERAL']);
    }

    private function actor(Collection $users, mixed $id): string
    {
        $user = $users->get($id);
        return $user ? trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name]))) : ($id ? 'Recorded user #'.$id : 'Not recorded');
    }

    private function changeNotes(array $snapshot): string
    {
        $labels = ['followup_date' => 'Visit date', 'followup_status' => 'Follow-up status', 'notes' => 'Notes',
            'outcome' => 'Outcome', 'content_summary' => 'Summary', 'next_appointment_date' => 'Next appointment',
            'transferred_referral_id' => 'Destination referral', 'deleted_at' => 'Archived at'];
        $changes = [];
        foreach ($labels as $field => $label) {
            $after = $snapshot['after'][$field] ?? null; $before = $snapshot['before'][$field] ?? null;
            if ($after === $before) continue;
            $changes[] = $label.': '.($before === null || $before === '' ? 'Not recorded' : $before).' → '.($after === null || $after === '' ? 'Not recorded' : $after);
        }
        return implode(' | ', $changes) ?: 'Record change saved';
    }

    private function safeDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', $value, $matches) || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) return null;
        return $value;
    }

    private function columns(array $labels): array
    {
        return collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'type' => $key === 'followup_count' ? 'integer' : 'text'])->values()->all();
    }
}
