<?php
declare(strict_types=1);

namespace OCA\Cognos\Service;

final class ActivityChatException extends \RuntimeException {
    public function __construct(string $message, public readonly string $messageId, int $code = 502) {
        parent::__construct($message, $code);
    }
}
