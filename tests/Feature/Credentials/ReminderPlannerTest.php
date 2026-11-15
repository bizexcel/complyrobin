<?php

use App\Modules\Credentials\Enums\ReminderKind;
use App\Modules\Credentials\Support\ReminderPlanner;
use App\Modules\Credentials\Support\ReminderPolicy;
use App\Modules\Credentials\Support\ReminderStep;

function defaultPolicy(array $overdue = [0, 3, 7]): ReminderPolicy
{
    return new ReminderPolicy(before: [14, 7, 1], overdue: $overdue, sendHour: 9, sendWindowHours: 9, repeatWeeklyForWeeks: 12);
}

it('picks the latest step already reached', function (int $daysUntilDue, ?string $expected): void {
    $step = ReminderPlanner::currentStep($daysUntilDue, defaultPolicy());

    expect($step instanceof ReminderStep ? "{$step->kind->value} {$step->offsetDays}" : null)->toBe($expected);
})->with([
    'too early' => [20, null],
    '14 days before' => [14, 'upcoming 14'],
    'between 14 and 7' => [10, 'upcoming 14'],
    '7 days before' => [7, 'upcoming 7'],
    'between 7 and 1' => [2, 'upcoming 7'],
    'the day before' => [1, 'upcoming 1'],
    'the due day' => [0, 'overdue 0'],
    'a day late' => [-1, 'overdue 0'],
    '3 days late' => [-3, 'overdue 3'],
    '7 days late' => [-7, 'overdue 7'],
    '13 days late' => [-13, 'overdue 7'],
    'a week after the last step' => [-14, 'overdue 14'],
    'two weeks after' => [-21, 'overdue 21'],
    'twelve weeks after' => [-91, 'overdue 91'],
    'long after: no more than 12 weekly repeats' => [-300, 'overdue 91'],
]);

it('keeps the last upcoming step on the due day when no overdue step starts that day', function (): void {
    $step = ReminderPlanner::currentStep(0, defaultPolicy(overdue: [3]));

    expect($step->kind)->toBe(ReminderKind::Upcoming)->and($step->offsetDays)->toBe(1);
});
