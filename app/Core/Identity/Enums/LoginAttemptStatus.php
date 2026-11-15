<?php

namespace App\Core\Identity\Enums;

enum LoginAttemptStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
