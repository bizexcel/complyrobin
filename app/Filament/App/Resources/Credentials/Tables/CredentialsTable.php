<?php

namespace App\Filament\App\Resources\Credentials\Tables;

use App\Filament\App\Actor;
use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Modules\Credentials\Actions\DeleteCredential;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Enums\Environment;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\RotationSchedule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CredentialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('automaticRotation'))
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('application.name')->label('Application')->searchable(),
                TextColumn::make('environment')->formatStateUsing(fn (Environment $state): string => $state->label()),
                TextColumn::make('owner.name')->label('Owner')->placeholder('No owner'),
                TextColumn::make('next_rotation_at')->label('Due')->date(timezone: CredentialPresenter::timezone())->sortable(),
                TextColumn::make('status')
                    ->state(fn (Credential $record): CredentialStatus => CredentialPresenter::status($record))
                    ->formatStateUsing(fn (CredentialStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (CredentialStatus $state): string => CredentialPresenter::colour($state)),
                IconColumn::make('automatic')
                    ->label('Automatic')
                    ->state(fn (Credential $record): bool => $record->automaticRotation instanceof AutomaticRotation)
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedBolt : null)
                    ->tooltip(fn (Credential $record): ?string => $record->automaticRotation instanceof AutomaticRotation ? 'Rotated by a runner' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('next_rotation_at')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        CredentialStatus::Overdue->value => 'Overdue',
                        CredentialStatus::DueSoon->value => 'Due soon',
                        CredentialStatus::Revoked->value => 'Revoked',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::whereStatus($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Credential $record): bool => $record->revoked_at === null),
                CredentialPresenter::recordRotationAction(),
                CredentialPresenter::revokeAction(),
                DeleteAction::make()->using(fn (Credential $record) => app(DeleteCredential::class)->handle(Actor::current(), $record)),
            ]);
    }

    /**
     * Filter on the organisation's own days, the same way the status badge is worked out.
     *
     * @param  Builder<Credential>  $query
     * @return Builder<Credential>
     */
    private static function whereStatus(Builder $query, ?string $status): Builder
    {
        $startOfToday = RotationSchedule::startOfDay(now(), CredentialPresenter::timezone());
        $dueSoonEnd = RotationSchedule::startOfDay(now(), CredentialPresenter::timezone(), (int) config('credentials.due_soon_days') + 1);

        return match ($status) {
            CredentialStatus::Overdue->value => $query->active()->where('next_rotation_at', '<', $startOfToday),
            CredentialStatus::DueSoon->value => $query->active()->where('next_rotation_at', '>=', $startOfToday)->where('next_rotation_at', '<', $dueSoonEnd),
            CredentialStatus::Revoked->value => $query->whereNotNull('revoked_at'),
            default => $query,
        };
    }
}
