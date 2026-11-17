<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Vendor;
use App\Modules\Credentials\VendorRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateVendor
{
    /**
     * Edit a catalogue vendor's name, category, links and suggested interval. Platform Admins only. The slug never
     * changes, and credentials already in the register keep their own rotation interval.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $staff, Vendor $vendor, array $data): Vendor
    {
        if ($staff->platform_role !== PlatformRole::Admin) {
            throw new AuthorizationException('Only a platform Admin can change the vendor catalogue.');
        }

        return Tenancy::bypass('edit a vendor in the catalogue', function () use ($vendor, $data): Vendor {
            $validated = VendorRules::validate(Arr::except($data, ['slug']), $vendor);

            return DB::transaction(function () use ($vendor, $validated): Vendor {
                $vendor->update($validated);

                return $vendor;
            });
        });
    }
}
