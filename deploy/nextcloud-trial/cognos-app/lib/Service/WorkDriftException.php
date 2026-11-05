<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
final class WorkDriftException extends \RuntimeException {
    public function __construct(public readonly array $drift) { parent::__construct('起步以后有关决定或依据有变化，本次没有修改工作；请先看差异再确认',409); }
}
