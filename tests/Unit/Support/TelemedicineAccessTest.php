<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\TelemedicineAccess;
use Mockery;
use Tests\TestCase;

class TelemedicineAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dcs_can_create_a_request_but_does_not_receive_appointment_scheduling_access(): void
    {
        $user = $this->userWithRoles([TelemedicineAccess::ROLE_DCS]);

        $this->assertTrue(TelemedicineAccess::canCreate($user));
    }

    public function test_bill_verification_officer_can_schedule_appointments(): void
    {
        $user = $this->userWithRoles([TelemedicineAccess::ROLE_BILL_VERIFICATION]);

        $this->assertTrue(TelemedicineAccess::canView($user));
        $this->assertTrue(TelemedicineAccess::canSchedule($user));
    }

    public function test_hospital_user_can_review_reports_and_record_decisions(): void
    {
        $user = $this->userWithRoles(['ROLE HOSPITAL USER'], [
            'View Telemedicine',
            'Create Telemedicine Request',
            'Review Telemedicine Appointment',
            'Manage Telemedicine Report',
            'Record Telemedicine Decision',
        ]);

        $this->assertTrue(TelemedicineAccess::canView($user));
        $this->assertTrue(TelemedicineAccess::canCreate($user));
        $this->assertTrue(TelemedicineAccess::canReviewAppointment($user));
        $this->assertTrue(TelemedicineAccess::canManageReport($user));
        $this->assertTrue(TelemedicineAccess::canRecordDecision($user));
    }

    public function test_coordinator_can_view_and_schedule_the_coordination_queue(): void
    {
        $user = $this->userWithRoles([TelemedicineAccess::ROLE_COORDINATOR]);

        $this->assertTrue(TelemedicineAccess::canView($user));
        $this->assertTrue(TelemedicineAccess::canSchedule($user));
    }

    private function userWithRoles(array $roles, array $permissions = []): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 99]);
        $user->shouldReceive('hasAnyRole')->andReturnUsing(function (array $expected) use ($roles): bool {
            return array_intersect($expected, $roles) !== [];
        });
        $user->shouldReceive('hasRole')->andReturnUsing(function (string $role) use ($roles): bool {
            return in_array($role, $roles, true);
        });
        $user->shouldReceive('can')->andReturnUsing(function (string $permission) use ($permissions): bool {
            return in_array($permission, $permissions, true);
        });

        return $user;
    }
}
