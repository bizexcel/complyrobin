<?php

namespace App\Modules\Credentials\Enums;

/**
 * How a runner's attempt to rotate a credential went.
 */
enum RotationRunStatus: string
{
    /** Handed to the runner, which hasn't reported yet. */
    case Claimed = 'claimed';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The runner didn't report back in time. */
    case Abandoned = 'abandoned';
}
