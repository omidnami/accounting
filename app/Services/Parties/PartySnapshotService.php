<?php

namespace App\Services\Parties;

use App\Enums\PartyEntityType;
use App\Models\Party;
use DomainException;

class PartySnapshotService
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array{
     *     schema_version: int,
     *     party_id: int,
     *     party_version: int,
     *     store_id: int,
     *     code: string,
     *     entity_type: string,
     *     name: string,
     *     phone: string|null,
     *     email: string|null,
     *     national_id: string|null,
     *     tax_id: string|null,
     *     address: string|null
     * }
     */
    public function capture(Party $party): array
    {
        $entityType = $party->getAttribute('entity_type');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'party_id' => (int) $party->id,
            'party_version' => (int) $party->version,
            'store_id' => (int) $party->store_id,
            'code' => (string) $party->code,
            'entity_type' => $entityType instanceof PartyEntityType
                ? $entityType->value
                : PartyEntityType::Individual->value,
            'name' => (string) $party->name,
            'phone' => $party->phone,
            'email' => $party->email,
            'national_id' => $party->national_id,
            'tax_id' => $party->tax_id,
            'address' => $party->address,
        ];
    }

    /**
     * Legacy documents have no snapshot and explicitly fall back to the current
     * party identity instead of fabricating a historical capture timestamp.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    public function resolve(?array $snapshot, ?Party $party): array
    {
        if ($snapshot !== null) {
            return $snapshot;
        }

        if ($party === null) {
            throw new DomainException('هویت طرف‌حساب سند در دسترس نیست.');
        }

        return [...$this->capture($party), 'legacy_current' => true];
    }

    /**
     * @param  array{id: int, code: string, name: string}  $fallback
     * @return array<string, mixed>
     */
    public function fromDatabase(mixed $snapshot, array $fallback): array
    {
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [...$fallback, 'legacy_current' => true];
        }

        return is_array($snapshot) ? $snapshot : [...$fallback, 'legacy_current' => true];
    }
}
