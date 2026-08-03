<?php

namespace App\Services\Security;

interface ProcessRunner
{
    /**
     * Run $command and return its stdout output as a string.
     */
    public function run(array $command): string;
}
