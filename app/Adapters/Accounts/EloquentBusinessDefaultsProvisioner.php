<?php

namespace App\Adapters\Accounts;

use App\Domains\Accounts\Contracts\CompanyDefaultsProvisioner;
use App\Domains\Accounts\Models\Company;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Receivables\Models\PaymentMethod;

class EloquentBusinessDefaultsProvisioner implements CompanyDefaultsProvisioner
{
    public function provision(Company $company): void
    {
        foreach (['Cash', 'Check', 'Credit Card', 'Bank Transfer'] as $name) {
            PaymentMethod::create([
                'name' => $name,
                'company_id' => $company->id,
            ]);
        }

        // No Unit Code is set here: default units take the model's C62 (piece)
        // default, exactly like units an existing install already had.
        foreach (['box', 'cm', 'dz', 'ft', 'g', 'in', 'kg', 'km', 'lb', 'mg', 'pc'] as $name) {
            Unit::create([
                'name' => $name,
                'company_id' => $company->id,
            ]);
        }
    }
}
