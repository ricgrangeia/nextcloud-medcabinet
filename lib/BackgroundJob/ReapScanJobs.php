<?php

declare(strict_types=1);

namespace OCA\MedCabinet\BackgroundJob;

use OCA\MedCabinet\Service\AiScanService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Fecha as leituras que ficaram penduradas.
 *
 * A app recebe as respostas da IA por evento, e um evento pode nao chegar: o
 * fornecedor foi desativado a meio, o worker morreu, a tarefa ficou na fila
 * de alguem. Sem isto a leitura ficava "a correr" para sempre e quem a enviou
 * ficava a olhar para uma coisa que nunca mais mexe.
 *
 * Antes de desistir, pergunta a IA pela tarefa: pode ter corrido bem e so o
 * aviso ter-se perdido. Desistir sem perguntar perdia uma leitura boa.
 */
class ReapScanJobs extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AiScanService $scans,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(30 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$this->scans->reapStale();
		} catch (\Throwable $e) {
			// Nunca rebentar o cron: leva com ela os outros trabalhos.
			$this->logger->warning('Falhou a limpeza das leituras pendentes', ['exception' => $e]);
		}
	}
}
