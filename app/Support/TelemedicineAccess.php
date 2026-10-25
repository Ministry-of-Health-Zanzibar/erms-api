<?php

namespace App\Support;

use App\Models\TelemedicineRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TelemedicineAccess
{
    public const ROLE_COORDINATOR = 'ROLE TELEMEDICINE COORDINATOR';
    public const ROLE_BILL_VERIFICATION = 'ROLE BILL VERIFICATION OFFICER';
    public const ROLE_DCS = 'ROLE MKURUGENZI TIBA';

    private function __construct()
    {
    }

    public static function isBillVerificationOfficer(?User $user): bool
    {
        return (bool) $user && $user->hasRole(self::ROLE_BILL_VERIFICATION);
    }

    public static function isCoordinator(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole(self::ROLE_COORDINATOR)) {
            return true;
        }

        return DB::table('hospital_user')
            ->where('user_id', $user->getKey())
            ->whereRaw("LOWER(COALESCE(role, '')) LIKE ?", ['%coordinator%'])
            ->exists();
    }

    public static function isDcs(?User $user): bool
    {
        return (bool) $user && $user->hasAnyRole([self::ROLE_DCS, 'ROLE SUPERVISOR']);
    }

    public static function isHospitalDoctor(?User $user): bool
    {
        if (! $user || SuperAdminAccess::allowed($user)) {
            return false;
        }

        return $user->hasRole('ROLE HOSPITAL USER')
            || DB::table('hospital_user')
                ->where('user_id', $user->getKey())
                ->whereRaw("LOWER(COALESCE(role, '')) LIKE ?", ['%doctor%'])
                ->exists();
    }

    public static function canView(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('View Telemedicine')
            || $user->hasAnyRole([
                'ROLE DG',
                'ROLE DIRECTOR GENERAL',
                self::ROLE_BILL_VERIFICATION,
                self::ROLE_COORDINATOR,
                self::ROLE_DCS,
                'ROLE HOSPITAL USER',
            ])
        );
    }

    public static function canCreate(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('Create Telemedicine Request')
            || self::isDcs($user)
            || self::isCoordinator($user)
            || self::isHospitalDoctor($user)
        );
    }

    public static function canSchedule(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('Schedule Telemedicine Appointment')
            || self::isBillVerificationOfficer($user)
            || self::isCoordinator($user)
        );
    }

    public static function canReviewAppointment(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('Review Telemedicine Appointment')
            || self::isHospitalDoctor($user)
            || self::isCoordinator($user)
        );
    }

    public static function canManageReport(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('Manage Telemedicine Report')
            || self::isHospitalDoctor($user)
            || self::isCoordinator($user)
        );
    }

    public static function canRecordDecision(?User $user): bool
    {
        return (bool) $user && (
            SuperAdminAccess::allowed($user)
            || $user->can('Record Telemedicine Decision')
            || self::isHospitalDoctor($user)
        );
    }

    public static function canAccessRequest(?User $user, ?TelemedicineRequest $request): bool
    {
        if (! $user || ! $request) {
            return false;
        }

        if (SuperAdminAccess::allowed($user)
            || $user->hasAnyRole([
                'ROLE DG',
                'ROLE DIRECTOR GENERAL',
                self::ROLE_BILL_VERIFICATION,
                self::ROLE_DCS,
            ])) {
            return true;
        }

        if ((int) $request->created_by === (int) $user->getKey()
            || (int) $request->requesting_doctor_id === (int) $user->getKey()) {
            return true;
        }

        $request->loadMissing('patient', 'appointments');
        if ((int) ($request->patient?->created_by ?? 0) === (int) $user->getKey()) {
            return true;
        }

        if (self::isCoordinator($user)) {
            $hospitalIds = $user->hospitals()->pluck('hospitals.hospital_id')->map(fn ($id) => (int) $id)->all();
            return $request->appointments->contains(fn ($appointment): bool => in_array((int) $appointment->hospital_id, $hospitalIds, true)
                || (int) $appointment->telemedicine_coordinator_id === (int) $user->getKey());
        }

        if (self::isHospitalDoctor($user)) {
            return $request->appointments->contains(fn ($appointment): bool => (int) $appointment->assigned_doctor_id === (int) $user->getKey());
        }

        return false;
    }
}
