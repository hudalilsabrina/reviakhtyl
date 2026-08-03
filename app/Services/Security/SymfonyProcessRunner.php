<?php

namespace App\Services\Security;

use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command): string
    {
        $process = new Process($command);
        $process->run();

        return $process->getOutput();
    }
}
