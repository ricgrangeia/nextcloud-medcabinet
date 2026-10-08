<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

/**
 * O que um campo tem de ser para ser aceito.
 *
 * Isto esta aqui, e nao no agente, por uma razao de desenho: **quem escreve o
 * registo e quem tem de o validar**. O agente (appsagent) le a caixa com um
 * modelo de visao e propoe campos; se a regra vivesse la, um agente novo, uma
 * versao nova do modelo ou uma chamada feita a mao com `curl` entravam sem
 * passar por ela. A app e o ultimo sitio antes da base de dados.
 *
 * As regras sao de forma, nao de plausibilidade. "2028-02-30" e rejeitada
 * porque esse dia nao existe -- nao porque pareca improvavel. Rejeitar por
 * parecer estranho dava uma app que discute com quem tem a caixa na mao.
 */
class FieldRules {
	/** As formas que a app conhece. Fora desta lista nao e uma forma. */
	public const FORMS = [
		'comprimido', 'capsula', 'xarope', 'suspensao', 'solucao', 'colirio', 'gotas',
		'pomada', 'creme', 'gel', 'supositorio', 'saqueta', 'ampola', 'adesivo',
		'inalador', 'spray',
	];

	/**
	 * Limpa um conjunto de campos, dizendo o que recusou e porque.
	 *
	 * Recusar em silencio era o pior dos mundos: o agente ficava a pensar que
	 * tinha gravado a validade, e a caixa ficava sem nenhuma.
	 *
	 * @param array<string, mixed> $values
	 * @return array{values: array<string, mixed>, rejected: array<string, string>}
	 */
	public function clean(array $values): array {
		$clean = [];
		$rejected = [];

		foreach ($values as $field => $value) {
			if ($value === null || $value === '' || $value === 'null') {
				continue;
			}

			$result = $this->field((string)$field, $value);
			if ($result['ok']) {
				$clean[$field] = $result['value'];
			} else {
				$rejected[$field] = $result['why'];
			}
		}

		return ['values' => $clean, 'rejected' => $rejected];
	}

	/**
	 * @return array{ok: bool, value?: mixed, why?: string}
	 */
	public function field(string $field, mixed $value): array {
		if (is_array($value)) {
			return ['ok' => false, 'why' => 'esperava-se um valor, não uma lista'];
		}

		$raw = trim((string)$value);
		if ($raw === '') {
			return ['ok' => false, 'why' => 'veio vazio'];
		}

		return match ($field) {
			'expiry', 'openedAt', 'discardedAt' => $this->date($raw),
			'form' => $this->form($raw),
			'unitsTotal', 'unitsLeft', 'daysAfterOpening' => $this->quantity($raw),
			'medicineId' => $this->id($raw),
			'batch' => $this->batch($raw),
			'gtin' => $this->gtin($raw),
			'cnp' => preg_match('/^\d{7}$/', $raw) === 1
				? ['ok' => true, 'value' => $raw]
				: ['ok' => false, 'why' => 'o código nacional tem sete dígitos'],
			default => ['ok' => true, 'value' => mb_substr($raw, 0, 192)],
		};
	}

	/**
	 * Uma data tem de ser um dia que existe.
	 *
	 * "2028-02-30" vem com ar de data e nao e nenhuma. Aceita tambem
	 * "AAAA-MM", e ai vale o ULTIMO dia do mes -- a mesma regra do dia "00" do
	 * codigo GS1 e do que esta impresso nas caixas. Posta a dia 1, encurtava a
	 * validade um mes inteiro.
	 *
	 * @return array{ok: bool, value?: string, why?: string}
	 */
	public function date(string $raw): array {
		if (preg_match('/^(\d{4})-(\d{1,2})(?:-(\d{1,2}))?$/', $raw, $m) !== 1) {
			return ['ok' => false, 'why' => 'formato esperado AAAA-MM-DD (ou AAAA-MM)'];
		}

		$year = (int)$m[1];
		$month = (int)$m[2];

		if ($month < 1 || $month > 12) {
			return ['ok' => false, 'why' => sprintf('não há mês %d', $month)];
		}
		if ($year < 1900 || $year > 2100) {
			return ['ok' => false, 'why' => sprintf('o ano %d está fora do que faz sentido', $year)];
		}

		$first = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month));
		if ($first === false) {
			return ['ok' => false, 'why' => 'não é uma data'];
		}

		if (!isset($m[3]) || $m[3] === '') {
			return ['ok' => true, 'value' => $first->format('Y-m-t')];
		}

		$day = (int)$m[3];
		$last = (int)$first->format('t');
		if ($day < 1 || $day > $last) {
			return [
				'ok' => false,
				'why' => sprintf('%s de %04d só tem %d dias', $first->format('F'), $year, $last),
			];
		}

		return ['ok' => true, 'value' => $first->setDate($year, $month, $day)->format('Y-m-d')];
	}

	/** @return array{ok: bool, value?: string, why?: string} */
	private function form(string $raw): array {
		$lower = $this->deaccent(mb_strtolower($raw));
		if (in_array($lower, self::FORMS, true)) {
			return ['ok' => true, 'value' => $lower];
		}
		return [
			'ok' => false,
			'why' => 'forma desconhecida; esperava-se uma de: ' . implode(', ', self::FORMS),
		];
	}

	/** @return array{ok: bool, value?: int, why?: string} */
	private function quantity(string $raw): array {
		$normalised = str_replace(',', '.', $raw);
		if (preg_match('/^\d{1,5}(\.\d+)?$/', $normalised) !== 1) {
			return ['ok' => false, 'why' => 'esperava-se um número'];
		}
		return ['ok' => true, 'value' => (int)round((float)$normalised)];
	}

	/** @return array{ok: bool, value?: int, why?: string} */
	private function id(string $raw): array {
		if (preg_match('/^\d{1,19}$/', $raw) !== 1 || (int)$raw <= 0) {
			return ['ok' => false, 'why' => 'esperava-se um id'];
		}
		return ['ok' => true, 'value' => (int)$raw];
	}

	/** @return array{ok: bool, value?: string, why?: string} */
	private function batch(string $raw): array {
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/]{0,31}$/', $raw) !== 1) {
			return ['ok' => false, 'why' => 'um lote são letras e dígitos, sem espaços'];
		}
		return ['ok' => true, 'value' => strtoupper($raw)];
	}

	/**
	 * O codigo do produto leva digito de controlo.
	 *
	 * Um GTIN errado nao e um campo errado qualquer: e o que associa esta
	 * caixa a um medicamento na leitura seguinte. Associado ao errado, passa a
	 * preencher-se sozinho com o nome errado -- pior do que nao ter codigo.
	 *
	 * @return array{ok: bool, value?: string, why?: string}
	 */
	private function gtin(string $raw): array {
		$digits = preg_replace('/\D/', '', $raw);
		if (!in_array(strlen((string)$digits), [8, 12, 13, 14], true)) {
			return ['ok' => false, 'why' => 'um GTIN tem 8, 12, 13 ou 14 dígitos'];
		}

		$padded = str_pad((string)$digits, 14, '0', STR_PAD_LEFT);
		$sum = 0;
		for ($i = 0; $i < 13; $i++) {
			$sum += (int)$padded[$i] * ($i % 2 === 0 ? 3 : 1);
		}
		if ((10 - $sum % 10) % 10 !== (int)$padded[13]) {
			return ['ok' => false, 'why' => 'o dígito de controlo não bate: o código está mal lido'];
		}

		return ['ok' => true, 'value' => $padded];
	}

	private function deaccent(string $text): string {
		return strtr($text, [
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
			'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
		]);
	}
}
