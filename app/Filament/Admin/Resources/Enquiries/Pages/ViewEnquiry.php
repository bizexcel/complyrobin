<?php

namespace App\Filament\Admin\Resources\Enquiries\Pages;

use App\Core\Enquiries\Actions\MarkEnquiryHandled;
use App\Core\Enquiries\Actions\ReopenEnquiry;
use App\Core\Enquiries\Models\Enquiry;
use App\Filament\Admin\Actor;
use App\Filament\Admin\Resources\Enquiries\EnquiryResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewEnquiry extends ViewRecord
{
    #[\Override]
    protected static string $resource = EnquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply by email')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->url(fn (Enquiry $record): string => 'mailto:'.rawurlencode($record->email).'?subject='.rawurlencode('Your enquiry to ComplyRobin')),
            Action::make('markHandled')
                ->label('Mark handled')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (Enquiry $record): bool => $record->handled_at === null && Actor::isAdmin())
                ->fillForm(fn (Enquiry $record): array => ['note' => $record->note])
                ->schema([
                    Textarea::make('note')->maxLength(5000)->helperText('For the rest of the team: what you replied, or what happens next. Optional.'),
                ])
                ->action(function (Enquiry $record, array $data): void {
                    app(MarkEnquiryHandled::class)->handle(Actor::current(), $record, $data['note'] ?? null);

                    Notification::make()->title('Enquiry marked handled')->success()->send();
                }),
            Action::make('reopen')
                ->label('Reopen')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (Enquiry $record): bool => $record->handled_at !== null && Actor::isAdmin())
                ->action(function (Enquiry $record): void {
                    app(ReopenEnquiry::class)->handle(Actor::current(), $record);

                    Notification::make()->title('Enquiry reopened')->success()->send();
                }),
        ];
    }
}
