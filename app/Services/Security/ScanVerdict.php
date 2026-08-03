<?php

namespace App\Services\Security;

enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
    case Error = 'error';
}
