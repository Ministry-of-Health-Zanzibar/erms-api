<?php

namespace App\Console\Commands;

use App\Services\TransferReferralService;
use Illuminate\Console\Command;

class RepairTransferLetters extends Command
{
    protected $signature = 'referrals:repair-transfer-letters {--apply : Save verified links and missing transfer letters}';
    protected $description = 'Audit transfer letters; only save conservative repairs when --apply is supplied';

    public function handle(TransferReferralService $transfers): int
    {
        $result = $transfers->repairLegacy($this->option('apply'));
        $this->info($this->option('apply') ? 'Verified repairs applied.' : 'Dry run: no records changed.');
        $this->table(['Missing letters', 'Missing safe follow-up links', 'Transfers needing review'], [[
            $result['letters_needed'], $result['links_needed'], count($result['needs_review']),
        ]]);
        if ($result['needs_review'] !== []) $this->warn('Review referral IDs: '.implode(', ', $result['needs_review']));
        return self::SUCCESS;
    }
}
