<?php

namespace App\Filament\App\Resources\Credentials;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\RemoveAutomaticRotation;
use App\Modules\Credentials\Actions\RequestRotation;
use App\Modules\Credentials\Actions\SetAutomaticRotation;
use App\Modules\Credentials\Enums\RotationDestination;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Enums\RotationSource;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Models\Runner;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * The "Automatic rotation" part of a credential's page: what a runner does for it, and the actions to set it up.
 */
final class AutomaticRotationPresenter
{
    public static function section(): Section
    {
        return Section::make('Automatic rotation')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('automatic_rotation_off')
                    ->hiddenLabel()
                    ->state('Off. You record rotations yourself.')
                    ->visible(fn (Credential $record): bool => ! self::automaticRotation($record) instanceof AutomaticRotation),
                TextEntry::make('automatic_rotation_runner')
                    ->label('Runner')
                    ->state(fn (Credential $record): ?string => self::automaticRotation($record)?->runner?->name)
                    ->visible(fn (Credential $record): bool => self::automaticRotation($record) instanceof AutomaticRotation),
                TextEntry::make('automatic_rotation_source')
                    ->label('Makes the key')
                    ->state(fn (Credential $record): ?string => self::source(self::automaticRotation($record)))
                    ->visible(fn (Credential $record): bool => self::automaticRotation($record) instanceof AutomaticRotation),
                TextEntry::make('automatic_rotation_destinations')
                    ->label('Puts it in')
                    ->state(fn (Credential $record): array => self::destinations(self::automaticRotation($record)))
                    ->listWithLineBreaks()
                    ->visible(fn (Credential $record): bool => self::automaticRotation($record) instanceof AutomaticRotation),
                TextEntry::make('automatic_rotation_last_run')
                    ->label('Last run')
                    ->state(fn (Credential $record): string => self::lastRun($record))
                    ->visible(fn (Credential $record): bool => self::automaticRotation($record) instanceof AutomaticRotation),
            ]);
    }

    /**
     * @return list<Action>
     */
    public static function actions(): array
    {
        return [self::setUpAction(), self::rotateNowAction(), self::switchOffAction()];
    }

    public static function setUpAction(): Action
    {
        return Action::make('setUpAutomaticRotation')
            ->label(fn (Credential $record): string => self::automaticRotation($record) instanceof AutomaticRotation ? 'Change automatic rotation' : 'Set up automatic rotation')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->visible(fn (Credential $record): bool => $record->revoked_at === null
                && $record->rotation_days !== null
                && app(Entitlements::class)->allows(Feature::AutomaticRotation))
            ->authorize(fn (Credential $record): bool => Actor::current()->can('update', $record))
            ->modalDescription('ComplyRobin keeps only these ids. The runner, in your own account, makes the new key and puts it in place.')
            ->fillForm(fn (Credential $record): array => self::formData(self::automaticRotation($record)))
            ->schema([
                Select::make('runner_id')
                    ->label('Runner')
                    ->options(fn (): array => Runner::query()->whereNull('revoked_at')->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                Hidden::make('source')->default(RotationSource::OpenAi->value),
                TextInput::make('source_settings.project_id')->label('OpenAI project ID')->placeholder('proj_…')->required()->maxLength(100),
                TextInput::make('source_settings.service_account_id')
                    ->label('OpenAI service account ID')
                    ->helperText('Use a service account that exists only for this credential. The runner retires every other key it has.')
                    ->required()
                    ->maxLength(100),
                TextInput::make('source_settings.key_lifetime_days')
                    ->label('New keys last')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(365)
                    ->suffix('days')
                    ->required(fn (Credential $record): bool => $record->expires_at !== null),
                Repeater::make('destinations')
                    ->label('Where the key is used')
                    ->addActionLabel('Add another place')
                    ->minItems(1)
                    ->maxItems(5)
                    ->defaultItems(1)
                    ->schema([
                        Hidden::make('type')->default(RotationDestination::LaravelCloud->value),
                        TextInput::make('secret_id')->label('Laravel Cloud secret ID')->required()->maxLength(100),
                        TextInput::make('secret_name')->label('Secret name')->placeholder('OPENAI_API_KEY')->required()->maxLength(100),
                        TagsInput::make('environment_ids')->label('Environment IDs')->helperText('The environments this secret is attached to.'),
                        Toggle::make('redeploy')->label('Redeploy after the change')->default(true),
                    ]),
            ])
            ->action(fn (Credential $record, array $data) => app(SetAutomaticRotation::class)->handle(Actor::current(), $record, $data));
    }

    public static function rotateNowAction(): Action
    {
        return Action::make('rotateNow')
            ->label('Rotate now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('The runner will swap this key the next time it checks in.')
            ->visible(fn (Credential $record): bool => $record->revoked_at === null && self::automaticRotation($record) instanceof AutomaticRotation)
            ->authorize(fn (Credential $record): bool => Actor::current()->can('rotate', $record))
            ->action(fn (Credential $record) => app(RequestRotation::class)->handle(Actor::current(), $record));
    }

    public static function switchOffAction(): Action
    {
        return Action::make('switchOffAutomaticRotation')
            ->label('Switch off automatic rotation')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The runner stops rotating this credential. You record its rotations yourself again.')
            ->visible(fn (Credential $record): bool => self::automaticRotation($record) instanceof AutomaticRotation)
            ->authorize(fn (Credential $record): bool => Actor::current()->can('update', $record))
            ->action(fn (Credential $record) => app(RemoveAutomaticRotation::class)->handle(Actor::current(), $record));
    }

    private static function automaticRotation(Credential $credential): ?AutomaticRotation
    {
        return $credential->automaticRotation()->with('runner')->first();
    }

    private static function source(?AutomaticRotation $automaticRotation): ?string
    {
        return $automaticRotation instanceof AutomaticRotation
            ? $automaticRotation->source->label().' · project '.$automaticRotation->source_settings['project_id']
            : null;
    }

    /**
     * @return list<string>
     */
    private static function destinations(?AutomaticRotation $automaticRotation): array
    {
        return array_map(function (array $destination): string {
            $line = (RotationDestination::tryFrom($destination['type'])?->label() ?? $destination['type']).' · '.$destination['secret_name'];
            $environments = count($destination['environment_ids'] ?? []);

            return $destination['redeploy'] && $environments > 0
                ? $line.' · redeploys '.$environments.' '.($environments === 1 ? 'environment' : 'environments')
                : $line;
        }, $automaticRotation->destinations ?? []);
    }

    private static function lastRun(Credential $credential): string
    {
        $run = RotationRun::query()->where('credential_id', $credential->getKey())->latest('claimed_at')->first();
        $timezone = CredentialPresenter::timezone();

        return match (true) {
            ! $run instanceof RotationRun => 'Not run yet',
            $run->status === RotationRunStatus::Claimed => 'Running now',
            $run->status === RotationRunStatus::Succeeded => 'Succeeded '.$run->finished_at?->timezone($timezone)->format('j M Y'),
            default => 'Failed '.$run->finished_at?->timezone($timezone)->format('j M Y').': '.$run->failure,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(?AutomaticRotation $automaticRotation): array
    {
        if (! $automaticRotation instanceof AutomaticRotation) {
            return ['source' => RotationSource::OpenAi->value, 'destinations' => [['type' => RotationDestination::LaravelCloud->value, 'redeploy' => true]]];
        }

        return [
            'runner_id' => $automaticRotation->runner_id,
            'source' => $automaticRotation->source->value,
            'source_settings' => $automaticRotation->source_settings,
            'destinations' => $automaticRotation->destinations,
        ];
    }
}
