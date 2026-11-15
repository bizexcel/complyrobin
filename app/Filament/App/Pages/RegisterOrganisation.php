<?php

namespace App\Filament\App\Pages;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use Filament\Facades\Filament;
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
        ]);
    }

    /**
     * @param  array{name: string}  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return app(CreateOrganisation::class)->handle($user, $data['name']);
    }
}
