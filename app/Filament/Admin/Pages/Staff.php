<?php

namespace App\Filament\Admin\Pages;

use App\Core\Identity\Actions\ChangeStaffRole;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Filament\Admin\Actor;
use App\Filament\Admin\Pages\Widgets\StaffChanges;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Validation\ValidationException;

/**
 * ComplyRobin's own staff: who can open this panel, and as what. Admins only. Every change goes through
 * ChangeStaffRole, which refuses a change to your own role and always keeps one Admin.
 */
class Staff extends Page implements HasTable
{
    use InteractsWithTable;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    #[\Override]
    protected static ?int $navigationSort = 30;

    #[\Override]
    protected static ?string $title = 'Staff';

    public static function canAccess(): bool
    {
        return Actor::isAdmin();
    }

    public function getSubheading(): string
    {
        return 'Admins can do everything here, including granting plans and managing staff. Support can look but not change.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->whereNotNull('platform_role'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('platform_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (PlatformRole $state): string => $state->label()),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->hidden(fn (User $record): bool => $record->is(Actor::current()))
                    ->fillForm(fn (User $record): array => ['role' => $record->platform_role?->value])
                    ->schema([$this->roleField()])
                    ->action(fn (User $record, array $data, Action $action) => $this->change($record->email, PlatformRole::from($data['role']), $action)),
                Action::make('removeStaff')
                    ->label('Remove from staff')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('They lose access to this panel on their next click. Their ComplyRobin account and organisations are not affected.')
                    ->hidden(fn (User $record): bool => $record->is(Actor::current()))
                    ->action(fn (User $record, Action $action) => $this->change($record->email, null, $action)),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addStaff')
                ->label('Add staff')
                ->icon(Heroicon::OutlinedUserPlus)
                ->modalDescription('They need a ComplyRobin account first, with a verified email and multi-factor authentication set up.')
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255)->helperText('Exactly as they sign in with it.'),
                    $this->roleField()->default(PlatformRole::Support->value),
                ])
                ->action(fn (array $data, Action $action) => $this->change((string) $data['email'], PlatformRole::from($data['role']), $action)),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [StaffChanges::class];
    }

    private function roleField(): Select
    {
        return Select::make('role')
            ->options(collect(PlatformRole::cases())->mapWithKeys(fn (PlatformRole $role): array => [$role->value => "{$role->label()}: {$role->description()}"])->all())
            ->required();
    }

    private function change(string $email, ?PlatformRole $role, Action $action): void
    {
        try {
            app(ChangeStaffRole::class)->handle(Actor::current(), $email, $role);
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($role instanceof PlatformRole ? "{$email} is now {$role->label()}." : "{$email} is no longer staff.")->success()->send();
    }
}
