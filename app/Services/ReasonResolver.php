<?php

namespace App\Services;

use App\Models\Reason;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ReasonResolver
{
    /**
     * Return an existing reason ID or create/reuse a reason from custom text.
     *
     * The caller is responsible for validating an explicitly supplied ID.
     * Custom text takes precedence so the database always receives a real
     * reasons.reason_id value.
     */
    public function resolve($reasonId = null, ?string $customReason = null): int
    {
        $customReason = $this->normalise($customReason);

        if ($customReason !== '') {
            $reason = Reason::withTrashed()
                ->whereRaw('LOWER(TRIM(referral_reason_name)) = ?', [mb_strtolower($customReason)])
                ->first();

            if ($reason) {
                if ($reason->trashed()) {
                    $reason->restore();
                }

                return (int) $reason->reason_id;
            }

            return (int) Reason::create([
                'referral_reason_name' => $customReason,
                'reason_descriptions' => null,
                'created_by' => Auth::id(),
            ])->reason_id;
        }

        if ($reasonId !== null && $reasonId !== '') {
            return (int) $reasonId;
        }

        throw ValidationException::withMessages([
            'reason_id' => 'Select a referral reason or enter a custom reason.',
        ]);
    }

    private function normalise(?string $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }
}
