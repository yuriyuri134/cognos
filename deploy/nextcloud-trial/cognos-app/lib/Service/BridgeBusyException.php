<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
/**
 * 桥明确说正忙（HTTP 409）：这件没开工，排着等上一件做完。只有这一种该排队重试。
 * 别的 409（恢复隔离、归属变了、请求编号冲突……）不是它，不能靠异常码判断，用 ClaudeBridgeService::isBusy()。
 */
final class BridgeBusyException extends \RuntimeException {
    public function __construct(string $message) { parent::__construct($message, ClaudeBridgeService::BUSY); }
}
