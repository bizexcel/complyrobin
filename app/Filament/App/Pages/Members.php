<?php

namespace App\Filament\App\Pages;

use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Models\Role;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Limit;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Seats;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Actor;
use App\Filament\App\Pages\Widgets\OpenInvitations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Who belongs to the organisation and with which role, and the seats they take on the plan. Every member sees it;
 * inviting needs members.manage.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    #[\Override]
    protected static ?int $navigationSort = 1;

    #[\Override]
    protected static ?string $title = 'Members';

    public function getSubheading(): string
    {
        $organisation = Filament::getTenant();
        $entitlements = app(Entitlements::class);
        $limit = $entitlements->limit(Limit::Members);
        $taken = $organisation instanceof Organisation ? Seats::taken($organisation) : 0;

        return $limit === null
            ? "{$taken} members and open invitations · unlimited on {$entitlements->plan()->label()}"
            : "{$taken} of {$limit} ".Str::plural('seat', $limit)." on {$entitlements->plan()->label()}, counting open invitations";
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->whereHas('organisations', fn ($query) => $query->whereKey(Tenancy::id())))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles')
                    ->label('Role')
                    ->state(fn (User $record): string => $record->roles->pluck('name')->implode(', '))
                    ->badge(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite someone')
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn (): bool => Actor::current()->can(Ability::ManageMembers->value))
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255),
                    Select::make('role')
                        ->options(fn (): array => Role::query()
                            ->when(! Actor::current()->hasRole(SystemRole::Owner->value), fn ($query) => $query->where('name', '!=', SystemRole::Owner->value))
                            ->orderBy('name')->pluck('name', 'name')->all())
                        ->default(SystemRole::Editor->value)
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(InviteMember::class)->handle(Actor::current(), (string) $data['email'], (string) $data['role']);
                        Notification::make()->title("Invitation sent to {$data['email']}.")->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                        $action->halt();
                    }
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [OpenInvitations::class];
    }
}
