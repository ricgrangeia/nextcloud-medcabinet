<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Controller;

use OCA\MedCabinet\Service\CabinetService;
use OCA\MedCabinet\Service\EpisodeService;
use OCA\MedCabinet\Service\MedicineService;
use OCA\MedCabinet\Service\ScanException;
use OCA\MedCabinet\Service\ScanService;
use OCA\MedCabinet\Service\PersonService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A UNICA superficie de API da app -- usada tanto pela interface web como por
 * clientes externos (um agente de IA com uma app password).
 *
 * O que o agente consegue fazer e exactamente o que a UI faz, e nao ha como
 * os dois divergirem com o tempo.
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PersonService $personService,
		private MedicineService $medicineService,
		private EpisodeService $episodeService,
		private ScanService $scanService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function getUserId(): string {
		return $this->userSession->getUser()->getUID();
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
	}

	private function badRequest(string $message): DataResponse {
		return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}

	private function today(): string {
		return (new \DateTimeImmutable('today'))->format('Y-m-d');
	}

	private function date(?string $value): ?\DateTimeImmutable {
		if ($value === null || trim($value) === '') {
			return null;
		}
		return new \DateTimeImmutable($value);
	}

	// ------------------------------------------------------------------ Ajuda

	/**
	 * Descreve a API inteira, incluindo as armadilhas do dominio.
	 *
	 * Publico de proposito: e por aqui que um agente comeca, antes de ter
	 * credenciais.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/help')]
	public function help(): DataResponse {
		return new DataResponse([
			'description' =>
				'Armario de Medicamentos: o que ha em casa, para quem, para que serviu e ate quando '
				. 'e valido. Responde "o que foi usado, quando e por indicacao de quem" -- NUNCA "o '
				. 'que deves tomar". Um registo com proveniencia vale mais do que uma sugestao sem fonte.',
			'authentication' => [
				'method' => 'HTTP Basic Auth com uma app password do Nextcloud (NAO a password da conta)',
				'howToGet' => 'Definicoes > Seguranca > Dispositivos e sessoes > "Criar nova app password"',
				'requiredHeader' => 'OCS-APIRequest: true (em todos os pedidos)',
			],
			'concepts' => [
				'medicine' => 'O produto: nome comercial, substancia ativa, dosagem, forma. NAO e uma caixa.',
				'substance' => 'A substancia ativa. E o campo que faz "Brufen" e "ibuprofeno" encontrarem-se. Sem catalogo externo, so existe se for preenchido -- preenche-o sempre.',
				'package' => 'Uma caixa fisica no armario. O stock conta-se em UNIDADES do medicamento (comprimidos, ml, gotas), nao em caixas: meia caixa e meio comprimido existem.',
				'unit' => 'A unidade em que se conta o stock desse medicamento: comprimido, ml, gota, puff.',
				'expiresAt' => 'A validade IMPRESSA na caixa. Nao e necessariamente a que conta -- ver effectiveExpiry.',
				'openedAt' => 'Quando a embalagem foi aberta. Para xaropes, colirios, suspensoes e insulina, e isto que decide a validade real.',
				'daysAfterOpening' => 'Quantos dias o medicamento dura depois de aberto. Sem ele, uma embalagem aberta de forma pereciveil fica com validade DESCONHECIDA em vez de usar a impressa.',
				'effectiveExpiry' => 'min(impressa, abertura + daysAfterOpening). Abrir NUNCA prolonga a validade. Um xarope com a caixa a dizer 2028, aberto ha dois meses e valido 28 dias, esta FORA DE PRAZO -- e o campo "source" diz qual das duas datas mandou.',
				'episode' => 'PARA QUE serviu: uma pessoa, um motivo ("otite", "dor de dentes"), um intervalo, quem assistiu. A finalidade vive aqui e NAO no medicamento, porque o mesmo medicamento serve fins diferentes em epocas diferentes.',
				'posology' => 'Texto livre, como veio escrito ("1 comprimido de 8 em 8 horas, 8 dias"). Nao e estruturado de proposito: interpretar posologia e inventar precisao que a receita nao tem.',
				'prescriber' => 'Quem indicou. Faz parte da proveniencia: "receitado pelo Dr. X" vale diferente de "demos nos".',
				'useFirst' => 'Qual caixa gastar primeiro: a que expira mais cedo, nao a mais antiga. Com a mesma validade, a ja aberta -- abrir uma segunda tendo uma aberta garante que uma se estraga.',
				'scanProposal' => 'O que sai de uma leitura e uma PROPOSTA, nao um registo. O que vem de um codigo de barras e exacto (tem digito de controlo); o que vem de texto numa fotografia e um palpite com boa aparencia -- um "7" lido como "1" desloca a validade seis anos e continua a parecer uma data normal. Por isso cada campo diz de onde veio, e "needsReview" lista o que precisa de confirmacao antes de /scan/apply.',
				'gs1EndOfMonth' => 'A validade no codigo vem como AAMMDD, e o dia pode ser "00" -- que na norma GS1 significa FIM DO MES, nao dia zero. "280300" e 31 de marco de 2028. Ao pe da letra da data invalida; posto a dia 1 encurta a validade um mes inteiro.',
				'derived' => 'Validade efetiva, stock utilizavel e ordem de uso NUNCA sao guardados -- sao sempre calculados a partir dos dados em bruto.',
			],
			'quickReference' => [
				'GET /api/v1/overview' => 'O que precisa de atencao: fora de prazo, a expirar, sem validade conhecida, e o que esta a acabar.',
				'GET /api/v1/people' => 'Pessoas da casa. POST para criar, PUT/DELETE em /people/{id}.',
				'GET /api/v1/medicines' => 'Medicamentos com o estado das caixas. Filtro "q" procura no nome E na substancia ativa.',
				'POST /api/v1/medicines' => 'Cria. Campos: name (obrig.), substance, strength, form, unit, daysAfterOpening, gtin, notes.',
				'GET /api/v1/medicines/{id}' => 'Um medicamento com todas as caixas, o estado de cada uma e qual usar primeiro.',
				'POST /api/v1/medicines/{id}/packages' => 'Registar uma caixa. Campos: unitsTotal, unitsLeft, expiresAt, openedAt, batch, location, source (manual|datamatrix|prescription).',
				'PUT /api/v1/packages/{id}' => 'Alterar uma caixa -- incluindo registar a abertura (openedAt) ou o descarte (discardedAt).',
				'GET /api/v1/medicines/{id}/uses' => 'PARA QUE serviu este medicamento: episodios, com data, pessoa, quem receitou e posologia.',
				'GET /api/v1/episodes' => 'Episodios, mais recente primeiro. Filtros: personId, e "q" que procura no motivo, no resultado e nas notas.',
				'POST /api/v1/episodes' => 'Cria. Campos: reason (obrig.), personId, startedAt, endedAt, prescriber, outcome, notes, items[] com medicineId e posology.',
				'POST /api/v1/episodes/{id}/items' => 'Acrescenta um medicamento ao episodio.',
				'GET /api/v1/scan/status' => 'Diz se a leitura de fotografias esta disponivel, e o que falta se nao.',
				'POST /api/v1/scan/code' => 'Interpreta a cadeia GS1 do DataMatrix de uma caixa (campo "payload"). Devolve uma PROPOSTA com "needsReview" e "canSaveDirectly" -- nao grava nada.',
				'POST /api/v1/scan/merge' => 'Junta varias leituras da mesma caixa. Cada entrada de "observations" pode ter "payload", "values", "from" e "manual". Desacordos aparecem em "conflicts".',
				'POST /api/v1/scan/photos' => 'Le fotografias (multipart "file" ou "file[]") a procura de codigos e junta o que encontrar. Precisa de servico de leitura configurado.',
				'POST /api/v1/scan/apply' => 'Grava uma proposta JA REVISTA (campo "values"): cria o medicamento se for novo, e a caixa.',
			],
			'notAdvice' =>
				'Esta API devolve o historico do utilizador, nao recomendacoes clinicas. '
				. '"Serviu para X em marco, receitado pelo Dr. Y" e um facto do registo dele. '
				. '"Usa isto para X" nao e coisa que esta API diga, nem que um cliente deva '
				. 'inferir dela.',
		]);
	}

	// ----------------------------------------------------------- Visao geral

	/**
	 * O que precisa de atencao.
	 *
	 * Primeiro o que esta fora de prazo e o que nao se sabe, depois o que
	 * esta a expirar. Por esta ordem porque e a ordem em que se age: o que
	 * esta mau deita-se fora hoje, o que expira em 20 dias usa-se primeiro.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/overview')]
	public function overview(): DataResponse {
		$today = $this->today();
		$medicines = $this->medicineService->findAll($this->getUserId(), null, $today);

		$buckets = [
			CabinetService::STATUS_EXPIRED => [],
			CabinetService::STATUS_UNKNOWN => [],
			CabinetService::STATUS_EXPIRING => [],
		];
		$empty = [];

		foreach ($medicines as $medicine) {
			foreach ($medicine['packages'] as $package) {
				if ($package['status'] === CabinetService::STATUS_DISCARDED) {
					continue;
				}
				if (isset($buckets[$package['status']])) {
					$buckets[$package['status']][] = [
						'medicineId' => $medicine['id'],
						'medicine' => $medicine['name'],
						'strength' => $medicine['strength'],
						'packageId' => $package['id'],
						'expiry' => $package['expiry'],
						'daysLeft' => $package['daysLeft'],
						'location' => $package['location'],
						'reason' => $package['reason'],
					];
				}
			}
			if (($medicine['unitsUsable'] ?? null) !== null && $medicine['unitsUsable'] <= 0) {
				$empty[] = [
					'medicineId' => $medicine['id'],
					'medicine' => $medicine['name'],
					'strength' => $medicine['strength'],
				];
			}
		}

		return new DataResponse([
			'today' => $today,
			'expired' => $buckets[CabinetService::STATUS_EXPIRED],
			'unknownExpiry' => $buckets[CabinetService::STATUS_UNKNOWN],
			'expiringSoon' => $buckets[CabinetService::STATUS_EXPIRING],
			'outOfStock' => $empty,
			'expiringSoonDays' => CabinetService::EXPIRING_SOON_DAYS,
			'medicineCount' => count($medicines),
		]);
	}

	// --------------------------------------------------------------- Pessoas

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/people')]
	public function listPeople(): DataResponse {
		return new DataResponse($this->personService->findAll($this->getUserId()));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/people')]
	public function createPerson(string $name, ?string $birthDate = null, ?string $notes = null): DataResponse {
		if (trim($name) === '') {
			return $this->badRequest('O nome e obrigatorio.');
		}
		try {
			return new DataResponse($this->personService->create(
				$this->getUserId(), trim($name), $this->date($birthDate), $notes
			));
		} catch (\Exception $e) {
			return $this->badRequest($e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/people/{id}')]
	public function updatePerson(int $id, ?string $name = null, ?string $birthDate = null, ?string $notes = null): DataResponse {
		try {
			return new DataResponse($this->personService->update(
				$id, $this->getUserId(), $name, $this->date($birthDate), $notes
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->badRequest($e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/people/{id}')]
	public function deletePerson(int $id): DataResponse {
		try {
			$this->personService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// ---------------------------------------------------------- Medicamentos

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/medicines')]
	public function listMedicines(?string $q = null): DataResponse {
		return new DataResponse(
			$this->medicineService->findAll($this->getUserId(), $q, $this->today())
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/medicines/{id}')]
	public function getMedicine(int $id): DataResponse {
		try {
			return new DataResponse(
				$this->medicineService->detail($id, $this->getUserId(), $this->today())
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/medicines')]
	public function createMedicine(
		string $name,
		?string $substance = null,
		?string $strength = null,
		?string $form = null,
		?string $unit = null,
		?int $daysAfterOpening = null,
		?string $gtin = null,
		?string $notes = null,
	): DataResponse {
		if (trim($name) === '') {
			return $this->badRequest('O nome e obrigatorio.');
		}
		return new DataResponse($this->medicineService->create($this->getUserId(), [
			'name' => $name, 'substance' => $substance, 'strength' => $strength,
			'form' => $form, 'unit' => $unit, 'daysAfterOpening' => $daysAfterOpening,
			'gtin' => $gtin, 'notes' => $notes,
		]));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/medicines/{id}')]
	public function updateMedicine(int $id): DataResponse {
		$fields = ['name', 'substance', 'strength', 'form', 'unit', 'daysAfterOpening', 'gtin', 'notes'];
		$data = [];
		foreach ($fields as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$data[$field] = $value;
			}
		}
		try {
			return new DataResponse($this->medicineService->update($id, $this->getUserId(), $data));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/medicines/{id}')]
	public function deleteMedicine(int $id): DataResponse {
		try {
			$this->medicineService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	/**
	 * Para que serviu este medicamento, segundo o registo.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/medicines/{id}/uses')]
	public function medicineUses(int $id): DataResponse {
		try {
			return new DataResponse($this->episodeService->forMedicine($id, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// ------------------------------------------------------------ Embalagens

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/medicines/{id}/packages')]
	public function addPackage(int $id): DataResponse {
		$data = $this->packageParams();
		try {
			return new DataResponse($this->medicineService->addPackage($id, $this->getUserId(), $data));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/packages/{id}')]
	public function updatePackage(int $id): DataResponse {
		try {
			return new DataResponse(
				$this->medicineService->updatePackage($id, $this->getUserId(), $this->packageParams())
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/packages/{id}')]
	public function deletePackage(int $id): DataResponse {
		try {
			$this->medicineService->deletePackage($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	/**
	 * Le so os campos que vieram no pedido.
	 *
	 * A distincao importa: `openedAt` ausente significa "nao mexer", e
	 * `openedAt: null` significa "marcar como nao aberta". Tratar os dois da
	 * mesma maneira tornaria impossivel desfazer um registo de abertura.
	 */
	private function packageParams(): array {
		$params = $this->request->getParams();
		$data = [];
		foreach (['unitsTotal', 'unitsLeft', 'expiresAt', 'openedAt', 'discardedAt', 'batch', 'location', 'source'] as $field) {
			// array_key_exists, nao getParam() !== null: a distincao entre
			// "nao veio" e "veio a null" e o que permite desfazer o registo de
			// uma abertura.
			if (array_key_exists($field, $params)) {
				$data[$field] = $params[$field];
			}
		}
		return $data;
	}

	// -------------------------------------------------------------- Episodios

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/episodes')]
	public function listEpisodes(?int $personId = null, ?string $q = null): DataResponse {
		return new DataResponse($this->episodeService->findAll($this->getUserId(), $personId, $q));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/episodes/{id}')]
	public function getEpisode(int $id): DataResponse {
		try {
			return new DataResponse($this->episodeService->detail($id, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/episodes')]
	public function createEpisode(
		string $reason,
		?int $personId = null,
		?string $startedAt = null,
		?string $endedAt = null,
		?string $prescriber = null,
		?string $outcome = null,
		?string $notes = null,
		array $items = [],
	): DataResponse {
		if (trim($reason) === '') {
			return $this->badRequest('O motivo e obrigatorio -- e por ele que se procura depois.');
		}
		try {
			return new DataResponse($this->episodeService->create($this->getUserId(), [
				'reason' => $reason, 'personId' => $personId,
				'startedAt' => $startedAt ?? $this->today(), 'endedAt' => $endedAt,
				'prescriber' => $prescriber, 'outcome' => $outcome, 'notes' => $notes,
				'items' => $items,
			]));
		} catch (DoesNotExistException) {
			return $this->badRequest('A pessoa ou o medicamento indicados nao existem.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/episodes/{id}')]
	public function updateEpisode(int $id): DataResponse {
		$data = [];
		foreach (['reason', 'personId', 'startedAt', 'endedAt', 'prescriber', 'outcome', 'notes'] as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$data[$field] = $value;
			}
		}
		try {
			return new DataResponse($this->episodeService->update($id, $this->getUserId(), $data));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/episodes/{id}')]
	public function deleteEpisode(int $id): DataResponse {
		try {
			$this->episodeService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/episodes/{id}/items')]
	public function addEpisodeItem(
		int $id,
		int $medicineId,
		?string $posology = null,
		?string $startedAt = null,
		?string $endedAt = null,
		?string $notes = null,
	): DataResponse {
		try {
			return new DataResponse($this->episodeService->addItemTo($id, $this->getUserId(), [
				'medicineId' => $medicineId, 'posology' => $posology,
				'startedAt' => $startedAt, 'endedAt' => $endedAt, 'notes' => $notes,
			]));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/episode-items/{id}')]
	public function deleteEpisodeItem(int $id): DataResponse {
		try {
			$this->episodeService->deleteItem($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// --------------------------------------------------- Registar por leitura

	/**
	 * Diz se a leitura de fotografias esta disponivel, e o que falta se nao.
	 *
	 * A interface pergunta isto antes de mostrar o botao: um botao que nao
	 * funciona e pior do que um botao ausente.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/scan/status')]
	public function scanStatus(): DataResponse {
		return new DataResponse($this->scanService->readerStatus());
	}

	/**
	 * Interpreta o conteudo do codigo de uma caixa.
	 *
	 * O `payload` e a cadeia GS1 tal como sai do DataMatrix -- de uma app de
	 * telefone, de um leitor, ou colada a mao. Devolve uma PROPOSTA, nao um
	 * registo: `needsReview` diz que campos precisam de confirmacao e
	 * `canSaveDirectly` diz se se pode gravar sem perguntar.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/scan/code')]
	public function scanCode(string $payload): DataResponse {
		if (trim($payload) === '') {
			return $this->badRequest('Falta o conteudo do codigo.');
		}
		return new DataResponse($this->scanService->fromCode($payload, $this->getUserId()));
	}

	/**
	 * Junta varias leituras da mesma caixa numa proposta so.
	 *
	 * Uma caixa precisa de mais do que uma: o nome esta na frente, o
	 * DataMatrix com o lote e a validade esta noutro painel. Cada entrada de
	 * `observations` pode trazer `payload` (conteudo de um codigo), `values`
	 * (campos lidos ou escritos), `from` (de onde veio) e `manual` (true se
	 * foi escrito a mao).
	 *
	 * Os desacordos entre leituras aparecem em `conflicts` em vez de serem
	 * resolvidos a sorte.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/scan/merge')]
	public function scanMerge(array $observations = []): DataResponse {
		if ($observations === []) {
			return $this->badRequest('Falta "observations".');
		}
		return new DataResponse($this->scanService->merge($observations, $this->getUserId()));
	}

	/**
	 * Le fotografias de uma embalagem e devolve a proposta.
	 *
	 * Cada imagem e lida a procura de codigos, e o que elas disserem junta-se.
	 * Depende de haver um servico de leitura configurado -- ver
	 * GET /api/v1/scan/status.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/scan/photos')]
	public function scanPhotos(): DataResponse {
		$files = $this->uploadedFiles();
		if ($files === []) {
			return $this->badRequest(
				'Falta o ficheiro: envia as fotografias em multipart no campo "file" '
				. '(ou "file[]" para varias).'
			);
		}

		$observations = [];
		$failed = [];

		foreach ($files as $index => $file) {
			try {
				$payloads = $this->scanService->decodeImage($file['bytes'], $file['name']);
			} catch (ScanException $e) {
				$failed[] = ['filename' => $file['name'], 'reason' => $e->getMessage()];
				continue;
			}

			if ($payloads === []) {
				$failed[] = [
					'filename' => $file['name'],
					'reason' => 'Nao se encontrou nenhum codigo nesta imagem. O DataMatrix e pequeno '
						. '-- aproxima-te e garante que esta focado e bem iluminado.',
				];
				continue;
			}

			foreach ($payloads as $payload) {
				$observations[] = [
					'payload' => $payload,
					'from' => sprintf('foto %d (%s)', $index + 1, $file['name']),
				];
			}
		}

		if ($observations === []) {
			return new DataResponse([
				'proposal' => null, 'medicine' => null, 'failed' => $failed,
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(
			$this->scanService->merge($observations, $this->getUserId()) + ['failed' => $failed]
		);
	}

	/**
	 * Grava uma proposta confirmada: cria o medicamento se for novo, e a caixa.
	 *
	 * Os campos vao tal como vao ser gravados -- ja revistos por quem decide.
	 * E deliberado que isto seja um passo separado: uma proposta nao e um
	 * registo, e uma validade lida de uma fotografia pode estar errada de
	 * forma perfeitamente plausivel.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/scan/apply')]
	public function scanApply(array $values = []): DataResponse {
		if ($values === []) {
			return $this->badRequest('Falta "values".');
		}
		try {
			return new DataResponse($this->scanService->apply($values, $this->getUserId()));
		} catch (\InvalidArgumentException $e) {
			return $this->badRequest($e->getMessage());
		} catch (DoesNotExistException) {
			return $this->badRequest('O medicamento indicado nao existe.');
		}
	}

	/**
	 * Normaliza o que o PHP poe em $_FILES, que tem formas diferentes para um
	 * ficheiro e para varios.
	 *
	 * @return list<array{name: string, bytes: string}>
	 */
	private function uploadedFiles(): array {
		$uploaded = $this->request->getUploadedFile('file');
		if (!is_array($uploaded)) {
			return [];
		}

		if (is_array($uploaded['error'] ?? null)) {
			$out = [];
			foreach ($uploaded['error'] as $i => $error) {
				if ($error !== UPLOAD_ERR_OK) {
					continue;
				}
				$out[] = [
					'name' => (string)($uploaded['name'][$i] ?? 'foto.jpg'),
					'bytes' => (string)file_get_contents($uploaded['tmp_name'][$i]),
				];
			}
			return $out;
		}

		if (($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return [];
		}

		return [[
			'name' => (string)($uploaded['name'] ?? 'foto.jpg'),
			'bytes' => (string)file_get_contents($uploaded['tmp_name']),
		]];
	}
}
