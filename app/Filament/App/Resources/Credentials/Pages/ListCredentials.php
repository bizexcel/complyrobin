<?php

namespace App\Filament\App\Resources\Credentials\Pages;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Limit;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Models\Credential;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCredentials extends ListRecords
{
    #[\Override]
    protected static string $resource = CredentialResource::class;

    public function getSubheading(): string
    {
        $entitlements = app(Entitlements::class);
        $used = Credential::query()->active()->count();
        $limit = $entitlements->limit(Limit::Credentials);

        return $limit === null
            ? "{$used} credentials · unlimited on {$entitlements->plan()->name}"
            : "{$used} of {$limit} credentials on {$entitlements->plan()->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
