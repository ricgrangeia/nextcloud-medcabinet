<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Listener;

use OCA\MedCabinet\Service\AiScanService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

/**
 * Ouve as tarefas de IA a terminar.
 *
 * E por evento e nao por sondagem de proposito. Um modelo local a olhar para
 * tres fotografias pode levar minutos, e nem o pedido web nem uma passagem do
 * cron sao sitio para esperar por ele: o pedido rebentava por timeout e o
 * cron ficava preso a segurar uma fila que nao e dele. Assim as fotos entram,
 * o separador fica livre, e quando a IA responde isto continua o trabalho.
 *
 * @template-implements IEventListener<TaskSuccessfulEvent|TaskFailedEvent>
 */
class TaskListener implements IEventListener {
	public function __construct(private AiScanService $scans) {
	}

	public function handle(Event $event): void {
		if ($event instanceof TaskSuccessfulEvent) {
			$this->scans->onTaskFinished($event->getTask());
			return;
		}

		if ($event instanceof TaskFailedEvent) {
			$this->scans->onTaskFinished($event->getTask(), $event->getErrorMessage());
		}
	}
}
