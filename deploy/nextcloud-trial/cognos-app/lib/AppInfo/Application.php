<?php
declare(strict_types=1);
namespace OCA\Cognos\AppInfo;

use OCA\Cognos\Command\ImportObsidian;
use OCA\Cognos\Listener\TaskFeedbackListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {
    public function __construct(array $urlParams = []) {
        parent::__construct('cognos', $urlParams);
    }
    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, TaskFeedbackListener::class);
    }
    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();

        // Set Cognos as the default app; only write config.php when it actually changes
        $config = $server->get(\OCP\IConfig::class);
        if ($config->getSystemValue('defaultapp', '') !== 'cognos') {
            $config->setSystemValue('defaultapp', 'cognos');
        }
    }
}
