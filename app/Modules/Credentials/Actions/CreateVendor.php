<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Vendor;
use App\Modules\Credentials\VendorRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CreateVendor
{
    /**
     * Add a vendor to the global catalogue, for every organisation to pick. Platform Admins only.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $staff, array $data): Vendor
    {
        if ($staff->platform_role !== PlatformRole::Admin) {
            throw new AuthorizationException('Only a platform Admin can change the vendor catalogue.');
        }

        return Tenancy::bypass('add a vendor to the catalogue', function () use ($data): Vendor {
            $validated = VendorRules::validate($data);

            return DB::transaction(fn (): Vendor => Vendor::query()->create($validated));
        });
    }
}
