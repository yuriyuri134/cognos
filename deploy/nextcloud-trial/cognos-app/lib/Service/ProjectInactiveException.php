<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
/** Distinguish paused-project conflicts from a busy bridge (both HTTP 409). */
final class ProjectInactiveException extends \RuntimeException {
    public function __construct(string $message) { parent::__construct($message,409); }
}
