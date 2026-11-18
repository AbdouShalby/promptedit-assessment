<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * Any failed call to Pixelforge. Callers branch on $errorCode (the API's
 * machine-readable `error.code`), never on the message text.
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    /**
     * True when we cannot know whether Pixelforge acted on the request:
     * no response at all (timeout, connection error) or a 5xx that is not the
     * documented "overloaded" (which guarantees nothing was created).
     */
    public function isAmbiguous(): bool
    {
        if ($this->httpStatus === null) {
            return true;
        }

        return $this->httpStatus >= 500 && $this->errorCode !== 'overloaded';
    }
}
