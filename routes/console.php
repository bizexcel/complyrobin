<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('audit:prune')->dailyAt('03:17')->onOneServer();

Schedule::command('credentials:send-reminders')->hourlyAt(5)->onOneServer()->withoutOverlapping();
