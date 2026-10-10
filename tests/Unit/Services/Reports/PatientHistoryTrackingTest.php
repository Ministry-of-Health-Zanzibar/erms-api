<?php

namespace Tests\Unit\Services\Reports;

use App\Models\PatientHistory;
use App\Services\Reports\CaseReport;
use Tests\TestCase;

class PatientHistoryTrackingTest extends TestCase
{
    public function test_five_workflow_stages_keep_the_existing_status_codes(): void
    {
        $stages = [
            'reviewed' => [1, 20, 'Awaiting Medical Board', 'Medical Board'],
            'assigned' => [2, 40, 'Assigned to Board Meeting', 'Medical Board'],
            'requested' => [3, 60, 'Awaiting DCS Approval', 'DCS'],
            'approved' => [4, 80, 'Approved by DCS', 'Director General (DG)'],
            'confirmed' => [5, 100, 'Confirmed', 'Completed'],
        ];
        foreach ($stages as $status => [$stage, $progress, $label, $holder]) {
            $history = new PatientHistory(['status' => $status]);
            $this->assertSame($stage, $history->status_tracking['stage']);
            $this->assertSame($label, $history->status_tracking['label']);
            $this->assertSame($holder, $history->status_tracking['current_holder']);
            $this->assertSame($progress.'%', $history->progress_percentage);
            $this->assertSame($status, $history->status);
        }
        $newHistory = new PatientHistory;
        $this->assertSame('reviewed', $newHistory->status);
        $this->assertSame('20%', $newHistory->progress_percentage);
    }

    public function test_terminal_outcomes_remain_separate_and_legacy_is_not_a_stage(): void
    {
        $this->assertSame(100, PatientHistory::progressForStatus('boarded_out'));
        $this->assertSame(0, PatientHistory::progressForStatus('rejected'));
        $history = new PatientHistory(['status' => 'pending']);
        $this->assertTrue($history->status_tracking['is_legacy']);
        $this->assertSame('pending', $history->status);
        $this->assertArrayNotHasKey('pending', PatientHistory::STATUS_MAP);
    }

    public function test_chart_counts_balance_with_legacy_and_unknown_records_without_dividing_by_zero(): void
    {
        $summary = CaseReport::summarizeStatusCounts(['reviewed' => '2', 'approved' => 1, 'pending' => 3, 'unknown' => 2]);
        $this->assertSame(8, $summary['total']);
        $this->assertSame(3, $summary['tracked_total']);
        $this->assertSame(5, $summary['untracked_total']);
        $this->assertSame(6, $summary['under_review']);
        $this->assertSame(66.67, $summary['statuses'][0]['case_percentage']);
        $this->assertSame(25.0, $summary['statuses'][0]['total_case_percentage']);
        $this->assertSame(8, array_sum(array_column($summary['statuses'], 'count')) + array_sum(array_column($summary['untracked_statuses'], 'count')));
        $this->assertSame(0, CaseReport::summarizeStatusCounts([])['statuses'][0]['case_percentage']);
        $this->assertSame(0, CaseReport::summarizeStatusCounts(['pending' => 3])['tracked_total']);
    }
}
