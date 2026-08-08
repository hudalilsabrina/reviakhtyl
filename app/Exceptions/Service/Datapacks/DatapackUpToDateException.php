<?php

namespace App\Exceptions\Service\Datapacks;

use App\Exceptions\DisplayException;

/**
 * Thrown when an update is requested for a datapack that is already on the
 * newest version compatible with the server.
 *
 * It extends DisplayException so the HTTP API keeps returning the same 400
 * with the same wording, but callers that treat "nothing to do" as an
 * ordinary outcome can catch this specific type instead of matching on the
 * message text.
 */
class DatapackUpToDateException extends DisplayException
{
    public function __construct(string $message = 'Datapack is already up to date.')
    {
        parent::__construct($message);
    }
}
