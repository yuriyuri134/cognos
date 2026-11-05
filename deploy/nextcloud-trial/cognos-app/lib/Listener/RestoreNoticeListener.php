<?php
declare(strict_types=1);
namespace OCA\Cognos\Listener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\{Event,IEventListener};
use OCA\Cognos\Service\RestoreGuardService;
use OCP\Util;
/** @implements IEventListener<BeforeTemplateRenderedEvent> */
final class RestoreNoticeListener implements IEventListener {
    public function __construct(private RestoreGuardService $guard) {}
    public function handle(Event $event):void {
        if($event instanceof BeforeTemplateRenderedEvent && $event->isLoggedIn() && $this->guard->isQuarantined())Util::addScript('cognos','restore-notice');
    }
}
