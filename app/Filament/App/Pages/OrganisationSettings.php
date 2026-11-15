<?php

namespace App\Filament\App\Pages;

use App\Core\Organisations\Actions\UpdateOrganisationProfile;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\UpdateReminderPolicy;
use App\Modules\Credentials\Support\ReminderPolicy;
use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The organisation's settings: its name, time zone and reminder schedule. Needs organisation.update.
 */
class OrganisationSettings extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Organisation settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisation')->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('timezone')
                    ->label('Time zone')
                    ->helperText('Decides your organisation\'s days: when reminders go out and when something is overdue.')
                    ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                    ->searchable()
                    ->required(),
            ]),
            Section::make('Rotation reminders')->schema([
                TagsInput::make('reminders_before')->label('Days before it\'s due')->placeholder('e.g. 14')->helperText('Up to six, from 1 to 90.'),
                TagsInput::make('reminders_overdue')->label('Days after it\'s due, while overdue')->placeholder('e.g. 3')->helperText('Up to six, from 0 (the due day) to 90. After the last one, a weekly reminder follows for up to 12 weeks.'),
                Select::make('reminders_send_hour')
                    ->label('Send from')
                    ->options(collect(range(0, 23))->mapWithKeys(fn (int $hour): array => [$hour => sprintf('%02d:00', $hour)])->all())
                    ->required(),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Organisation $organisation */
        $organisation = $this->tenant;
        $policy = ReminderPolicy::for($organisation);

        return [
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'reminders_before' => array_map(strval(...), $policy->before),
            'reminders_overdue' => array_map(strval(...), $policy->overdue),
            'reminders_send_hour' => $policy->sendHour,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Organisation, 404);
        $actor = Actor::current();

        return DB::transaction(function () use ($actor, $record, $data): Organisation {
            app(UpdateOrganisationProfile::class)->handle($actor, $record, ['name' => $data['name'], 'timezone' => $data['timezone']]);
            app(UpdateReminderPolicy::class)->handle($actor, $record, [
                'before' => array_values((array) ($data['reminders_before'] ?? [])),
                'overdue' => array_values((array) ($data['reminders_overdue'] ?? [])),
                'send_hour' => $data['reminders_send_hour'] ?? null,
            ]);

            return $record;
        });
    }
}
