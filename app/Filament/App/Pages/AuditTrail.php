<?php

namespace App\Filament\App\Pages;

use App\Core\Access\Enums\Ability;
use App\Core\Audit\Actions\ExportAuditTrail;
use App\Core\Audit\AuditTrail as Trail;
use App\Core\Audit\Models\Activity;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Who changed what in the organisation. For members who export audit evidence; exporting it as CSV is Team.
 */
class AuditTrail extends Page implements HasTable
{
    use InteractsWithTable;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    #[\Override]
    protected static ?int $navigationSort = 90;

    #[\Override]
    protected static ?string $title = 'Audit trail';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->can(Ability::ExportAudit->value);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $organisation = Filament::getTenant();
        $timezone = $organisation instanceof Organisation ? $organisation->timezone : (string) config('app.timezone');

        return $table
            ->query(Activity::query()->with(['causer', 'subject']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(timezone: $timezone)->sortable(),
                TextColumn::make('who')->state(fn (Activity $record): string => Trail::who($record)),
                TextColumn::make('event')->badge(),
                TextColumn::make('record')->state(fn (Activity $record): string => trim(Trail::recordType($record).' '.Trail::record($record))),
                TextColumn::make('changes')->state(fn (Activity $record): string => Trail::changes($record))->limit(120)->wrap(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    Select::make('days')
                        ->label('Period')
                        ->options(collect(ExportAuditTrail::PERIODS)->mapWithKeys(fn (int $days): array => [$days => "Last {$days} days"])->all())
                        ->default(90)
                        ->required(),
                ])
                ->action(function (array $data): ?StreamedResponse {
                    try {
                        return app(ExportAuditTrail::class)->handle(Actor::current(), (int) $data['days']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                        return null;
                    }
                }),
        ];
    }
}
