<?php

namespace App\Core\Enquiries\Actions;

use App\Core\Enquiries\Models\Enquiry;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkEnquiryHandled
{
    /**
     * Mark an enquiry as answered, with an optional note for the rest of the team. Platform Admins only.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $staff, Enquiry $enquiry, ?string $note = null): Enquiry
    {
        if ($staff->platform_role !== PlatformRole::Admin) {
            throw new AuthorizationException('Only a platform Admin can handle enquiries.');
        }

        if ($note !== null && mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['note' => 'Keep the note under 5,000 characters.']);
        }

        return Tenancy::bypass('mark an enquiry handled', fn (): Enquiry => DB::transaction(function () use ($staff, $enquiry, $note): Enquiry {
            $enquiry->forceFill([
                'handled_at' => now(),
                'handled_by_id' => $staff->getKey(),
                'note' => filled($note) ? trim($note) : null,
            ])->save();

            return $enquiry;
        }));
    }
}
