<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\PrintStation;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PairPrintStation extends Command
{
    protected $signature = 'print:pair-station
        {--store=MAIN : Store code}
        {--branch= : Branch code or ID}
        {--name=Cashier : Station display name}
        {--platform=linux : Agent platform}';

    protected $description = 'Create a Print Agent station and print its one-time token';

    public function handle(): int
    {
        $store = Store::query()->where('code', $this->stringOption('store'))->firstOrFail();
        $branchQuery = Branch::query()->where('store_id', $store->id);
        $branchCode = $this->option('branch');

        $branch = $branchCode
            ? $branchQuery->where(fn ($query) => $query
                ->where('code', $branchCode)
                ->orWhere('id', is_numeric($branchCode) ? (int) $branchCode : 0))
                ->firstOrFail()
            : $branchQuery->where('is_default', true)->firstOrFail();
        $token = Str::random(64);
        PrintStation::query()->create([
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'name' => $this->stringOption('name'),
            'platform' => $this->stringOption('platform'),
            'token_hash' => hash('sha256', $token),
            'status' => 'active',
        ]);
        $this->info('Print station created.');
        $this->line('Token (shown once): '.$token);

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
