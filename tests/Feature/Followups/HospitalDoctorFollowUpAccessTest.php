<?php

namespace Tests\Feature\Followups;

use App\Models\HospitalLetter;
use App\Models\User;
use App\Services\Letters\LetterDocumentService;
use App\Support\HospitalDoctorAccess;
use Mockery;
use Tests\TestCase;

class HospitalDoctorFollowUpAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_hospital_doctor_cannot_preview_or_print_a_pending_followup_request(): void
    {
        $access = Mockery::mock(new HospitalDoctorAccess());
        $access->shouldReceive('deniesFollowUpLetter')->times(2)->andReturn(true);
        $access->shouldReceive('canPrintFollowUpLetter')->times(2)->andReturn(false);
        app()->instance(HospitalDoctorAccess::class, $access);

        $documents = Mockery::mock(LetterDocumentService::class);
        $documents->shouldReceive('findFollowUpLetter')->times(2)->andReturn(
            new HospitalLetter([
                'letter_id' => 12,
                'dg_approval_status' => 'pending',
                'outcome' => 'Follow-up',
            ]),
        );
        app()->instance(LetterDocumentService::class, $documents);

        $this->actingAs($this->user(), 'sanctum');

        $this->getJson('/api/letter-documents/follow-ups/12/pdf')->assertForbidden();
        $this->postJson('/api/letter-documents/follow-ups/12/print')->assertForbidden();
    }

    public function test_hospital_doctor_cannot_create_or_change_followup_records(): void
    {
        $access = Mockery::mock(new HospitalDoctorAccess());
        $access->shouldReceive('isHospitalDoctor')->times(3)->andReturn(true);
        app()->instance(HospitalDoctorAccess::class, $access);

        $this->actingAs($this->user(), 'sanctum');

        $this->postJson('/api/followups', [])->assertForbidden();
        $this->putJson('/api/followups/12', [])->assertForbidden();
        $this->deleteJson('/api/followups/12')->assertForbidden();
    }

    private function user(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 17, 'is_blocked' => false]);
        $user->shouldReceive('can')->andReturn(false);
        $user->shouldReceive('hasAnyRole')->andReturn(false);

        return $user;
    }
}
