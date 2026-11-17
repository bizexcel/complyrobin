<?php

namespace App\Core\Enquiries\Actions;

use App\Core\Enquiries\Models\Enquiry;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ReopenEnquiry
{
    /**
     * Put a handled enquiry back on the open list. The note is kept. Platform Admins only.
     *
     * @throws AuthorizationException
     */
    public function handle(User $staff, Enquiry $enquiry): Enquiry
    {
        if ($staff->platform_role !== PlatformRole::Admin) {
            throw new AuthorizationException('Only a platform Admin can handle enquiries.');
        }

        return Tenancy::bypass('reopen an enquiry', fn (): Enquiry => DB::transaction(function () use ($enquiry): Enquiry {
            $enquiry->forceFill(['handled_at' => null, 'handled_by_id' => null])->save();

            return $enquiry;
        }));
    }
}
