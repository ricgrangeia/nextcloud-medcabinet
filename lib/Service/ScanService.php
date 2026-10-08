<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\Package;

/**
 * Registar uma caixa a partir do que se le dela.
 *
 * Esta app **nao trata imagens**, de proposito. Quem fotografa a caixa e le o
 * que esta nela e o agente (appsagent), que ja tem modelo de visao e descobre
 * estas rotas sozinho. Aqui ficam o registo e as regras do dominio, que e
 * onde elas se aguentam: um modelo novo, um agente novo ou uma chamada feita
 * a mao com `curl` continuam a passar por elas.
 *
 * Tres entradas, uma so saida:
 *
 *  - `fromCode()`   -- o conteudo de um DataMatrix descodificado. Exacto, com
 *                      digito de controlo. A entrada mais fiavel que existe.
 *  - `fromText()`   -- o texto que se leu da caixa (o agente extraiu-o da
 *                      fotografia, ou escreveu-se a mao). As regras do
 *                      BoxTextParser encontram nele a validade, o lote e a
 *                      dosagem -- por expressao regular e nao por modelo.
 *  - `merge()`      -- varias leituras da mesma caixa juntas numa proposta.
 *
 * E em todas, **uma proposta nao e um registo**: gravar e `apply()`, e e um
 * passo separado. Quem confirma e quem tem a caixa na mao.
 */
class ScanService {
	/**
	 * Campos que sao transcricao de algo impresso.
	 *
	 * Para estes, o que um modelo diz so vale se o texto lido o contiver. E
	 * aqui que a app deixa de aceitar numeros que ninguem viu: um modelo a
	 * quem falta a validade na fotografia nao responde "nao sei" -- responde
	 * uma data plausivel, e uma data plausivel errada e exactamente o que
	 * esta app existe para evitar.
	 */
	private const TRANSCRIBED = [
		'expiry', 'batch', 'strength', 'unitsTotal', 'unitsLeft', 'cnp', 'gtin',
	];

	public function __construct(
		private GS1Parser $gs1,
		private BoxTextParser $boxText,
		private FieldRules $rules,
		private MedicineMapper $medicines,
		private MedicineService $medicineService,
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

		$medicine = $this->attachKnownMedicine($proposal, $code['gtin'], $userId);

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
	 * Le uma caixa a partir do texto que se leu dela.
	 *
	 * E a porta por onde o agente entra: ele olha para a fotografia e manda o
	 * texto; as regras daqui e que encontram os campos. A diferenca nao e
	 * academica -- um modelo que "le" uma data pode devolver uma data que nao
	 * esta la, e uma expressao regular sobre o texto nao inventa: ou encontra
	 * o que esta escrito, ou nao devolve nada.
	 *
	 * Opcionalmente aceita `$proposed`: campos que o agente ja extraiu. Esses
	 * sao conferidos contra o texto, e o que nao aparecer la fica marcado como
	 * nao confirmado.
	 *
	 * @param array<string, mixed> $proposed
	 * @return array{proposal: array, medicine: ?array, read: array, rejected: array<string, string>}
	 */
	public function fromText(
		string $text,
		string $userId,
		array $proposed = [],
		string $from = 'texto lido da caixa',
	): array {
		$proposal = new ScanProposal();
		$read = $this->boxText->parse($text);

		foreach ($read['warnings'] as $warning) {
			$proposal->warn($warning);
		}

		// A interpretacao legivel do codigo 2D, se a caixa a imprimir debaixo
		// dele: "(01)0560...(17)280331(10)AB1234".
		if ($read['gs1'] !== null) {
			$code = $this->gs1->parse($read['gs1']);
			foreach ($code['warnings'] as $warning) {
				$proposal->warn($warning);
			}
			// Confianca "text" e nao "code": os digitos vieram da leitura do
			// texto, nao de um descodificador. O digito de controlo do GTIN
			// apanha a maior parte dos erros de um digito, nao todos.
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

		$proposal->observe($read['values'], ScanProposal::CONFIDENCE_TEXT, $from);

		// Os campos que o agente ja extraiu. Passam pelas regras de forma e,
		// se forem transcricao, tem de aparecer no texto.
		$rejected = [];
		if ($proposed !== []) {
			$cleaned = $this->rules->clean($proposed);
			$rejected = $cleaned['rejected'];

			foreach ($cleaned['rejected'] as $field => $why) {
				$proposal->warn(sprintf('O campo "%s" não foi aceite: %s.', $field, $why));
			}

			foreach ($cleaned['values'] as $field => $value) {
				$verified = !in_array($field, self::TRANSCRIBED, true)
					|| $this->boxText->corroborates($text, (string)$value);

				$proposal->observe(
					[$field => $value],
					ScanProposal::CONFIDENCE_TEXT,
					'extraído pelo agente',
					$verified
				);
			}
		}

		$result = $proposal->result();
		$medicine = $this->attachKnownMedicine($proposal, $result['values']['gtin'] ?? null, $userId);
		$result = $proposal->result();

		if (!isset($result['values']['expiry'])) {
			$proposal->warn(
				'Não se encontrou validade no texto. É o campo que esta app existe para não '
				. 'errar -- escreve-a a olhar para a caixa.'
			);
			$result = $proposal->result();
		}

		return [
			'proposal' => $result,
			'medicine' => $medicine,
			'read' => ['values' => $read['values'], 'evidence' => $read['evidence']],
			'rejected' => $rejected,
		];
	}

	/**
	 * Junta varias leituras -- codigo, texto, e o que se escreveu a mao --
	 * numa proposta so.
	 *
	 * Uma caixa precisa de mais do que uma leitura: o nome esta na frente, o
	 * DataMatrix com o lote e a validade esta noutro painel, e os dias apos
	 * abertura estao no folheto.
	 *
	 * @param list<array{payload?: string, text?: string, values?: array, from?: string, manual?: bool}> $observations
	 */
	public function merge(array $observations, string $userId): array {
		$proposal = new ScanProposal();
		$medicine = null;
		$rejected = [];

		foreach ($observations as $index => $observation) {
			$from = (string)($observation['from'] ?? sprintf('leitura %d', $index + 1));

			// Um codigo descodificado: exacto, e pode trazer o medicamento
			// que ja se conhece.
			if (($observation['payload'] ?? '') !== '') {
				$one = $this->fromCode((string)$observation['payload'], $userId);
				$medicine ??= $one['medicine'];
				$this->absorb($proposal, $one['proposal'], $from);
			}

			// Texto lido da caixa: as regras daqui encontram-lhe os campos.
			if (trim((string)($observation['text'] ?? '')) !== '') {
				$one = $this->fromText(
					(string)$observation['text'],
					$userId,
					(array)($observation['values'] ?? []),
					$from
				);
				$medicine ??= $one['medicine'];
				$rejected += $one['rejected'];
				$this->absorb($proposal, $one['proposal'], $from);
				continue;
			}

			// Campos sem texto de onde os confirmar. Escritos a mao valem por
			// quem os escreveu; vindos de um modelo, nao ha com que os
			// confirmar -- e isso tem de aparecer.
			if (($observation['values'] ?? []) !== []) {
				$manual = (bool)($observation['manual'] ?? false);
				$cleaned = $this->rules->clean((array)$observation['values']);
				$rejected += $cleaned['rejected'];

				foreach ($cleaned['rejected'] as $field => $why) {
					$proposal->warn(sprintf('O campo "%s" não foi aceite: %s.', $field, $why));
				}

				foreach ($cleaned['values'] as $field => $value) {
					$verified = $manual || !in_array($field, self::TRANSCRIBED, true);
					$proposal->observe(
						[$field => $value],
						$manual ? ScanProposal::CONFIDENCE_MANUAL : ScanProposal::CONFIDENCE_TEXT,
						$from,
						$verified
					);
				}
			}
		}

		$result = $proposal->result();
		if ($medicine === null) {
			$medicine = $this->attachKnownMedicine($proposal, $result['values']['gtin'] ?? null, $userId);
			$result = $proposal->result();
		}

		return ['proposal' => $result, 'medicine' => $medicine, 'rejected' => $rejected];
	}

	/**
	 * Grava uma proposta confirmada: cria o medicamento se for novo, e a caixa.
	 *
	 * Os campos passam pelas regras de forma outra vez, de proposito. Pode
	 * chegar aqui coisa que nunca passou por uma proposta -- um agente pode
	 * chamar isto directamente -- e e este o ultimo sitio antes da base.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function apply(array $values, string $userId): array {
		$cleaned = $this->rules->clean($values);

		if ($cleaned['rejected'] !== []) {
			$parts = [];
			foreach ($cleaned['rejected'] as $field => $why) {
				$parts[] = sprintf('%s (%s)', $field, $why);
			}
			// Recusa-se tudo e nao so o campo mau. Gravar uma caixa sem a
			// validade que se achava que ia gravada e pior do que nao gravar:
			// fica um registo com ar de completo.
			throw new \InvalidArgumentException(
				'Estes campos não foram aceites: ' . implode('; ', $parts)
				. '. Nada foi gravado.'
			);
		}

		$values = $cleaned['values'];
		$medicineId = isset($values['medicineId']) ? (int)$values['medicineId'] : null;

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

	// ------------------------------------------------------------- Interno

	/**
	 * Se esta caixa ja foi registada, o registo anterior manda: foi
	 * confirmado por uma pessoa. E o que faz a segunda leitura da mesma
	 * embalagem preencher-se sozinha.
	 */
	private function attachKnownMedicine(
		ScanProposal $proposal,
		mixed $gtin,
		string $userId,
	): ?array {
		if ($gtin === null || $gtin === '') {
			return null;
		}

		$found = $this->medicines->findByGtin((string)$gtin, $userId);
		if ($found === null) {
			$proposal->warn(
				'Este código ainda não está associado a nenhum medicamento. Dá-lhe o nome uma '
				. 'vez e, da próxima, a caixa preenche-se sozinha.'
			);
			return null;
		}

		$proposal->observe([
			'medicineId' => $found->getId(),
			'name' => $found->getName(),
			'substance' => $found->getSubstance(),
			'strength' => $found->getStrength(),
			'form' => $found->getForm(),
			'unit' => $found->getUnit(),
		], ScanProposal::CONFIDENCE_MANUAL, 'registo anterior desta caixa');

		return $found->jsonSerialize();
	}

	/** Passa os campos de uma proposta para outra, mantendo a confianca. */
	private function absorb(ScanProposal $target, array $source, string $from): void {
		foreach ($source['fields'] as $field => $entry) {
			$target->observe(
				[$field => $entry['value']],
				$entry['confidence'],
				$from,
				$entry['verified'] ?? true
			);
		}
		foreach ($source['warnings'] as $warning) {
			$target->warn($warning);
		}
	}
}
