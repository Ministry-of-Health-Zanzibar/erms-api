<?php

namespace App\Services;

use App\Models\PatientHistory;
use App\Models\Referral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ReferralCaseLinker
{
    /** Only use an explicit decision, creation event, parent link or a single
     * possible history. Never choose a patient's newest history by default. */
    public function historyId(object $referral): ?int
    {
        if ($referral->patient_histories_id ?? null) {
            return (int) $referral->patient_histories_id;
        }
        $ids = collect();
        if (Schema::hasColumn('boarded_out_letters', 'referral_id')) {
            $ids = $ids->merge(DB::table('boarded_out_letters')->where('referral_id', $referral->referral_id)
                ->whereNull('deleted_at')->pluck('patient_histories_id'));
        }
        if (Schema::hasTable('patient_history_workflow_events')) {
            DB::table('patient_history_workflow_events')->whereNull('undone_at')
                ->whereIn('action', ['auto_approved_registration', 'medical_board_referral_decision', 'mkurugenzi_tiba_decision'])
                ->whereIn('patient_histories_id', DB::table('patient_histories')->where('patient_id', $referral->patient_id)->select('patient_histories_id'))
                ->get(['patient_histories_id', 'metadata'])->each(function ($event) use (&$ids, $referral): void {
                    $metadata = json_decode($event->metadata, true) ?? [];
                    if ((int) ($metadata['context']['referral_id'] ?? 0) === (int) $referral->referral_id) {
                        $ids->push($event->patient_histories_id);
                    }
                });
        }
        if ($referral->parent_referral_id ?? null) {
            $parentId = DB::table('referrals')->where('referral_id', $referral->parent_referral_id)->value('patient_histories_id');
            if ($parentId) $ids->push($parentId);
        }
        $ids = $ids->filter()->unique()->values();
        if ($ids->count() === 1) {
            return DB::table('patient_histories')->where('patient_id', $referral->patient_id)
                ->where('patient_histories_id', $ids->first())->exists() ? (int) $ids->first() : null;
        }
        if ($ids->isNotEmpty()) return null;
        $histories = DB::table('patient_histories')->where('patient_id', $referral->patient_id)->pluck('patient_histories_id');
        return $histories->count() === 1 ? (int) $histories->first() : null;
    }

    public function requireHistory(Referral $referral): PatientHistory
    {
        $id = $this->historyId($referral);
        $history = $id ? PatientHistory::where('patient_id', $referral->patient_id)->find($id) : null;
        if (! $history) {
            throw ValidationException::withMessages(['patient_histories_id' => 'This referral needs a verified case link before its decision can be updated.']);
        }
        if (! $referral->patient_histories_id) {
            $referral->update(['patient_histories_id' => $id]);
        }
        return $history;
    }

    public function backfill(): void
    {
        // Parents precede transfers; a second pass resolves deeper transfer trees.
        for ($pass = 0; $pass < 2; $pass++) {
            DB::table('referrals')->whereNull('patient_histories_id')->orderBy('referral_id')
                ->chunkById(250, function ($referrals): void {
                    foreach ($referrals as $referral) {
                        $id = $this->historyId($referral);
                        if ($id !== null) {
                            DB::table('referrals')->where('referral_id', $referral->referral_id)
                                ->whereNull('patient_histories_id')->update(['patient_histories_id' => $id]);
                        }
                    }
                }, 'referral_id');
        }
    }
}
