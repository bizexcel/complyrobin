<?php

namespace App\Filament\App\Widgets;

use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\RotationSchedule;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * What needs rotating next: overdue credentials and those due within the due-soon window, soonest first.
 */
class CredentialsDueSoon extends TableWidget
{
    #[\Override]
    protected static ?int $sort = 2;

    #[\Override]
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $organisation = Filament::getTenant();
        $timezone = $organisation instanceof Organisation ? $organisation->timezone : (string) config('app.timezone');
        $dueSoonEnd = RotationSchedule::startOfDay(now(), $timezone, (int) config('credentials.due_soon_days') + 1);

        return $table
            ->heading('Up for rotation')
            ->query(Credential::query()->active()->with(['application', 'owner'])->where('next_rotation_at', '<', $dueSoonEnd))
            ->defaultSort('next_rotation_at')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Nothing due in the next '.config('credentials.due_soon_days').' days')
            ->columns([
                TextColumn::make('reference'),
                TextColumn::make('name'),
                TextColumn::make('application.name')->label('Application'),
                TextColumn::make('owner.name')->label('Owner')->placeholder('No owner'),
                TextColumn::make('next_rotation_at')->label('Due')->date(timezone: $timezone),
                TextColumn::make('status')
                    ->state(fn (Credential $record): CredentialStatus => CredentialPresenter::status($record))
                    ->formatStateUsing(fn (CredentialStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (CredentialStatus $state): string => CredentialPresenter::colour($state)),
            ])
            ->recordUrl(fn (Credential $record): string => CredentialResource::getUrl('view', ['record' => $record]));
    }
}
