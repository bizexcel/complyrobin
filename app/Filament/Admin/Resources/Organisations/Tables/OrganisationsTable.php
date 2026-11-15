<?php

namespace App\Filament\Admin\Resources\Organisations\Tables;

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class OrganisationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('users_count')->label('Members')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('grantModule')
                    ->label('Grant module')
                    ->icon('heroicon-o-key')
                    ->visible(fn (Organisation $record): bool => $record->root_id === null)
                    ->schema([
                        Select::make('module')->options(collect(Module::cases())->mapWithKeys(fn (Module $module): array => [$module->value => $module->name]))->required(),
                        Select::make('plan')
                            ->options([Plan::Team->value => 'Team', Plan::Enterprise->value => 'Enterprise'])
                            ->default(Plan::Team->value)
                            ->helperText('Any module puts the organisation on Team. Enterprise applies with the credentials module.'),
                        DateTimePicker::make('starts_at')->default(now())->required(),
                        DateTimePicker::make('ends_at')->after('starts_at'),
                        Textarea::make('note')->maxLength(1000),
                    ])
                    ->action(function (Organisation $record, array $data): void {
                        $staff = Filament::auth()->user();
                        abort_unless($staff instanceof User, 403);

                        app(GrantModule::class)->handle(
                            $staff,
                            $record,
                            Module::from($data['module']),
                            Carbon::parse($data['starts_at']),
                            filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null,
                            $data['note'] ?? null,
                            $data['module'] === Module::Credentials->value && ($data['plan'] ?? null) === Plan::Enterprise->value ? Plan::Enterprise : null,
                        );

                        Notification::make()->title('Module granted')->success()->send();
                    }),
            ]);
    }
}
