<?php

namespace App\Services;

use App\Models\HospitalLetter;
use App\Models\Referral;
use App\Models\ReferralLetter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransferReferralService
{
    public function __construct(private readonly ReferralCaseLinker $cases) {}

    /** Called inside the follow-up transaction. Approval/case status is not changed. */
    public function create(HospitalLetter $followUp, Referral $source, int $hospitalId, int $userId): Referral
    {
        $history = $this->cases->requireHistory($source);
        if (! in_array($source->status, ['Confirmed', 'Transferred', 'BoardedOut', 'Closed', 'Death', 'Expired'], true)
            || ! $this->originalLetter($source)
            || ! DB::table('patients')->where('patient_id', $source->patient_id)->whereNull('deleted_at')->exists()
            || ! DB::table('hospitals')->where('hospital_id', $hospitalId)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['hospital_id' => 'A transfer needs an existing approved referral letter and an active destination hospital.']);
        }

        if ($followUp->transferred_referral_id) {
            $transfer = Referral::find($followUp->transferred_referral_id);
            if (! $transfer || ! $this->matches($source, $transfer) || (int) $transfer->hospital_id !== $hospitalId) {
                throw ValidationException::withMessages(['hospital_id' => 'This follow-up already belongs to a different transfer. Record a new transfer instead.']);
            }
            $this->ensureLetter($transfer, $followUp, $userId);
            return $transfer;
        }

        $transfer = Referral::create([
            'referral_number' => $source->referral_number,
            'patient_id' => $source->patient_id,
            'patient_histories_id' => $history->getKey(),
            'hospital_id' => $hospitalId,
            'status' => 'Transferred',
            'reason_id' => $source->reason_id,
            'parent_referral_id' => $source->getKey(),
            'confirmed_by' => $userId,
            'created_by' => $userId,
        ]);
        $transfer->diagnoses()->sync($source->diagnoses()->pluck('diagnoses.diagnosis_id'));
        $followUp->update(['transferred_referral_id' => $transfer->getKey()]);
        $this->ensureLetter($transfer, $followUp, $userId);

        return $transfer;
    }

    public function transferredReferral(HospitalLetter $followUp): ?Referral
    {
        if ($followUp->outcome !== 'Transferred' || ! $followUp->transferred_referral_id) return null;
        $source = $followUp->referral;
        $transfer = $followUp->transferredReferral;
        return $source && $transfer && $this->matches($source, $transfer) ? $transfer : null;
    }

    /** The first hospital referral, not the latest transfer or another case's referral. */
    public function originalReferral(Referral $selected): ?Referral
    {
        $seen = [];
        while (! isset($seen[$selected->getKey()])) {
            $seen[$selected->getKey()] = true;
            if (! $selected->parent_referral_id) return $selected;
            $parent = Referral::find($selected->parent_referral_id);
            if (! $parent || ! $this->matches($parent, $selected)) return null;
            $selected = $parent;
        }
        return null;
    }

    public function chain(Referral $selected): \Illuminate\Support\Collection
    {
        $root = $selected;
        $seen = [];
        while ($root->parent_referral_id) {
            if (isset($seen[$root->getKey()])) break;
            $seen[$root->getKey()] = true;
            $parent = Referral::find($root->parent_referral_id);
            if (! $parent || ! $this->matches($parent, $root)) break;
            $root = $parent;
        }
        $chain = $root->newCollection([$root]);
        $frontier = [$root->getKey()];
        $seen = [$root->getKey() => true];
        while ($frontier !== []) {
            $children = Referral::whereIn('parent_referral_id', $frontier)
                ->where('patient_id', $root->patient_id)
                ->where('patient_histories_id', $root->patient_histories_id)->orderBy('referral_id')->get();
            $frontier = [];
            foreach ($children as $child) {
                if (isset($seen[$child->getKey()])) continue;
                $seen[$child->getKey()] = true;
                $chain->push($child);
                $frontier[] = $child->getKey();
            }
        }
        return $chain;
    }

    /** Conservative legacy repair. Dry-run by default; never infer from newest patient history. */
    public function repairLegacy(bool $apply = false): array
    {
        $result = ['letters_needed' => 0, 'links_needed' => 0, 'needs_review' => []];
        Referral::where('status', 'Transferred')->whereNotNull('parent_referral_id')->orderBy('referral_id')
            ->chunkById(200, function ($transfers) use ($apply, &$result): void {
                foreach ($transfers as $transfer) {
                    DB::transaction(function () use ($transfer, $apply, &$result): void {
                        $transfer = Referral::whereKey($transfer->getKey())->lockForUpdate()->first();
                        $parent = Referral::find($transfer->parent_referral_id);
                        if (! $parent || ! $this->matches($parent, $transfer) || ! $this->originalLetter($parent)
                            || ! DB::table('patient_histories')->where('patient_histories_id', $transfer->patient_histories_id)->where('patient_id', $transfer->patient_id)->whereNull('deleted_at')->exists()
                            || ! DB::table('hospitals')->where('hospital_id', $transfer->hospital_id)->whereNull('deleted_at')->exists()) {
                            $result['needs_review'][] = $transfer->getKey();
                            return;
                        }
                        $events = HospitalLetter::where('transferred_referral_id', $transfer->getKey())->get();
                        $event = $events->count() === 1 ? $events->first() : null;
                        if (! $event && $events->isEmpty()) {
                            // Only one possible event AND one possible child: timestamps are not proof.
                            $candidates = HospitalLetter::withTrashed()->where('referral_id', $parent->getKey())->where('outcome', 'Transferred')->get();
                            $children = Referral::withTrashed()->where('parent_referral_id', $parent->getKey())->get();
                            if ($candidates->count() === 1 && $children->count() === 1 && ! $candidates->first()->trashed()
                                && ! $candidates->first()->transferred_referral_id) {
                                $event = $candidates->first();
                                $result['links_needed']++;
                                if ($apply) $event->update(['transferred_referral_id' => $transfer->getKey()]);
                            }
                        }
                        if (! $event || (int) $event->referral_id !== (int) $parent->getKey()) $result['needs_review'][] = $transfer->getKey();
                        // The child's explicit parent, case and hospital are sufficient for its own letter.
                        if (! ReferralLetter::withTrashed()->where('referral_id', $transfer->getKey())->exists()) {
                            $result['letters_needed']++;
                            if ($apply) $this->ensureLetter($transfer, $event, (int) ($transfer->created_by ?: $parent->created_by));
                        }
                    });
                }
            }, 'referral_id');
        $result['needs_review'] = array_values(array_unique($result['needs_review']));
        return $result;
    }

    private function ensureLetter(Referral $transfer, ?HospitalLetter $event, int $userId): ReferralLetter
    {
        // A retired letter must not silently be resurrected by printing or repair.
        $existing = ReferralLetter::withTrashed()->where('referral_id', $transfer->getKey())->latest('referral_letter_id')->first();
        if ($existing) return $existing;
        return ReferralLetter::create([
            'referral_id' => $transfer->getKey(),
            'letter_text' => $event?->content_summary ?? '',
            'start_date' => $transfer->created_at->toDateString(),
            'end_date' => null,
            'created_by' => $userId,
        ]);
    }

    private function matches(Referral $parent, Referral $child): bool
    {
        return (int) $child->parent_referral_id === (int) $parent->getKey()
            && (int) $child->patient_id === (int) $parent->patient_id
            && $parent->patient_histories_id !== null
            && (int) $child->patient_histories_id === (int) $parent->patient_histories_id;
    }

    private function originalLetter(Referral $referral): ?ReferralLetter
    {
        $seen = [];
        while (! isset($seen[$referral->getKey()])) {
            $seen[$referral->getKey()] = true;
            $letter = $referral->referralLetters;
            if ($letter && in_array($referral->status, ['Confirmed', 'Transferred', 'BoardedOut', 'Closed', 'Death', 'Expired'], true)) return $letter;
            if (! $referral->parent_referral_id) break;
            $parent = Referral::find($referral->parent_referral_id);
            if (! $parent || ! $this->matches($parent, $referral)) break;
            $referral = $parent;
        }
        return null;
    }
}
