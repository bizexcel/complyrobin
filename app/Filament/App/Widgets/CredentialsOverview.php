<?php

namespace App\Filament\App\Widgets;

use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Support\RegisterStatistics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The register at a glance on the dashboard. Every member sees it.
 */
class CredentialsOverview extends StatsOverviewWidget
{
    #[\Override]
    protected static ?int $sort = 1;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $organisation = Filament::getTenant();

        if (! $organisation instanceof Organisation) {
            return [];
        }

        $statistics = RegisterStatistics::for($organisation, now());
        $listUrl = fn (CredentialStatus $status): string => CredentialResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);

        return [
            Stat::make('Overdue', $statistics->overdue)
                ->description('Past their rotation date')
                ->color($statistics->overdue > 0 ? 'danger' : 'success')
                ->url($listUrl(CredentialStatus::Overdue)),
            Stat::make('Due in '.config('credentials.due_soon_days').' days', $statistics->dueSoon)
                ->description('Coming up for rotation')
                ->color($statistics->dueSoon > 0 ? 'warning' : 'success')
                ->url($listUrl(CredentialStatus::DueSoon)),
            Stat::make('Rotated on time', $statistics->onTimePercent === null ? '–' : "{$statistics->onTimePercent}%")
                ->description($statistics->rotations === 0
                    ? 'No rotations in the last '.RegisterStatistics::ON_TIME_WINDOW_DAYS.' days'
                    : trans_choice(':count rotation in the last :days days|:count rotations in the last :days days', $statistics->rotations, ['days' => RegisterStatistics::ON_TIME_WINDOW_DAYS])),
            Stat::make('No owner to remind', $statistics->withoutReachableOwner)
                ->description('Their reminders go to your Owners and Admins')
                ->color($statistics->withoutReachableOwner > 0 ? 'warning' : 'success'),
        ];
    }
}
