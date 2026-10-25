<?php

namespace App\Support;

use App\Models\HospitalLetter;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HospitalDoctorAccess
{
    /**
     * Hospital follow-up access is controlled by the application role. The
     * hospital_user pivot only supplies the facilities that the user may see;
     * its professional role is not used for this authorization decision.
     */
    public function isHospitalDoctor(?User $user): bool
    {
        if (! $user || SuperAdminAccess::allowed($user) || $user->hasAnyRole([
            'ROLE DG',
            'ROLE DIRECTOR GENERAL',
        ])) {
            return false;
        }

        // Every user with this system role receives the hospital follow-up
        // scope. Do not require a particular professional title such as
        // "Hospital Doctor" on the hospital_user pivot.
        if (! $user->hasRole('ROLE HOSPITAL USER')) {
            return false;
        }

        return true;
    }

    /**
     * Return only facilities where the hospital user is assigned.
     * Null means that the user is not a hospital user and therefore this
     * hospital-specific scope must not be applied. An empty array means the
     * hospital user has no assignment and must not see hospital records.
     */
    public function hospitalIds(?User $user): ?array
    {
        if (! $this->isHospitalDoctor($user)) {
            return null;
        }

        if (! Schema::hasTable('hospital_user')) {
            return [];
        }

        return DB::table('hospital_user')
            ->where('user_id', $user->getKey())
            ->pluck('hospital_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function canViewReferrals(?User $user): bool
    {
        return (bool) $user && ($user->can('View Referral') || $this->isHospitalDoctor($user));
    }

    public function canViewFollowUps(?User $user): bool
    {
        return (bool) $user && ($user->can('View FollowUp') || $this->isHospitalDoctor($user));
    }

    public function canAccessReferral(?User $user, ?Referral $referral): bool
    {
        $hospitalIds = $this->hospitalIds($user);

        if ($hospitalIds === null) {
            return true;
        }

        if ($referral === null) {
            return false;
        }

        return $this->ownsReferral($user, $referral)
            || in_array((int) $referral->hospital_id, $hospitalIds, true);
    }

    /**
     * A hospital user's own case can be represented by either the referral
     * creator or the patient creator. The latter matters when a hospital user
     * registers the patient and a later workflow step creates the referral.
     */
    public function ownsReferral(?User $user, ?Referral $referral): bool
    {
        if (! $user || ! $referral) {
            return false;
        }

        $userId = (int) $user->getKey();
        if ((int) $referral->created_by === $userId) {
            return true;
        }

        if ($referral->relationLoaded('patient')) {
            return (int) ($referral->patient?->created_by ?? 0) === $userId;
        }

        return $referral->patient_id !== null
            && $referral->patient()
                ->where('created_by', $userId)
                ->exists();
    }

    public function applyReferralScope(Builder $query, ?User $user, string $column = 'hospital_id'): void
    {
        $hospitalIds = $this->hospitalIds($user);

        if ($hospitalIds === null) {
            return;
        }

        $query->where(function (Builder $scope) use ($column, $hospitalIds, $user): void {
            if ($hospitalIds !== []) {
                $scope->whereIn($column, $hospitalIds)
                    ->orWhere('created_by', $user->getKey());
            } else {
                $scope->where('created_by', $user->getKey());
            }

            $scope->orWhereHas('patient', function ($patientQuery) use ($user): void {
                $patientQuery->where('created_by', $user->getKey());
            });
        });
    }

    public function deniesFollowUpLetter(?User $user): bool
    {
        return $this->isHospitalDoctor($user);
    }

    /**
     * Hospital users may print a follow-up letter only after the DG has
     * explicitly confirmed that particular submission. This keeps pending
     * requests read-only while allowing the doctor to act on the approval.
     */
    public function canPrintFollowUpLetter(?User $user, ?HospitalLetter $letter): bool
    {
        if (! $user || ! $letter) {
            return false;
        }

        if (in_array($letter->dg_approval_status, ['pending', 'rejected'], true)) {
            return false;
        }

        if (! $this->isHospitalDoctor($user)) {
            return true;
        }

        $letter->loadMissing('referral.patient');

        return $letter->dg_approval_status === 'confirmed'
            && $this->canAccessReferral($user, $letter->referral);
    }
}
