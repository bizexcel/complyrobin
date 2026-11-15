<?php

use App\Core\Identity\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class PingNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => 'Ping'];
    }
}

it('lets anyone send an in-app notification, but each user read only their own', function (): void {
    [$alice, $bob] = User::factory()->count(2)->create();
    $alice->notify(new PingNotification);
    $bob->notify(new PingNotification);

    $this->actingAs($alice);

    expect($alice->notifications()->count())->toBe(1)
        ->and(DB::table('notifications')->pluck('notifiable_id')->all())->toBe([$alice->id]);
});

it('stops a user marking someone else\'s notifications as read', function (): void {
    [$alice, $bob] = User::factory()->count(2)->create();
    $bob->notify(new PingNotification);

    $this->actingAs($alice);
    DB::table('notifications')->update(['read_at' => now()]);

    expect(DB::connection('pgsql_platform')->table('notifications')->whereNull('read_at')->count())->toBe(1);
});
