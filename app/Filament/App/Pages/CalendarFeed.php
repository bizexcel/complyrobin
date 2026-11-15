<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\CreateCalendarFeed;
use App\Modules\Credentials\Actions\RevokeCalendarFeed;
use App\Modules\Credentials\Models\CalendarFeed as CalendarFeedModel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * A member's own private calendar link of rotation due dates, for Google Calendar, Outlook or Apple Calendar.
 */
class CalendarFeed extends Page
{
    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Credentials';

    #[\Override]
    protected static ?int $navigationSort = 10;

    #[\Override]
    protected static ?string $title = 'Calendar feed';

    /**
     * The link, right after it is made. Only a hash of it is stored, so it is shown just this once.
     */
    public ?string $newLink = null;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rotation due dates in your calendar')
                ->description('Subscribe to your private link in Google Calendar ("From URL"), Outlook ("Subscribe from web") or Apple Calendar ("New Calendar Subscription"). Members who manage credentials see every credential; everyone else sees the ones they own or back up.')
                ->schema([
                    Text::make('Copy your link now. For your security it is shown only once; anyone with it can see these due dates.')
                        ->visible(fn (): bool => $this->newLink !== null),
                    Text::make(fn (): string => (string) $this->newLink)
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->visible(fn (): bool => $this->newLink !== null),
                    Text::make(fn (): string => $this->status()),
                    Actions::make([$this->createLinkAction(), $this->revokeLinkAction()]),
                ]),
        ]);
    }

    public function createLinkAction(): Action
    {
        return Action::make('createLink')
            ->label(fn (): string => $this->feed() instanceof CalendarFeedModel ? 'Make a new link' : 'Make my link')
            ->requiresConfirmation(fn (): bool => $this->feed() instanceof CalendarFeedModel)
            ->modalDescription('Your current link stops working. Calendars subscribed to it need the new one.')
            ->action(function (): void {
                $this->newLink = app(CreateCalendarFeed::class)->handle(Actor::current());
            });
    }

    public function revokeLinkAction(): Action
    {
        return Action::make('revokeLink')
            ->label('Switch off my link')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Calendars subscribed to your link stop updating.')
            ->visible(fn (): bool => $this->feed() instanceof CalendarFeedModel)
            ->action(function (): void {
                app(RevokeCalendarFeed::class)->handle(Actor::current());
                $this->newLink = null;
            });
    }

    private function feed(): ?CalendarFeedModel
    {
        return CalendarFeedModel::query()->where('user_id', Actor::current()->getKey())->first();
    }

    private function status(): string
    {
        $feed = $this->feed();

        return match (true) {
            ! $feed instanceof CalendarFeedModel => 'You have no calendar link yet.',
            $feed->last_used_at === null => 'Your link hasn\'t been used by a calendar yet.',
            default => 'A calendar last fetched your link '.$feed->last_used_at->diffForHumans().'.',
        };
    }
}
