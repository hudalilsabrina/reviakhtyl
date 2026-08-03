<?php

namespace App\Exceptions\Service\Datapacks;

use App\Exceptions\DisplayException;

/**
 * Thrown when an update is requested for a datapack that is already on the newest
 * version compatible with the server.
 */
class DatapackUpToDateException extends DisplayException
{
    public function __construct(string $message = 'Datapack is already up to date.')
    {
        parent::__construct($message);
    }
}
