<?php

namespace App\Core\Entitlements\Enums;

/**
 * The parts of ComplyRobin an organisation can be entitled to.
 */
enum Module: string
{
    case Credentials = 'credentials';
    case Bcms = 'bcms';
    case Isms = 'isms';
}
