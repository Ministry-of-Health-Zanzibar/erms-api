<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\HospitalLetter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive evidence for future edits; never backfill or infer a case link. */
final class CaseJourneyRecorder
{
    public function record(Model $record, string $kind): void
    {
        // Rolling deployments may run code before the additive migration.
        if (! Schema::hasTable('case_journey_events')) return;
        $isVisit = $record instanceof FollowUp;
        $letter = $isVisit ? DB::table('hospital_letters')->where('letter_id', $record->letter_id)->first() : $record;
        if (! $letter) return;
        $referral = DB::table('referrals as r')->join('patient_histories as ph', 'ph.patient_histories_id', '=', 'r.patient_histories_id')
            ->where('r.referral_id', $letter->referral_id)->whereColumn('ph.patient_id', 'r.patient_id')->select('r.*')->first();
        if (! $referral || ($isVisit && (int) $record->patient_id !== (int) $referral->patient_id)) return;
        $fields = $isVisit ? ['followup_date', 'followup_status', 'notes', 'deleted_at']
            : ['outcome', 'content_summary', 'next_appointment_date', 'transferred_referral_id', 'deleted_at'];
        if (str_contains($kind, 'updated') && ! $record->wasChanged($fields)) return;
        $before = []; $after = [];
        foreach ($fields as $field) {
            $after[$field] = $record->getAttribute($field);
            if (str_contains($kind, 'updated')) $before[$field] = $record->getRawOriginal($field);
        }
        DB::table('case_journey_events')->insert([
            'patient_histories_id' => $referral->patient_histories_id, 'patient_id' => $referral->patient_id,
            'referral_id' => $referral->referral_id, 'letter_id' => $letter->letter_id, 'actor_id' => Auth::id(),
            'event_kind' => $kind, 'outcome' => $letter->outcome, 'occurred_at' => now(),
            'snapshot' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
