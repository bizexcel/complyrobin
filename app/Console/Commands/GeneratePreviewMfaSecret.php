<?php

namespace App\Console\Commands;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A new authenticator secret for the preview demo account (PREVIEW_MFA_SECRET). Add it to your authenticator app by
 * hand ("enter a setup key"), then set it on the Laravel Cloud preview automation.
 */
#[Signature('preview:mfa-secret')]
#[Description('Generate an authenticator secret for the preview demo account')]
class GeneratePreviewMfaSecret extends Command
{
    public function handle(): int
    {
        $this->line(AppAuthentication::make()->generateSecret());

        return self::SUCCESS;
    }
}
