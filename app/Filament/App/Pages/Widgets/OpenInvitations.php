<?php

namespace App\Filament\App\Pages\Widgets;

use App\Core\Access\Enums\Ability;
use App\Core\Organisations\Actions\RevokeInvitation;
use App\Core\Organisations\Models\Invitation;
use App\Filament\App\Actor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Invitations not yet accepted, on the Members page. Each holds a seat until it expires or is revoked.
 */
class OpenInvitations extends TableWidget
{
    #[\Override]
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Open invitations')
            ->query(Invitation::query()->with('invitedBy')->whereNull('accepted_at')->where('expires_at', '>', now()))
            ->defaultSort('expires_at')
            ->emptyStateHeading('No open invitations')
            ->columns([
                TextColumn::make('email'),
                TextColumn::make('role')->badge(),
                TextColumn::make('invitedBy.name')->label('Invited by'),
                TextColumn::make('expires_at')->label('Expires')->since(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The link stops working and the seat is free again.')
                    ->visible(fn (): bool => Actor::current()->can(Ability::ManageMembers->value))
                    ->action(function (Invitation $record): void {
                        app(RevokeInvitation::class)->handle(Actor::current(), $record);
                        Notification::make()->title("Invitation to {$record->email} revoked.")->success()->send();
                    }),
            ]);
    }
}
