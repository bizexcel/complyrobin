<?php

namespace App\Filament\App\Pages;

use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\CreateRunner;
use App\Modules\Credentials\Actions\RevokeRunner;
use App\Modules\Credentials\Models\Runner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The organisation's rotation runners: programs it runs itself, which swap keys and report back. A runner's token is
 * shown once, when it is added.
 */
class RotationRunners extends Page implements HasTable
{
    use InteractsWithTable;

    /** A runner counts as silent after this many hours without checking in. */
    public const int SILENT_AFTER_HOURS = 48;

    /** A runner that has never checked in counts as silent this many hours after it was added. */
    public const int NEVER_SEEN_SILENT_AFTER_HOURS = 24;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Credentials';

    #[\Override]
    protected static ?int $navigationSort = 15;

    #[\Override]
    protected static ?string $title = 'Rotation runners';

    /**
     * The token of the runner just added. Only a hash of it is stored, so it is shown just this once.
     */
    public ?string $newToken = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->can(Ability::ManageCredentials->value);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rotate keys automatically')
                ->description('A rotation runner is a small program you run in your own GitHub account or Laravel app. It swaps keys when they are due and tells ComplyRobin when it is done. Your keys and admin tokens stay with you: ComplyRobin never sees them.')
                ->schema([
                    Text::make('Automatic rotation is part of the Team plan.')->visible(fn (): bool => ! $this->isAllowed()),
                    Text::make('Copy this token now. For your security it is shown only once.')->visible(fn (): bool => $this->newToken !== null),
                    Text::make(fn (): string => (string) $this->newToken)
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->visible(fn (): bool => $this->newToken !== null),
                    Text::make(fn (): string => 'Organisation: '.$this->organisationSlug())
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->visible(fn (): bool => $this->newToken !== null),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Runner::query())
            ->defaultSort('name')
            ->emptyStateHeading('No runners yet')
            ->emptyStateDescription('Add a runner, then give its token to the program in your GitHub account or Laravel app.')
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Last checked in')
                    ->formatStateUsing(fn (Runner $record): string => $record->last_seen_at?->diffForHumans() ?? 'Never')
                    ->placeholder('Never'),
                TextColumn::make('status')
                    ->state(fn (Runner $record): string => self::status($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Silent' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This runner stops working straight away. Credentials it rotates will become overdue until you move them to another runner.')
                    ->visible(fn (Runner $record): bool => $record->revoked_at === null)
                    ->action(fn (Runner $record) => app(RevokeRunner::class)->handle(Actor::current(), $record)),
            ]);
    }

    public static function status(Runner $runner): string
    {
        return match (true) {
            $runner->revoked_at !== null => 'Revoked',
            $runner->last_seen_at !== null => $runner->last_seen_at->lt(now()->subHours(self::SILENT_AFTER_HOURS)) ? 'Silent' : 'Active',
            $runner->created_at?->lt(now()->subHours(self::NEVER_SEEN_SILENT_AFTER_HOURS)) === true => 'Silent',
            default => 'Active',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addRunner')
                ->label('Add runner')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->isAllowed())
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(80)
                        ->helperText('Where it runs, e.g. "GitHub: acme/api".'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $this->newToken = app(CreateRunner::class)->handle(Actor::current(), ['name' => $data['name'] ?? null])['token'];
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                        $action->halt();
                    }
                }),
        ];
    }

    private function isAllowed(): bool
    {
        return app(Entitlements::class)->allows(Feature::AutomaticRotation);
    }

    private function organisationSlug(): string
    {
        $organisation = Filament::getTenant();

        return $organisation instanceof Organisation ? $organisation->slug : '';
    }
}
