<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCaseLinks extends Command
{
    protected $signature = 'cases:audit-links {--limit=100 : Maximum unresolved referrals to display}';
    protected $description = 'List unresolved or conflicting referral case links without changing records';

    public function handle(): int
    {
        $query = DB::table('referrals as r')->leftJoin('patient_histories as ph', 'ph.patient_histories_id', '=', 'r.patient_histories_id')
            ->whereNull('r.deleted_at')->where(function ($q): void {
                $q->whereNull('r.patient_histories_id')->orWhereNull('ph.patient_histories_id')->orWhereColumn('r.patient_id', '<>', 'ph.patient_id');
            });
        $this->info('Referrals needing review: '.(clone $query)->count());
        $this->table(['Referral ID', 'Patient ID', 'Linked case ID'], $query->orderBy('r.referral_id')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get(['r.referral_id', 'r.patient_id', 'r.patient_histories_id'])->map(fn ($row) => (array) $row)->all());
        return self::SUCCESS;
    }
}
