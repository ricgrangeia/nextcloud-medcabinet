<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\AppInfo\Application;
use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\Package;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Registar uma caixa a partir do que se le dela.
 *
 * Duas entradas, uma so saida. A entrada pode ser o conteudo de um codigo
 * (lido por uma app de telefone, por um leitor, ou colado a mao) ou
 * fotografias da embalagem. Em ambos os casos o resultado e uma PROPOSTA, e
 * nunca um registo: quem confirma e quem esta a usar a app.
 *
 * Interpretar o codigo e separado de o ler de proposito. Significa que o
 * endpoint de interpretacao funciona hoje, com qualquer leitor que haja a
 * mao, sem depender de a app saber descodificar imagens -- e quando essa
 * leitura existir, entra pelo mesmo sitio.
 */
class ScanService {
	private const CONFIG_READER_URL = 'code_reader_url';
	private const CONFIG_READER_PATH = 'code_reader_path';

	public function __construct(
		private GS1Parser $gs1,
		private MedicineMapper $medicines,
		private MedicineService $medicineService,
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Interpreta o conteudo de um codigo de uma caixa.
	 *
	 * @return array{proposal: array, medicine: ?array, code: array}
	 */
	public function fromCode(string $payload, string $userId): array {
		$code = $this->gs1->parse($payload);
		$proposal = new ScanProposal();

		foreach ($code['warnings'] as $warning) {
			$proposal->warn($warning);
		}

		$proposal->observe([
			'gtin' => $code['gtin'],
			'expiry' => $code['expiry'],
			'batch' => $code['batch'],
		], ScanProposal::CONFIDENCE_CODE, 'código da caixa');

		if ($code['expiryIsEndOfMonth']) {
			$proposal->warn(
				'O código dá só o mês de validade; a norma GS1 diz que vale até ao último dia, '
				. 'e é essa a data proposta.'
			);
		}

		// O codigo identifica o produto mas nao o nomeia. Se esta caixa ja foi
		// registada antes, o nome vem do registo anterior -- e por isso que a
		// segunda leitura da mesma embalagem se preenche sozinha.
		$medicine = null;
		if ($code['gtin'] !== null) {
			$found = $this->medicines->findByGtin($code['gtin'], $userId);
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
			} else {
				$proposal->warn(
					'Este código ainda não está associado a nenhum medicamento. Dá-lhe o nome uma '
					. 'vez e, da próxima, a caixa preenche-se sozinha.'
				);
			}
		}

		if ($code['expiry'] === null) {
			$proposal->warn(
				'O código não trouxe validade. Caixas de medicamentos não sujeitos a receita muitas '
				. 'vezes só têm o código do produto -- nesse caso a validade tem de ser escrita.'
			);
		}

		return [
			'proposal' => $proposal->result(),
			'medicine' => $medicine,
			'code' => [
				'gtin' => $code['gtin'],
				'expiry' => $code['expiry'],
				'batch' => $code['batch'],
				'serial' => $code['serial'],
				'fields' => $code['fields'],
			],
		];
	}

	/**
	 * Junta varias leituras -- varias fotos, ou codigo mais o que se escreveu
	 * -- numa proposta so.
	 *
	 * @param list<array{payload?: string, values?: array, from?: string}> $observations
	 */
	public function merge(array $observations, string $userId): array {
		$proposal = new ScanProposal();
		$medicine = null;

		foreach ($observations as $index => $observation) {
			$from = (string)($observation['from'] ?? sprintf('leitura %d', $index + 1));

            // Um codigo: exacto, e pode trazer o medicamento ja conhecido.
			if (($observation['payload'] ?? '') !== '') {
				$one = $this->fromCode((string)$observation['payload'], $userId);
				$medicine ??= $one['medicine'];
				foreach ($one['proposal']['fields'] as $field => $entry) {
					$proposal->observe([$field => $entry['value']], $entry['confidence'], $from);
				}
				foreach ($one['proposal']['warnings'] as $warning) {
					$proposal->warn($warning);
				}
			}

			// Texto lido de uma fotografia, ou escrito a mao.
			if (($observation['values'] ?? []) !== []) {
				$confidence = ($observation['manual'] ?? false)
					? ScanProposal::CONFIDENCE_MANUAL
					: ScanProposal::CONFIDENCE_TEXT;
				$proposal->observe((array)$observation['values'], $confidence, $from);
			}
		}

		return ['proposal' => $proposal->result(), 'medicine' => $medicine];
	}

	/**
	 * Grava uma proposta confirmada: cria o medicamento se for novo, e a caixa.
	 *
	 * @param array $values os campos tal como vao ser gravados -- ja revistos
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function apply(array $values, string $userId): array {
		$medicineId = isset($values['medicineId']) && $values['medicineId'] !== ''
			? (int)$values['medicineId']
			: null;

		if ($medicineId === null) {
			$name = trim((string)($values['name'] ?? ''));
			if ($name === '') {
				throw new \InvalidArgumentException(
					'Falta o nome do medicamento. O código identifica o produto mas não o nomeia.'
				);
			}
			$created = $this->medicineService->create($userId, [
				'name' => $name,
				'substance' => $values['substance'] ?? null,
				'strength' => $values['strength'] ?? null,
				'form' => $values['form'] ?? null,
				'unit' => $values['unit'] ?? null,
				'daysAfterOpening' => $values['daysAfterOpening'] ?? null,
				'gtin' => $values['gtin'] ?? null,
			]);
			$medicineId = (int)$created['id'];
		}

		$package = $this->medicineService->addPackage($medicineId, $userId, [
			'unitsTotal' => $values['unitsTotal'] ?? null,
			'unitsLeft' => $values['unitsLeft'] ?? null,
			'expiresAt' => $values['expiry'] ?? null,
			'batch' => $values['batch'] ?? null,
			'location' => $values['location'] ?? null,
			// Marcar a origem nao e contabilidade: saber que uma validade foi
			// LIDA e nao escrita muda a confianca que se lhe da.
			'source' => ($values['source'] ?? null) === Package::SOURCE_DATAMATRIX
				? Package::SOURCE_DATAMATRIX
				: Package::SOURCE_MANUAL,
		]);

		return ['medicineId' => $medicineId, 'package' => $package];
	}

	// ------------------------------------------------- Leitura de fotografias

	public function readerUrl(): ?string {
		$url = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_READER_URL, ''));
		return $url === '' ? null : rtrim($url, '/');
	}

	/**
	 * Se a leitura de fotografias esta disponivel, e o que falta se nao.
	 *
	 * Dito em vez de escondido: sem isto configurado, o caminho da fotografia
	 * nao existe, e um botao que nao funciona e pior do que um botao ausente.
	 */
	public function readerStatus(): array {
		$url = $this->readerUrl();
		if ($url === null) {
			return [
				'available' => false,
				'reason' => 'Não há serviço de leitura de códigos configurado. Define "'
					. self::CONFIG_READER_URL . '" na configuração da app, apontando para um serviço '
					. 'que leia DataMatrix de uma imagem.',
			];
		}
		return ['available' => true, 'url' => $url];
	}

	/**
	 * Manda uma imagem ao servico de leitura e devolve os codigos encontrados.
	 *
	 * @return list<string>
	 * @throws ScanException
	 */
	public function decodeImage(string $contents, string $filename): array {
		$url = $this->readerUrl();
		if ($url === null) {
			throw new ScanException($this->readerStatus()['reason']);
		}

		$path = $this->appConfig->getValueString(
			Application::APP_ID, self::CONFIG_READER_PATH, '/api/v1/image/scan'
		);

		try {
			$response = $this->clientService->newClient()->post($url . $path, [
				'multipart' => [[
					'name' => 'file', 'contents' => $contents, 'filename' => $filename,
				]],
				'connect_timeout' => 15,
				'timeout' => 120,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Falhou a leitura da imagem', ['exception' => $e, 'url' => $url]);
			throw new ScanException('Não foi possível contactar o serviço de leitura (' . $url . ').');
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (!is_array($decoded)) {
			throw new ScanException('O serviço de leitura devolveu uma resposta que não se percebeu.');
		}

		return $this->collectPayloads($decoded);
	}

	/**
	 * Vai buscar as cadeias de codigo a uma resposta, sem assumir a forma.
	 *
	 * Servicos de leitura diferentes devolvem formas diferentes -- uma lista
	 * de objectos, um objecto com "codes", um campo "raw_content". Em vez de
	 * fixar uma, procura-se recursivamente o que parece conteudo de codigo.
	 * Uma cadeia GS1 reconhece-se: comeca por um identificador de aplicacao.
	 *
	 * @return list<string>
	 */
	private function collectPayloads(array $data): array {
		$found = [];

		$walk = function (mixed $node) use (&$walk, &$found): void {
			if (is_string($node)) {
				if (preg_match('/^(\]d2)?(01|17|10|21)\d/', $node) === 1) {
					$found[] = $node;
				}
				return;
			}
			if (is_array($node)) {
				foreach ($node as $child) {
					$walk($child);
				}
			}
		};

		$walk($data);

		return array_values(array_unique($found));
	}
}
