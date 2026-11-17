<?php

namespace App\Filament\Admin\Resources\Organisations\Tables;

use App\Billing\Subscription;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Scopes\OrganisationScope;
use App\Filament\Admin\Actor;
use Filament\Actions\Action;
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
            ->modifyQueryUsing(fn ($query) => $query->withCount('users')->with([
                // Staff read every organisation's subscriptions on the platform connection; the tenant scope would need one organisation.
                'subscriptions' => fn ($subscriptions) => $subscriptions->withoutGlobalScope(OrganisationScope::class),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('users_count')->label('Members')->sortable(),
                TextColumn::make('subscriptions')
                    ->label('Subscriptions')
                    ->state(fn (Organisation $record): array => $record->subscriptions->sortByDesc('created_at')->map(self::subscriptionStatus(...))->values()->all())
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_ends_with($state, 'active'), str_contains($state, 'trial') => 'success',
                        str_contains($state, 'overdue'), str_contains($state, 'unfinished') => 'danger',
                        str_contains($state, 'ends') => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('None'),
                TextColumn::make('airwallex_id')->label('Airwallex customer')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('grantModule')
                    ->label('Grant module')
                    ->icon('heroicon-o-key')
                    ->visible(fn (Organisation $record): bool => $record->root_id === null && Actor::isAdmin())
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
                        app(GrantModule::class)->handle(
                            Actor::current(),
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

    /**
     * A subscription in a few words for staff, e.g. "Credentials: on trial until 1 Dec 2026".
     */
    private static function subscriptionStatus(Subscription $subscription): string
    {
        $status = match (true) {
            $subscription->onGracePeriod() => 'ends '.$subscription->ends_at?->format('j M Y'),
            $subscription->ended() => 'ended',
            $subscription->onTrial() => 'on trial until '.$subscription->trial_ends_at?->format('j M Y'),
            $subscription->pastDue() => 'payment overdue',
            $subscription->incomplete() => 'checkout unfinished',
            default => 'active',
        };

        return ucfirst($subscription->type).": {$status}";
    }
}
