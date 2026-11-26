<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 */
class PrintStation extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'name',
        'platform',
        'token_hash',
        'status',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @param Builder<PrintStation> $query */
    public function scopeOnline(Builder $query): void
    {
        $query->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds($this->onlineThresholdSeconds())->startOfSecond());
    }

    public function isOnline(): bool
    {
        return $this->status === 'active'
            && $this->revoked_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(
                now()->subSeconds($this->onlineThresholdSeconds())->startOfSecond(),
            );
    }

    private function onlineThresholdSeconds(): int
    {
        return max(1, (int) config('print_agent.online_threshold_seconds', 300));
    }

    /** @return HasMany<PrintJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
