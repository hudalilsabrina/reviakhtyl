<?php

namespace App\Exceptions\Service\Plugins;

use App\Exceptions\DisplayException;

/**
 * Thrown when an update is requested for a plugin that is already on the
 * newest version compatible with the server.
 *
 * It extends DisplayException so the HTTP API keeps returning the same 400
 * with the same wording, but callers that treat "nothing to do" as an
 * ordinary outcome can catch this specific type instead of matching on the
 * message text.
 */
class PluginUpToDateException extends DisplayException
{
    public function __construct(string $message = 'Plugin is already up to date.')
    {
        parent::__construct($message);
    }
}
