<?php
declare(strict_types=1);
namespace OCA\Cognos\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Util;

/** @implements IEventListener<BeforeTemplateRenderedEvent> */
class TaskFeedbackListener implements IEventListener {
    public function __construct(private IRequest $request) {}
    public function handle(Event $event): void {
        if ($event instanceof BeforeTemplateRenderedEvent
            && $event->isLoggedIn() && ($event->getResponse()->getApp() === 'deck' || $event->getResponse()->getApp() === 'cognos')) {
            Util::addScript('cognos', 'task-events');
            Util::addScript('cognos', 'board-activity');
            Util::addScript('cognos', 'task-feedback');
            Util::addScript('cognos', 'task-lifecycle');
            Util::addScript('cognos', 'task-assignment');
            Util::addScript('cognos', 'task-edit');
            Util::addScript('cognos', 'personal-tasks');
            Util::addScript('cognos', 'activity-start');
            // Only load activity-page.js on actual activity pages, not home/notes
            $requestUri = $this->request->getRequestUri();
            if ($event->getResponse()->getApp() === 'cognos' && str_contains($requestUri, '/activities')) {
                Util::addScript('cognos', 'activity-page');
            }
        }
    }
}
