<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    public ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
