<?php
declare(strict_types=1);
namespace OCA\Cognos\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Cognos\Service\FollowupDispatcher;
final class RecoverFollowups extends TimedJob {
 public function __construct(ITimeFactory $time,private FollowupDispatcher $dispatcher){parent::__construct($time);$this->setInterval(60);}
 protected function run($argument):void {$this->dispatcher->recover();}
}
