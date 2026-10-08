<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\AppInfo\Application;
use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\ScanJob;
use OCA\MedCabinet\Db\ScanJobMapper;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use Psr\Log\LoggerInterface;

/**
 * Catalogar uma caixa a partir de fotografias, com a IA do proprio Nextcloud.
 *
 * Duas tarefas, com papeis diferentes de proposito:
 *
 * - **ocr** (`core:image2text:ocr`) transcreve o que esta impresso. O que sai
 *   dela e texto da imagem, nao opiniao de ninguem, e e sobre ele que as
 *   regras do BoxTextParser encontram a validade, o lote e a dosagem.
 * - **vision** (`core:analyze-images`) reconhece o produto: que medicamento
 *   e, que forma tem, que substancia leva. E o que um modelo faz bem e uma
 *   expressao regular nao faz de maneira nenhuma.
 *
 * A divisao nao e arbitraria. **Um numero que o texto da imagem nao confirme
 * nao conta como lido.** Um modelo a quem falta a validade na fotografia nao
 * devolve "nao sei": devolve uma data plausivel, e uma data plausivel errada
 * e precisamente o que esta app existe para evitar. Por isso tudo o que e
 * transcricao -- validade, lote, dosagem, quantidade, codigos -- e conferido
 * contra o texto do OCR, e o que nao aparecer la fica marcado como nao
 * confirmado, com o campo em needsReview e um aviso a dizer porque.
 *
 * Nada disto grava um registo. Continua a valer: uma proposta nao e um
 * registo, e quem confirma e quem esta a usar a app.
 */
class AiScanService {
	private const CONFIG_FOLDER = 'ai_photos_folder';
	private const DEFAULT_FOLDER = 'Medicamentos/Caixas';

	/**
	 * Campos que sao transcricao de algo impresso. Para estes, o que o modelo
	 * diz so vale se o texto da imagem o contiver.
	 */
	private const TRANSCRIBED = [
		'expiry', 'batch', 'strength', 'unitsTotal', 'unitsLeft', 'cnp', 'gtin',
	];

	/** Campos que o modelo de visao pode propor. Mais nenhum e aceito. */
	private const VISION_FIELDS = [
		'name', 'substance', 'strength', 'form', 'unit', 'unitsTotal', 'expiry', 'batch',
	];

	private const FORMS = [
		'comprimido', 'capsula', 'xarope', 'suspensao', 'solucao', 'colirio', 'gotas',
		'pomada', 'creme', 'gel', 'supositorio', 'saqueta', 'ampola', 'adesivo',
		'inalador', 'spray',
	];

	public function __construct(
		private ScanJobMapper $jobs,
		private BoxTextParser $boxText,
		private GS1Parser $gs1,
		private MedicineMapper $medicines,
		private ITaskManager $taskManager,
		private PhotoStore $photos,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------- Estado

	/**
	 * O que a IA deste servidor sabe fazer, dito em vez de assumido.
	 *
	 * Interessa saber as duas separadamente: sem OCR a app continua a
	 * reconhecer o produto, mas deixa de poder confirmar numeros -- e isso
	 * muda o que se pode gravar sem olhar, nao e um detalhe tecnico.
	 */
	public function status(): array {
		try {
			$available = $this->taskManager->getAvailableTaskTypeIds();
		} catch (\Throwable $e) {
			$this->logger->warning('Nao foi possivel saber os tipos de tarefa', ['exception' => $e]);
			$available = [];
		}

		$ocr = in_array(ImageToTextOpticalCharacterRecognition::ID, $available, true);
		$vision = in_array(AnalyzeImages::ID, $available, true);

		$status = [
			'available' => $ocr || $vision,
			'ocr' => $ocr,
			'vision' => $vision,
			'folder' => $this->photos->folderPath(),
			'taskTypes' => [
				ImageToTextOpticalCharacterRecognition::ID => $ocr,
				AnalyzeImages::ID => $vision,
			],
		];

		if (!$ocr && !$vision) {
			$status['reason'] =
				'Este servidor não tem nenhum fornecedor de IA capaz de ler imagens. Instala e '
				. 'ativa um (por exemplo o Local AI Assistant ou o Context Chat) e confirma em '
				. 'Definições de administração > Inteligência artificial que "' . AnalyzeImages::ID
				. '" ou "' . ImageToTextOpticalCharacterRecognition::ID . '" aparecem com fornecedor.';
			return $status;
		}

		if (!$ocr) {
			$status['warning'] =
				'Há reconhecimento de imagem mas não há OCR. Sem o texto da imagem não é possível '
				. 'confirmar a validade nem o lote que o modelo propõe -- vêm marcados como não '
				. 'confirmados e têm de ser vistos na caixa.';
		} elseif (!$vision) {
			$status['warning'] =
				'Há OCR mas não há reconhecimento de imagem. A validade, o lote e a dosagem são '
				. 'lidos do texto; o nome do medicamento terá de ser escrito.';
		}

		return $status;
	}

	// ------------------------------------------------------------- Entrada

	/**
	 * Guarda fotografias e devolve os ids dos ficheiros.
	 *
	 * @param list<array{name: string, bytes: string}> $photos
	 * @return array{fileIds: list<int>, names: list<string>}
	 * @throws ScanException
	 */
	public function storePhotos(string $userId, array $photos): array {
		return $this->photos->store($userId, $photos);
	}

	/**
	 * Poe fotografias em fila para a IA ler. Devolve logo, sem esperar.
	 *
	 * @param list<int> $fileIds
	 * @param list<string> $names
	 * @throws ScanException se nao houver IA capaz de ler imagens
	 */
	public function enqueue(string $userId, array $fileIds, array $names = []): ScanJob {
		if ($fileIds === []) {
			throw new ScanException('Não foi indicada nenhuma fotografia.');
		}

		$status = $this->status();
		if (!$status['available']) {
			throw new ScanException($status['reason']);
		}

		$this->photos->assertReadable($userId, $fileIds);

		$job = new ScanJob();
		$job->setUserId($userId);
		$job->setStatus(ScanJob::PENDING);
		$job->setFileIds(json_encode(array_values($fileIds)));
		$job->setFileNames(json_encode(array_values($names !== [] ? $names : $fileIds), JSON_UNESCAPED_UNICODE));
		$job->setCreatedAt(new \DateTimeImmutable());
		$job->setStageMap([]);
		$job = $this->jobs->insert($job);

		$stages = [];

		if ($status['ocr']) {
			$stages[ScanJob::STAGE_OCR] = $this->schedule(
				ImageToTextOpticalCharacterRecognition::ID,
				['input' => array_values($fileIds)],
				$userId,
				(int)$job->getId()
			);
		}

		if ($status['vision']) {
			$stages[ScanJob::STAGE_VISION] = $this->schedule(
				AnalyzeImages::ID,
				['images' => array_values($fileIds), 'input' => $this->prompt()],
				$userId,
				(int)$job->getId()
			);
		}

		$job->setStageMap($stages);

		// Se nenhuma tarefa foi aceite, isto acabou aqui -- e tem de o dizer
		// agora, em vez de ficar uma leitura "a correr" que nunca responde.
		$anyWaiting = false;
		foreach ($stages as $stage) {
			if ($stage['status'] === ScanJob::STAGE_WAITING) {
				$anyWaiting = true;
			}
		}

		$job->setStatus($anyWaiting ? ScanJob::RUNNING : ScanJob::FAILED);
		if (!$anyWaiting) {
			$job->setError('Nenhum fornecedor de IA aceitou as tarefas de leitura. Ver o registo do servidor.');
			$job->setFinishedAt(new \DateTimeImmutable());
		}

		$this->jobs->update($job);

		return $job;
	}

	// ------------------------------------------------------ Resposta da IA

	/**
	 * Uma tarefa da IA respondeu. Guarda o que trouxe e, se foi a ultima,
	 * monta a proposta.
	 *
	 * Nunca lanca: isto corre dentro do despacho de eventos do Nextcloud, e
	 * uma excecao aqui levava com ela o resto do processamento de tarefas.
	 */
	public function onTaskFinished(Task $task, ?string $error = null): void {
		if ($task->getAppId() !== Application::APP_ID) {
			return;
		}

		$jobId = (int)$task->getCustomId();
		if ($jobId <= 0) {
			return;
		}

		try {
			$job = $this->jobs->find($jobId);
		} catch (\Throwable) {
			return;
		}

		$stageName = match ($task->getTaskTypeId()) {
			ImageToTextOpticalCharacterRecognition::ID => ScanJob::STAGE_OCR,
			AnalyzeImages::ID => ScanJob::STAGE_VISION,
			default => null,
		};
		if ($stageName === null) {
			return;
		}

		$stages = $job->stageMap();
		$stage = $stages[$stageName] ?? ['taskId' => $task->getId(), 'status' => ScanJob::STAGE_WAITING];

		if (($stage['status'] ?? null) !== ScanJob::STAGE_WAITING) {
			// Ja respondeu antes. Um evento repetido nao deve voltar a montar
			// a proposta nem a notificar outra vez.
			return;
		}

		if ($error !== null) {
			$stage['status'] = ScanJob::STAGE_FAILED;
			$stage['error'] = mb_substr($error, 0, 1000);
		} else {
			$stage['status'] = ScanJob::STAGE_OK;
			$stage['text'] = $this->textFromOutput($task->getOutput() ?? []);
		}

		$stages[$stageName] = $stage;
		$job->setStageMap($stages);

		try {
			if ($job->stagesSettled()) {
				$this->finish($job);
			} else {
				$this->jobs->update($job);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Falhou a montagem da proposta da leitura', [
				'exception' => $e, 'jobId' => $jobId,
			]);
			$job->setStatus(ScanJob::FAILED);
			$job->setError(mb_substr($e->getMessage(), 0, 2000));
			$job->setFinishedAt(new \DateTimeImmutable());
			try {
				$this->jobs->update($job);
			} catch (\Throwable) {
				// Ja nao ha onde registar; o log acima fica.
			}
		}
	}

	/**
	 * Desiste das leituras que ficaram penduradas.
	 *
	 * Uma tarefa aceite cuja resposta nunca chega deixava a leitura em "a
	 * correr" para sempre. Dizer que falhou e pior do que dizer a verdade --
	 * e dizer nada e pior do que as duas.
	 */
	public function reapStale(int $olderThanMinutes = 60): int {
		$before = (new \DateTimeImmutable())->sub(new \DateInterval('PT' . max(5, $olderThanMinutes) . 'M'));
		$reaped = 0;

		foreach ($this->jobs->findStale($before) as $job) {
			$stages = $job->stageMap();
			foreach ($stages as $name => $stage) {
				if (($stage['status'] ?? ScanJob::STAGE_WAITING) !== ScanJob::STAGE_WAITING) {
					continue;
				}
				// Antes de desistir, pergunta-se a IA: a tarefa pode ter
				// corrido bem e o evento ter-se perdido.
				$recovered = $this->pollTask($stage['taskId'] ?? null);
				if ($recovered !== null) {
					$stages[$name] = $recovered + ['taskId' => $stage['taskId'] ?? null];
					continue;
				}
				$stages[$name] = [
					'taskId' => $stage['taskId'] ?? null,
					'status' => ScanJob::STAGE_FAILED,
					'error' => 'A IA não respondeu dentro do tempo esperado.',
				];
			}

			$job->setStageMap($stages);
			$this->finish($job);
			$reaped++;
		}

		return $reaped;
	}

	// ------------------------------------------------------------- Consulta

	/** @return list<array> */
	public function listForUser(string $userId): array {
		return array_map(
			static fn (ScanJob $job) => $job->jsonSerialize(),
			$this->jobs->findAllForUser($userId)
		);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function get(int $id, string $userId, bool $withText = false): array {
		$job = $this->jobs->findForUser($id, $userId);
		$out = $job->jsonSerialize();

		if ($withText) {
			// O texto em bruto so a pedido: sao paginas de OCR, e quem o quer
			// e quem esta a perceber porque e que um campo saiu como saiu.
			foreach ($job->stageMap() as $name => $stage) {
				$out['stages'][$name]['text'] = $stage['text'] ?? null;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------- Interno

	/**
	 * @return array{taskId: ?int, status: string, error?: string}
	 */
	private function schedule(string $taskTypeId, array $input, string $userId, int $jobId): array {
		try {
			$task = new Task($taskTypeId, $input, Application::APP_ID, $userId, (string)$jobId);
			$this->taskManager->scheduleTask($task);
			return ['taskId' => $task->getId(), 'status' => ScanJob::STAGE_WAITING];
		} catch (\Throwable $e) {
			$this->logger->warning('Nao foi possivel agendar a tarefa de IA', [
				'exception' => $e, 'taskType' => $taskTypeId,
			]);
			return [
				'taskId' => null,
				'status' => ScanJob::STAGE_FAILED,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * @return array{status: string, text?: ?string, error?: string}|null
	 */
	private function pollTask(?int $taskId): ?array {
		if ($taskId === null) {
			return null;
		}
		try {
			$task = $this->taskManager->getTask($taskId);
		} catch (\Throwable) {
			return null;
		}

		return match ($task->getStatus()) {
			Task::STATUS_SUCCESSFUL => [
				'status' => ScanJob::STAGE_OK,
				'text' => $this->textFromOutput($task->getOutput() ?? []),
			],
			Task::STATUS_FAILED, Task::STATUS_CANCELLED => [
				'status' => ScanJob::STAGE_FAILED,
				'error' => $task->getErrorMessage() ?? 'A tarefa de IA não concluiu.',
			],
			default => null,
		};
	}

	/**
	 * A saida pode ser um texto ou uma lista de textos -- o OCR devolve um por
	 * ficheiro. Junta-se tudo, porque as fotos sao de uma caixa so e o que
	 * interessa e o que esta escrito nela, nao em qual das fotos estava.
	 */
	private function textFromOutput(array $output): ?string {
		$value = $output['output'] ?? null;
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value)) {
			$parts = array_values(array_filter(array_map(
				static fn (mixed $v) => is_string($v) ? trim($v) : null,
				$value
			)));
			return $parts === [] ? null : implode("\n", $parts);
		}
		return null;
	}

	private function finish(ScanJob $job): void {
		$stages = $job->stageMap();
		$ocrText = $stages[ScanJob::STAGE_OCR]['text'] ?? null;
		$visionText = $stages[ScanJob::STAGE_VISION]['text'] ?? null;

		$result = $this->buildProposal($job->getUserId(), $ocrText, $visionText, $stages);

		$job->setProposal(json_encode($result, JSON_UNESCAPED_UNICODE));
		$job->setStatus($result['proposal'] === null ? ScanJob::FAILED : ScanJob::DONE);
		if ($result['proposal'] === null) {
			$job->setError(
				'Não se conseguiu ler nada das fotografias. Aproxima-te do painel onde está a '
				. 'validade, com boa luz e sem reflexos no plástico.'
			);
		}
		$job->setFinishedAt(new \DateTimeImmutable());

		$this->jobs->update($job);
		$this->notify($job);
	}

	/**
	 * Monta a proposta a partir do que as duas tarefas trouxeram.
	 *
	 * @return array{proposal: ?array, medicine: ?array, sources: array}
	 */
	public function buildProposal(
		string $userId,
		?string $ocrText,
		?string $visionText,
		array $stages = [],
	): array {
		$proposal = new ScanProposal();
		$medicine = null;
		$sources = [];

		foreach ($stages as $name => $stage) {
			if (($stage['status'] ?? null) === ScanJob::STAGE_FAILED) {
				$proposal->warn(sprintf(
					'A fase "%s" da leitura falhou: %s',
					$name === ScanJob::STAGE_OCR ? 'transcrição do texto' : 'reconhecimento do produto',
					$stage['error'] ?? 'razão desconhecida'
				));
			}
		}

		// 1. O texto impresso. As regras do BoxTextParser nao inventam: ou
		// encontram o que esta escrito, ou nao devolvem nada.
		if ($ocrText !== null && trim($ocrText) !== '') {
			$read = $this->boxText->parse($ocrText);
			$sources['ocr'] = ['values' => $read['values'], 'evidence' => $read['evidence']];

			foreach ($read['warnings'] as $warning) {
				$proposal->warn($warning);
			}

			// A interpretacao legivel do codigo 2D, se a caixa a imprimir.
			if ($read['gs1'] !== null) {
				$code = $this->gs1->parse($read['gs1']);
				$sources['gs1'] = $code['fields'];
				foreach ($code['warnings'] as $warning) {
					$proposal->warn($warning);
				}
				// Confianca "text" e nao "code": os digitos vieram do OCR, nao
				// do descodificador. O digito de controlo do GTIN apanha a
				// maior parte dos erros de um digito, nao todos.
				$proposal->observe([
					'gtin' => $code['gtin'],
					'expiry' => $code['expiry'],
					'batch' => $code['batch'],
				], ScanProposal::CONFIDENCE_TEXT, 'código impresso na caixa (lido como texto)');

				if ($code['gtin'] !== null) {
					$proposal->warn(
						'O código do produto foi lido do texto impresso debaixo do código 2D, não '
						. 'descodificado. Passou o dígito de controlo, mas confirma-o antes de o '
						. 'associar a este medicamento.'
					);
				}
			}

			$proposal->observe($read['values'], ScanProposal::CONFIDENCE_TEXT, 'texto lido da fotografia');
		} elseif (($stages[ScanJob::STAGE_OCR]['status'] ?? null) !== ScanJob::STAGE_FAILED
			&& array_key_exists(ScanJob::STAGE_OCR, $stages)) {
			$proposal->warn('Não se extraiu texto nenhum das fotografias.');
		}

		// 2. O modelo de visao: que produto e. Os campos de transcricao que
		// ele proponha sao conferidos contra o texto -- e e aqui que a app
		// deixa de aceitar numeros que ninguem viu.
		if ($visionText !== null && trim($visionText) !== '') {
			$seen = $this->parseVision($visionText);
			$sources['vision'] = $seen;

			if ($seen['error'] !== null) {
				$proposal->warn('O modelo respondeu: ' . $seen['error']);
			}

			foreach ($seen['values'] as $field => $value) {
				$verified = true;
				if (in_array($field, self::TRANSCRIBED, true)) {
					$verified = $ocrText !== null
						&& $this->boxText->corroborates($ocrText, (string)$value);
				}
				$proposal->observe(
					[$field => $value],
					ScanProposal::CONFIDENCE_TEXT,
					'reconhecimento da fotografia',
					$verified
				);
			}

			if ($seen['values'] === [] && $seen['error'] === null) {
				$proposal->warn(
					'O modelo não devolveu nada que se pudesse aproveitar. A resposta ficou '
					. 'guardada em "sources" para se poder ver o que ele disse.'
				);
			}
		}

		$result = $proposal->result();
		if ($result['values'] === []) {
			return ['proposal' => null, 'medicine' => null, 'sources' => $sources];
		}

		// 3. Se esta caixa ja foi registada, o registo anterior manda: foi
		// confirmado por uma pessoa, e e o que faz a segunda leitura da mesma
		// embalagem preencher-se sozinha.
		$gtin = $result['values']['gtin'] ?? null;
		if ($gtin !== null) {
			$found = $this->medicines->findByGtin((string)$gtin, $userId);
			if ($found !== null) {
				$medicine = $found->jsonSerialize();
				$proposal->observe([
					'medicineId' => $found->getId(),
					'name' => $found->getName(),
					'substance' => $found->getSubstance(),
					'strength' => $found->getStrength(),
					'form' => $found->getForm(),
					'unit' => $found->getUnit(),
				], ScanProposal::CONFIDENCE_MANUAL, 'registo anterior desta caixa');
				$result = $proposal->result();
			}
		}

		if (!isset($result['values']['expiry'])) {
			$proposal->warn(
				'Não se conseguiu ler a validade. É o campo que esta app existe para não errar -- '
				. 'escreve-a a olhar para a caixa.'
			);
			$result = $proposal->result();
		}

		return ['proposal' => $result, 'medicine' => $medicine, 'sources' => $sources];
	}

	/**
	 * O que se pede ao modelo de visao.
	 *
	 * Escrito para ele NAO usar o que sabe. Um modelo que conhece a marca
	 * preenche a dosagem de cabeca -- acerta quase sempre, e o "quase" e uma
	 * caixa de 1000 mg registada como 500. So vale o que esta na fotografia.
	 */
	private function prompt(): string {
		return
			"Estas fotografias são de UMA embalagem de medicamento. Identifica o produto.\n\n"
			. "Responde APENAS com um objeto JSON, sem texto antes nem depois, com estas chaves:\n"
			. '{"name": null, "substance": null, "strength": null, "form": null, "unit": null, '
			. '"unitsTotal": null, "expiry": null, "batch": null, "evidence": {}}' . "\n\n"
			. "Regras:\n"
			. "- name: o nome comercial tal como está impresso na caixa.\n"
			. "- substance: a substância ativa (por exemplo \"paracetamol\").\n"
			. "- strength: a dosagem com a unidade, como está escrita (\"500 mg\", \"100 mg/ml\").\n"
			. '- form: uma destas, ou null: ' . implode(', ', self::FORMS) . ".\n"
			. "- unit: a unidade em que se conta o conteúdo (comprimido, ml, gota, saqueta, puff).\n"
			. "- unitsTotal: quantas unidades a embalagem tem, só o número.\n"
			. "- expiry: a validade no formato AAAA-MM-DD. Se a caixa só der mês e ano, usa o "
			. "ÚLTIMO dia desse mês.\n"
			. "- batch: o lote, tal como está escrito.\n"
			. "- evidence: para cada campo que preencheste, o texto EXATO que leste na imagem.\n\n"
			. "MUITO IMPORTANTE:\n"
			. "- Se um campo não estiver VISÍVEL nas fotografias, põe null. Não deduzas, não "
			. "completes pelo que é habitual nesse medicamento, não uses o que sabes sobre o "
			. "produto. Só vale o que está na fotografia.\n"
			. "- Nunca escrevas uma data ou um lote que não consigas ler. Um valor inventado é "
			. "pior do que um campo vazio.\n"
			. "- Se as fotografias não forem de uma embalagem de medicamento, responde "
			. '{"error": "o que se vê nas fotografias"}.';
	}

	/**
	 * Le a resposta do modelo.
	 *
	 * Tolerante na forma e severa no conteudo. Na forma porque um modelo
	 * envolve o JSON em ```json, ou escreve uma frase antes -- rejeitar por
	 * isso perdia uma leitura boa. No conteudo porque so se aceitam os campos
	 * conhecidos, e cada um tem de passar a sua propria validacao: uma data
	 * tem de ser uma data que existe, uma forma tem de ser uma das formas.
	 *
	 * @return array{values: array<string, mixed>, evidence: array<string, string>, error: ?string, raw: string}
	 */
	public function parseVision(string $text): array {
		$json = $this->extractJson($text);
		if ($json === null) {
			return [
				'values' => [], 'evidence' => [],
				'error' => null, 'raw' => mb_substr(trim($text), 0, 2000),
			];
		}

		$error = isset($json['error']) && is_string($json['error']) && trim($json['error']) !== ''
			? mb_substr(trim($json['error']), 0, 500)
			: null;

		$values = [];
		foreach (self::VISION_FIELDS as $field) {
			$value = $json[$field] ?? null;
			if ($value === null || $value === '' || $value === 'null') {
				continue;
			}
			$clean = $this->cleanField($field, $value);
			if ($clean !== null) {
				$values[$field] = $clean;
			}
		}

		$evidence = [];
		if (isset($json['evidence']) && is_array($json['evidence'])) {
			foreach ($json['evidence'] as $field => $seen) {
				if (is_string($seen) && trim($seen) !== '') {
					$evidence[(string)$field] = mb_substr(trim($seen), 0, 200);
				}
			}
		}

		return [
			'values' => $values,
			'evidence' => $evidence,
			'error' => $error,
			'raw' => mb_substr(trim($text), 0, 2000),
		];
	}

	private function cleanField(string $field, mixed $value): string|int|null {
		if (is_array($value)) {
			return null;
		}
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return match ($field) {
			// Uma data tem de ser uma data que existe. "2028-02-30" vem com ar
			// de data e nao e nenhuma -- e passaria a validade a 1 de marco.
			'expiry' => $this->cleanDate($value),
			'unitsTotal' => preg_match('/^\d{1,4}$/', $value) === 1 ? (int)$value : null,
			'form' => in_array(mb_strtolower($value), self::FORMS, true) ? mb_strtolower($value) : null,
			'batch' => preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/]{1,19}$/', $value) === 1
				? strtoupper($value)
				: null,
			default => mb_substr($value, 0, 192),
		};
	}

	private function cleanDate(string $value): ?string {
		if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $value, $m) !== 1) {
			return null;
		}
		$year = (int)$m[1];
		$month = (int)$m[2];
		if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
			return null;
		}
		$first = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month));
		if ($first === false) {
			return null;
		}
		// Sem dia, vale o ultimo do mes -- a mesma regra do codigo e da caixa.
		if (!isset($m[3]) || $m[3] === '') {
			return $first->format('Y-m-t');
		}
		$day = (int)$m[3];
		if ($day < 1 || $day > (int)$first->format('t')) {
			return null;
		}
		return $first->setDate($year, $month, $day)->format('Y-m-d');
	}

	private function extractJson(string $text): ?array {
		$trimmed = trim($text);

		// Dentro de ```json ... ```
		if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m) === 1) {
			$decoded = json_decode($m[1], true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		$start = strpos($trimmed, '{');
		$end = strrpos($trimmed, '}');
		if ($start === false || $end === false || $end <= $start) {
			return null;
		}

		$decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
		return is_array($decoded) ? $decoded : null;
	}

	private function notify(ScanJob $job): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($job->getUserId())
			->setDateTime(new \DateTime())
			->setObject('scan_job', (string)$job->getId());

		if ($job->getStatus() === ScanJob::FAILED) {
			$notification->setSubject('scan_failed', [
				'photos' => count($job->fileIdList()),
				'error' => (string)$job->getError(),
			]);
		} else {
			$result = json_decode((string)$job->getProposal(), true) ?: [];
			$proposal = $result['proposal'] ?? [];
			$notification->setSubject('scan_done', [
				'photos' => count($job->fileIdList()),
				'name' => (string)($proposal['values']['name'] ?? ''),
				'expiry' => (string)($proposal['values']['expiry'] ?? ''),
				'review' => count($proposal['needsReview'] ?? []),
				'jobId' => (int)$job->getId(),
			]);
		}

		try {
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Nao foi possivel notificar', ['exception' => $e]);
		}
	}
}
