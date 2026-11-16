<?php

namespace App\Core\Identity\Commands;

use App\Core\Identity\Actions\ChangeStaffRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Removes someone from ComplyRobin staff, from the server console. Their account and organisations are untouched.
 * The last Admin can't be removed.
 */
#[Signature('staff:revoke {email : The account\'s email, exactly as they sign in with it}')]
#[Description('Remove someone from ComplyRobin staff')]
class RevokeStaffAccess extends Command
{
    public function handle(ChangeStaffRole $changeStaffRole): int
    {
        $email = (string) $this->argument('email');

        try {
            $changeStaffRole->handle(null, $email, null);
        } catch (ValidationException $exception) {
            $this->components->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info("{$email} is no longer staff.");

        return self::SUCCESS;
    }
}
