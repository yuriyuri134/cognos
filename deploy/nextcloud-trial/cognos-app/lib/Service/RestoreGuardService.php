<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IConfig;

/** Local restore policy, deliberately outside the restored application's scheduling records. */
final class RestoreGuardService {
    public function __construct(private IConfig $config) {}
    public function isQuarantined(): bool {
        return (bool)$this->config->getSystemValue('cognos_restore_quarantine',false);
    }
    public function assertExecutionAllowed(): void {
        if($this->isQuarantined())throw new \RuntimeException('当前为恢复隔离副本：保留记录与结果回执，暂不启动或派发 AI 工作。',409);
    }
}
