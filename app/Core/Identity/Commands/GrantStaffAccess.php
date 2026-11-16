<?php

namespace App\Core\Identity\Commands;

use App\Core\Identity\Actions\ChangeStaffRole;
use App\Core\Identity\Enums\PlatformRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Makes an existing account ComplyRobin staff, from the server console: how the first Admin is made after a fresh
 * install. Later changes are best made on the staff panel's Staff page, which records the Admin who made them.
 */
#[Signature('staff:grant {email : The account\'s email, exactly as they sign in with it} {--role=admin : admin or support}')]
#[Description('Make an existing account ComplyRobin staff (Admin by default)')]
class GrantStaffAccess extends Command
{
    public function handle(ChangeStaffRole $changeStaffRole): int
    {
        $role = PlatformRole::tryFrom((string) $this->option('role'));

        if (! $role instanceof PlatformRole) {
            $this->components->error('Choose --role=admin or --role=support.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        try {
            $changeStaffRole->handle(null, $email, $role);
        } catch (ValidationException $exception) {
            $this->components->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info("{$email} is now {$role->label()}. They can sign in at ".url('/admin').'.');

        return self::SUCCESS;
    }
}
