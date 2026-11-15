<?php

namespace App\Core\Tasks\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
