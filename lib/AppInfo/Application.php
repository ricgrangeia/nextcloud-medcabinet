<?php

declare(strict_types=1);

namespace OCA\MedCabinet\AppInfo;

use OCA\MedCabinet\Listener\TaskListener;
use OCA\MedCabinet\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'medcabinet';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);

		// A leitura das fotografias e assincrona: as tarefas de IA sao
		// agendadas e a app e avisada quando terminam. O ouvinte filtra pelas
		// que sao desta app, pelo appId da tarefa.
		$context->registerEventListener(TaskSuccessfulEvent::class, TaskListener::class);
		$context->registerEventListener(TaskFailedEvent::class, TaskListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
