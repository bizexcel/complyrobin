<?php

namespace App\Filament\App\Pages;

use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Files\Models\Media;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\GenerateRotationEvidenceReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The rotation evidence report for auditors: prepare one, and download the latest. Team, for members who export audit
 * evidence.
 */
class RotationEvidenceReports extends Page
{
    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Credentials';

    #[\Override]
    protected static ?int $navigationSort = 20;

    #[\Override]
    protected static ?string $navigationLabel = 'Evidence reports';

    #[\Override]
    protected static ?string $title = 'Rotation evidence reports';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->can(Ability::ExportAudit->value);
    }

    public function content(Schema $schema): Schema
    {
        $isAllowed = app(Entitlements::class)->allows(Feature::EvidenceReport);

        return $schema->components([
            Section::make('Proof of rotation for your auditors')
                ->description('A PDF and a CSV of every rotation in the period: when each was due, done and recorded, whether it was on time, and its evidence files, with the credentials overdue now as exceptions. Never the keys themselves.')
                ->schema([
                    Text::make('The rotation evidence report is part of Team. Upgrade to use it.')->visible(! $isAllowed),
                    Actions::make([$this->prepareAction()])->visible($isAllowed),
                ]),
            Section::make('Latest reports')->schema([
                Text::make(fn (): HtmlString|string => $this->reportLinks()),
            ]),
        ]);
    }

    public function prepareAction(): Action
    {
        return Action::make('prepare')
            ->label('Prepare a report')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->schema([
                Select::make('days')
                    ->label('Period')
                    ->options(collect(GenerateRotationEvidenceReport::PERIODS)->mapWithKeys(fn (int $days): array => [$days => "Last {$days} days"])->all())
                    ->default(90)
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(GenerateRotationEvidenceReport::class)->handle(Actor::current(), (int) $data['days']);
                    Notification::make()->title('Preparing your report. We\'ll let you know when it\'s ready.')->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    private function reportLinks(): HtmlString|string
    {
        $organisation = Filament::getTenant();
        $files = $organisation instanceof Organisation ? $organisation->getMedia('rotation-evidence')->sortByDesc('created_at') : collect();

        if ($files->isEmpty()) {
            return 'No reports yet.';
        }

        return new HtmlString($files->map(fn (Media $file): string => '<a href="'.e($file->getTemporaryUrl(now()->addMinutes(5))).'" class="underline">'.e($file->file_name).'</a>')->implode('<br>'));
    }
}
