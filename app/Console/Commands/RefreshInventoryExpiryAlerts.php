<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryExpiryAlertService;
use Illuminate\Console\Command;

class RefreshInventoryExpiryAlerts extends Command
{
    protected $signature = 'inventory:refresh-expiry-alerts';

    protected $description = 'Create or resolve Batch expiry alerts from current inventory';

    public function handle(InventoryExpiryAlertService $alerts): int
    {
        $created = $alerts->refresh();
        $this->info("{$created} new expiry alert(s) created.");

        return self::SUCCESS;
    }
}
