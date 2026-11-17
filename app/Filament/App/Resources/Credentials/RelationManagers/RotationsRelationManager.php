<?php

namespace App\Filament\App\Resources\Credentials\RelationManagers;

use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Models\RotationRun;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The credential's rotation history. Read-only: rotations are recorded with the "Record rotation" action and never
 * changed afterwards.
 */
class RotationsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'rotations';

    #[\Override]
    protected static ?string $title = 'Rotation history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['rotatedBy', 'run']))
            ->columns([
                TextColumn::make('rotated_at')->label('Rotated')->dateTime(timezone: CredentialPresenter::timezone())->sortable(),
                TextColumn::make('due_at')->label('Was due')->date(timezone: CredentialPresenter::timezone()),
                TextColumn::make('on_time')
                    ->label('')
                    ->state(fn (CredentialRotation $record): string => $record->isOnTime(CredentialPresenter::timezone()) ? 'On time' : 'Late')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'On time' ? 'success' : 'warning'),
                TextColumn::make('rotated_by')
                    ->label('By')
                    ->state(fn (CredentialRotation $record): ?string => $record->run instanceof RotationRun ? 'Automatic rotation' : $record->rotatedBy?->name),
                TextColumn::make('notes')->limit(80)->wrap(),
                TextColumn::make('evidence')
                    ->state(fn (CredentialRotation $record): ?HtmlString => $this->evidenceLinks($record))
                    ->html()
                    ->placeholder('—'),
            ])
            ->defaultSort('rotated_at', 'desc');
    }

    /**
     * Download links that work for five minutes.
     */
    private function evidenceLinks(CredentialRotation $rotation): ?HtmlString
    {
        $links = $rotation->getMedia('evidence')->map(fn (Media $file): string => '<a href="'.e($file->getTemporaryUrl(now()->addMinutes(5))).'" class="underline">'.e($file->file_name).'</a>');

        return $links->isEmpty() ? null : new HtmlString($links->implode('<br>'));
    }
}
