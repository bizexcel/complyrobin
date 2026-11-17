<?php

namespace App\Filament\App\Pages;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Support\Countries;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a signed-in user without an organisation creates one. The slug is generated from the name.
 */
class RegisterOrganisation extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create your organisation';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Organisation name')
                ->required()
                ->maxLength(255),
            Select::make('country')
                ->options(Countries::all())
                ->default(Countries::MALAYSIA)
                ->helperText('Decides the currency you pay in if you upgrade: ringgit in Malaysia, US dollars elsewhere.')
                ->searchable()
                ->required(),
        ]);
    }

    /**
     * @param  array{name: string, country: string}  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return app(CreateOrganisation::class)->handle($user, $data['name'], $data['country']);
    }
}
