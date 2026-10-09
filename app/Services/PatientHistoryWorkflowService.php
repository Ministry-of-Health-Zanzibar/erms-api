<?php

namespace App\Services;

use App\Models\PatientHistory;
use App\Models\PatientHistoryWorkflowEvent;
use App\Models\User;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PatientHistoryWorkflowService
{
    private const TERMINAL_REFERRAL_STATUSES = [
        'Closed',
        'Cancelled',
        'Expired',
        'BoardedOut',
    ];

    private const TABLES = [
        'referrals' => ['column' => 'referral_id', 'key' => 'referral_id'],
        'diagnosis_referral' => ['column' => 'referral_id', 'key' => 'id'],
        'hospital_letters' => ['column' => 'referral_id', 'key' => 'letter_id'],
        'referral_letters' => ['column' => 'referral_id', 'key' => 'referral_letter_id'],
        'treatments' => ['column' => 'referral_id', 'key' => 'treatment_id'],
        'referral_flights' => ['column' => 'referral_id', 'key' => 'referral_flight_id'],
        'bills' => ['column' => 'referral_id', 'key' => 'bill_id'],
    ];

    private const CHILD_TABLES = [
        'followups' => ['column' => 'letter_id', 'key' => 'followup_id'],
        'bill_items' => ['column' => 'bill_id', 'key' => 'bill_item_id'],
        'bill_payments' => ['column' => 'bill_id', 'key' => 'bill_payment_id'],
    ];

    /**
     * Capture the patient-history state and, when requested, the related
     * referral tree for this exact case. Callers changing one referral can
     * pass its tree IDs to keep the snapshot limited to that referral branch.
     */
    public function snapshot(PatientHistory $history, ?array $referralIds = null): array
    {
        $historyRow = DB::table('patient_histories')
            ->where('patient_histories_id', $history->patient_histories_id)
            ->first();

        $referralIds = $referralIds === null
            ? $this->referralTreeIds((int) $history->patient_histories_id)
            : collect($referralIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $tables = [
            'patient_histories' => [
                'key' => 'patient_histories_id',
                'rows' => $historyRow ? [(array) $historyRow] : [],
            ],
            'boarded_out_letters' => [
                'key' => 'id',
                'rows' => Schema::hasTable('boarded_out_letters')
                    ? DB::table('boarded_out_letters')
                        ->where('patient_histories_id', $history->patient_histories_id)
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all()
                    : [],
            ],
            'history_diagnosis' => [
                'key' => null,
                'rows' => Schema::hasTable('history_diagnosis')
                    ? DB::table('history_diagnosis')
                        ->where('patient_histories_id', $history->patient_histories_id)
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all()
                    : [],
            ],
            'patient_files' => [
                'key' => 'file_id',
                'rows' => Schema::hasTable('patient_files')
                    ? DB::table('patient_files')
                        ->where('patient_id', $history->patient_id)
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all()
                    : [],
            ],
            'patient_list_patient' => [
                'key' => null,
                'rows' => Schema::hasTable('patient_list_patient')
                    ? DB::table('patient_list_patient')
                        ->where('patient_id', $history->patient_id)
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all()
                    : [],
            ],
        ];

        foreach (self::TABLES as $table => $definition) {
            $rows = [];
            if ($referralIds !== [] && Schema::hasTable($table)) {
                $rows = DB::table($table)
                    ->whereIn($definition['column'], $referralIds)
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all();
            }
            $tables[$table] = [
                'key' => $definition['key'],
                'rows' => $rows,
            ];
        }

        $letterIds = collect($tables['hospital_letters']['rows'])
            ->pluck('letter_id')
            ->filter()
            ->values()
            ->all();
        $billIds = collect($tables['bills']['rows'])
            ->pluck('bill_id')
            ->filter()
            ->values()
            ->all();

        foreach (self::CHILD_TABLES as $table => $definition) {
            $ids = $table === 'followups' ? $letterIds : $billIds;
            $rows = [];
            if ($ids !== [] && Schema::hasTable($table)) {
                $rows = DB::table($table)
                    ->whereIn($definition['column'], $ids)
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all();
            }
            $tables[$table] = [
                'key' => $definition['key'],
                'rows' => $rows,
            ];
        }

        $paymentIds = collect($tables['bill_payments']['rows'])
            ->pluck('payment_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $tables['payments'] = [
            'key' => 'payment_id',
            'rows' => $paymentIds !== [] && Schema::hasTable('payments')
                ? DB::table('payments')->whereIn('payment_id', $paymentIds)->get()->map(fn ($row) => (array) $row)->all()
                : [],
        ];

        return [
            'patient_history' => $historyRow ? (array) $historyRow : [],
            'referral_ids' => $referralIds,
            'tables' => $tables,
        ];
    }

    public function record(
        PatientHistory $history,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        array $beforeSnapshot,
        array $context = [],
        ?array $referralIds = null,
    ): PatientHistoryWorkflowEvent {
        $afterSnapshot = $this->snapshot($history->fresh(), $referralIds);

        $event = PatientHistoryWorkflowEvent::create([
            'patient_histories_id' => $history->patient_histories_id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => auth()->id(),
            'metadata' => [
                'before' => $beforeSnapshot,
                'after' => $afterSnapshot,
                'context' => AuditService::scrub($context),
            ],
        ]);

        AuditService::record(
            'workflow_transition',
            'patient_histories',
            $history,
            ['status' => $fromStatus],
            ['status' => $toStatus],
            'Patient history workflow transition recorded',
            array_merge($context, [
                'patient_history_id' => $history->patient_histories_id,
                'workflow_event_id' => $event->id,
            ]),
        );

        return $event;
    }

    /**
     * Return a referral and all of its descendants for an isolated snapshot.
     */
    public function referralTreeSnapshotIds(int $referralId): array
    {
        $ids = collect([(int) $referralId]);
        $frontier = $ids->all();

        while ($frontier !== []) {
            $children = DB::table('referrals')
                ->whereIn('parent_referral_id', $frontier)
                ->pluck('referral_id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $ids = $ids->merge($children)->unique()->values();
            $frontier = $children->all();
        }

        return $ids->all();
    }

    public function undo(int $eventId, User $actor, string $reason): PatientHistoryWorkflowEvent
    {
        if (trim($reason) === '') {
            throw new RuntimeException('An undo reason is required.');
        }

        return DB::transaction(function () use ($eventId, $actor, $reason): PatientHistoryWorkflowEvent {
            $event = PatientHistoryWorkflowEvent::query()
                ->lockForUpdate()
                ->find($eventId);
            if (! $event) {
                throw new RuntimeException('Workflow event not found.');
            }
            if ($event->undone_at) {
                throw new RuntimeException('This workflow event has already been undone.');
            }

            $history = PatientHistory::query()
                ->lockForUpdate()
                ->find($event->patient_histories_id);
            if (! $history) {
                throw new RuntimeException('Patient history not found.');
            }

            $latest = PatientHistoryWorkflowEvent::query()
                ->where('patient_histories_id', $event->patient_histories_id)
                ->whereNull('undone_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $latest || $latest->id !== $event->id) {
                throw new RuntimeException('Only the latest active workflow event can be undone.');
            }

            if ($event->to_status !== null && $history->status !== $event->to_status) {
                throw new RuntimeException('The patient history has changed since this event and cannot be safely undone.');
            }

            $metadata = $event->metadata ?? [];
            $before = $metadata['before'] ?? [];
            $after = $metadata['after'] ?? [];
            $this->lockSnapshotRows($before, $after);
            $this->restoreSnapshot($before, $after);

            $event->forceFill([
                'undone_at' => now(),
                'undone_by' => $actor->id,
                'undo_reason' => $reason,
            ])->saveQuietly();

            AuditService::record(
                'workflow_undo',
                'patient_histories',
                $history,
                ['event_id' => $event->id, 'status' => $event->to_status],
                ['status' => $event->from_status],
                'Patient history workflow transition undone',
                [
                    'patient_history_id' => $history->patient_histories_id,
                    'undo_reason' => $reason,
                    'workflow_event_id' => $event->id,
                ],
            );

            return $event->fresh();
        });
    }

    private function referralTreeIds(int $historyId): array
    {
        $rootIds = DB::table('referrals')
            ->where('patient_histories_id', $historyId)
            ->whereNotIn('status', self::TERMINAL_REFERRAL_STATUSES)
            ->pluck('referral_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $allIds = $rootIds;
        $frontier = $rootIds->all();

        while ($frontier !== []) {
            $children = DB::table('referrals')
                ->whereIn('parent_referral_id', $frontier)
                ->pluck('referral_id')
                ->map(fn ($id) => (int) $id)
                ->diff($allIds)
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $allIds = $allIds->merge($children)->unique()->values();
            $frontier = $children->all();
        }

        return $allIds->all();
    }

    private function restoreSnapshot(array $before, array $after): void
    {
        $beforeTables = $before['tables'] ?? [];
        $afterTables = $after['tables'] ?? [];

        $extraOrder = [
            'followups',
            'bill_payments',
            'bill_items',
            'referral_flights',
            'patient_files',
            'patient_list_patient',
            'boarded_out_letters',
            'diagnosis_referral',
            'referral_letters',
            'hospital_letters',
            'treatments',
            'bills',
            'payments',
            'referrals',
        ];

        foreach ($extraOrder as $table) {
            $this->deactivateExtraRows($table, $beforeTables[$table] ?? [], $afterTables[$table] ?? []);
        }

        $restoreOrder = [
            'patient_histories',
            'patient_files',
            'boarded_out_letters',
            'referrals',
            'payments',
            'bills',
            'bill_items',
            'bill_payments',
            'hospital_letters',
            'followups',
            'referral_letters',
            'treatments',
            'referral_flights',
            'diagnosis_referral',
        ];

        foreach ($restoreOrder as $table) {
            $definition = $beforeTables[$table] ?? null;
            if (! $definition || ! Schema::hasTable($table)) {
                continue;
            }

            $key = $definition['key'];
            foreach ($definition['rows'] ?? [] as $row) {
                if ($key === null) {
                    continue;
                }

                $rowKey = $row[$key] ?? null;
                if ($rowKey === null) {
                    continue;
                }

                DB::table($table)->updateOrInsert(
                    [$key => $rowKey],
                    $row,
                );
            }
        }

        if (Schema::hasTable('history_diagnosis')) {
            $historyId = $before['patient_history']['patient_histories_id'] ?? null;
            if ($historyId !== null) {
                DB::table('history_diagnosis')
                    ->where('patient_histories_id', $historyId)
                    ->delete();

                $pivotRows = $beforeTables['history_diagnosis']['rows'] ?? [];
                if ($pivotRows !== []) {
                    DB::table('history_diagnosis')->insert($pivotRows);
                }
            }
        }

        if (Schema::hasTable('patient_list_patient')) {
            $patientId = $before['patient_history']['patient_id']
                ?? $after['patient_history']['patient_id']
                ?? null;
            if ($patientId !== null) {
                DB::table('patient_list_patient')
                    ->where('patient_id', $patientId)
                    ->delete();

                $pivotRows = $beforeTables['patient_list_patient']['rows'] ?? [];
                if ($pivotRows !== []) {
                    DB::table('patient_list_patient')->insert($pivotRows);
                }
            }
        }
    }

    /**
     * Lock the referral tree and its direct dependants before restoring a
     * snapshot. This prevents a concurrent DG, hospital, or follow-up update
     * from being overwritten while a Super Admin is undoing a transition.
     */
    private function lockSnapshotRows(array $before, array $after): void
    {
        $referralIds = collect([
            ...($before['referral_ids'] ?? []),
            ...($after['referral_ids'] ?? []),
        ])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        if ($referralIds !== []) {
            foreach (self::TABLES as $table => $definition) {
                if (Schema::hasTable($table)) {
                    DB::table($table)
                        ->whereIn($definition['column'], $referralIds)
                        ->lockForUpdate()
                        ->get();
                }
            }
        }

        // Lock rows whose foreign keys are reached through a referral-owned
        // record as well. Without these locks, a concurrent follow-up or bill
        // update could be committed after the referral rows were locked but
        // before the snapshot was restored.
        $beforeTables = $before['tables'] ?? [];
        $afterTables = $after['tables'] ?? [];

        $letterIds = collect([
            ...collect($beforeTables['hospital_letters']['rows'] ?? [])->pluck('letter_id')->all(),
            ...collect($afterTables['hospital_letters']['rows'] ?? [])->pluck('letter_id')->all(),
        ])->filter()->unique()->values()->all();
        if ($letterIds !== [] && Schema::hasTable('followups')) {
            DB::table('followups')->whereIn('letter_id', $letterIds)->lockForUpdate()->get();
        }

        $billIds = collect([
            ...collect($beforeTables['bills']['rows'] ?? [])->pluck('bill_id')->all(),
            ...collect($afterTables['bills']['rows'] ?? [])->pluck('bill_id')->all(),
        ])->filter()->unique()->values()->all();
        if ($billIds !== []) {
            foreach (['bill_items', 'bill_payments'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('bill_id', $billIds)->lockForUpdate()->get();
                }
            }
        }

        if (Schema::hasTable('history_diagnosis')) {
            $historyId = $before['patient_history']['patient_histories_id']
                ?? $after['patient_history']['patient_histories_id']
                ?? null;
            if ($historyId !== null) {
                DB::table('history_diagnosis')
                    ->where('patient_histories_id', $historyId)
                    ->lockForUpdate()
                    ->get();
            }
        }

        if (Schema::hasTable('boarded_out_letters')) {
            $historyId = $before['patient_history']['patient_histories_id']
                ?? $after['patient_history']['patient_histories_id']
                ?? null;
            if ($historyId !== null) {
                DB::table('boarded_out_letters')
                    ->where('patient_histories_id', $historyId)
                    ->lockForUpdate()
                    ->get();
            }
        }

        if (Schema::hasTable('patient_files')) {
            $patientId = $before['patient_history']['patient_id']
                ?? $after['patient_history']['patient_id']
                ?? null;
            if ($patientId !== null) {
                DB::table('patient_files')
                    ->where('patient_id', $patientId)
                    ->lockForUpdate()
                    ->get();
            }
        }

        if (Schema::hasTable('patient_list_patient')) {
            $patientId = $before['patient_history']['patient_id']
                ?? $after['patient_history']['patient_id']
                ?? null;
            if ($patientId !== null) {
                DB::table('patient_list_patient')
                    ->where('patient_id', $patientId)
                    ->lockForUpdate()
                    ->get();
            }
        }
    }

    private function deactivateExtraRows(string $table, array $before, array $after): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $key = $after['key'] ?? null;
        if ($key === null) {
            return;
        }

        $beforeIds = collect($before['rows'] ?? [])->pluck($key)->filter()->map(fn ($id) => (string) $id);
        $extraIds = collect($after['rows'] ?? [])
            ->pluck($key)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->diff($beforeIds)
            ->values();

        if ($extraIds->isEmpty()) {
            return;
        }

        $query = DB::table($table)->whereIn($key, $extraIds->all());
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->update(['deleted_at' => now()]);
        } else {
            $query->delete();
        }
    }
}
