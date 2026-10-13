<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

final class ActivityRequestInProgressException extends \RuntimeException {
    public function __construct(string $message, public readonly string $messageId) {
        parent::__construct($message, 409);
    }
}
