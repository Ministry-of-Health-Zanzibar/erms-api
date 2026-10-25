<?php

namespace Tests\Unit\Support;

use App\Models\Referral;
use App\Models\Patient;
use App\Models\HospitalLetter;
use App\Models\User;
use App\Support\HospitalDoctorAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class HospitalDoctorAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_hospital_user_is_scoped_to_assigned_hospitals(): void
    {
        $user = $this->hospitalDoctor(7);
        $builder = $this->pivotQuery([4, 8]);
        DB::shouldReceive('table')->with('hospital_user')->once()->andReturn($builder);

        $access = app(HospitalDoctorAccess::class);

        $this->assertTrue($access->isHospitalDoctor($user));
        $this->assertSame([4, 8], $access->hospitalIds($user));
    }

    public function test_hospital_user_does_not_need_a_doctor_professional_title(): void
    {
        $user = $this->hospitalDoctor(7);
        $builder = $this->pivotQuery([4]);
        DB::shouldReceive('table')->with('hospital_user')->once()->andReturn($builder);

        $access = app(HospitalDoctorAccess::class);

        $this->assertTrue($access->isHospitalDoctor($user));
        $this->assertSame([4], $access->hospitalIds($user));
    }

    public function test_hospital_user_can_access_another_hospital_case_when_they_created_it(): void
    {
        $user = $this->hospitalDoctor(7);
        $builder = $this->pivotQuery([4]);
        DB::shouldReceive('table')->with('hospital_user')->times(3)->andReturn($builder);

        $access = app(HospitalDoctorAccess::class);

        $this->assertTrue($access->canAccessReferral($user, new Referral(['hospital_id' => 4])));
        $this->assertTrue($access->canAccessReferral($user, new Referral(['hospital_id' => 9, 'created_by' => 7])));
        $this->assertFalse($access->canAccessReferral($user, new Referral(['hospital_id' => 9])));
    }

    public function test_hospital_user_can_access_a_case_for_a_patient_they_created(): void
    {
        $user = $this->hospitalDoctor(7);
        $builder = $this->pivotQuery([]);
        DB::shouldReceive('table')->with('hospital_user')->once()->andReturn($builder);

        $referral = new Referral(['hospital_id' => 9, 'created_by' => 22, 'patient_id' => 12]);
        $referral->setRelation('patient', new Patient(['created_by' => 7]));

        $this->assertTrue(app(HospitalDoctorAccess::class)->canAccessReferral($user, $referral));
    }

    public function test_doctor_assignment_without_hospital_user_role_does_not_receive_doctor_scope(): void
    {
        Schema::shouldReceive('hasTable')->never();

        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 7]);
        $user->shouldReceive('hasAnyRole')->andReturn(false);
        $user->shouldReceive('hasRole')
            ->with('ROLE HOSPITAL USER')
            ->andReturn(false);

        $access = app(HospitalDoctorAccess::class);

        $this->assertFalse($access->isHospitalDoctor($user));
        $this->assertNull($access->hospitalIds($user));
    }

    public function test_pending_follow_up_cannot_be_printed(): void
    {
        $letter = new HospitalLetter(['dg_approval_status' => 'pending']);

        $this->assertFalse(app(HospitalDoctorAccess::class)->canPrintFollowUpLetter(new User(['id' => 7]), $letter));
    }

    public function test_confirmed_follow_up_can_be_printed_by_an_assigned_hospital_user(): void
    {
        $user = $this->hospitalDoctor(7);
        $builder = $this->pivotQuery([4]);
        DB::shouldReceive('table')->with('hospital_user')->once()->andReturn($builder);

        $referral = new Referral(['hospital_id' => 4]);
        $referral->setRelation('patient', new Patient(['created_by' => 22]));
        $letter = new HospitalLetter(['dg_approval_status' => 'confirmed']);
        $letter->setRelation('referral', $referral);

        $this->assertTrue(app(HospitalDoctorAccess::class)->canPrintFollowUpLetter($user, $letter));
    }

    private function hospitalDoctor(int $id): User
    {
        Schema::shouldReceive('hasTable')->with('hospital_user')->andReturn(true);

        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id]);
        $user->shouldReceive('hasAnyRole')->andReturn(false);
        $user->shouldReceive('hasRole')
            ->with('ROLE HOSPITAL USER')
            ->andReturn(true);

        return $user;
    }

    private function pivotQuery(array $hospitalIds): object
    {
        $query = Mockery::mock();
        $query->shouldReceive('where')->with('user_id', 7)->andReturnSelf();
        $query->shouldReceive('pluck')->with('hospital_id')->andReturn(new Collection($hospitalIds));

        return $query;
    }
}
