<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

/**
 * Interpreta o conteudo do DataMatrix de uma caixa de medicamento.
 *
 * Desde a directiva dos medicamentos falsificados, as embalagens de
 * medicamentos sujeitos a receita trazem um DataMatrix com os dados em formato
 * GS1 Element String: pares de identificador de aplicacao (AI) e valor,
 * encadeados sem separador quando o campo tem comprimento fixo.
 *
 * Nao tem dependencias e nao toca na base de dados, porque e aqui que um erro
 * passa despercebido: uma validade mal lida nao falha -- fica com outra data,
 * igualmente plausivel. E uma caixa dada como boa dois anos depois de o estar
 * e exactamente o que esta app existe para evitar.
 *
 * Quatro coisas que esta cadeia faz e que nao sao obvias:
 *
 *  1. A data vem como AAMMDD de seis digitos, sem seculo. "280331" e marco de
 *     2028, nao de 1928.
 *
 *  2. **O dia pode ser "00"**, e significa fim do mes -- nao o dia zero.
 *     "280300" e 31 de marco de 2028. Interpretado ao pe da letra da uma data
 *     invalida; arredondado para o dia 1 encurta a validade um mes inteiro.
 *
 *  3. Os campos de comprimento variavel (lote, numero de serie) terminam no
 *     separador GS (ASCII 29) ou no fim da cadeia. Sem respeitar isso, o lote
 *     engole o campo seguinte.
 *
 *  4. A cadeia pode vir com o prefixo "]d2" (identificador de simbologia do
 *     DataMatrix), que nao faz parte dos dados.
 */
class GS1Parser {
	/** Separador de campos de comprimento variavel. */
	private const GS = "\x1d";

	/**
	 * Identificadores de aplicacao que interessam a uma caixa de medicamento,
	 * e o comprimento FIXO dos seus valores. Os que nao estao aqui sao de
	 * comprimento variavel e vao ate ao separador.
	 */
	private const FIXED_LENGTH = [
		'01' => 14,  // GTIN
		'17' => 6,   // validade, AAMMDD
		'11' => 6,   // data de fabrico
		'15' => 6,   // melhor antes
	];

	/** De comprimento variavel, ate ao separador GS. */
	private const VARIABLE = ['10', '21', '240', '710', '711', '712', '713'];

	/**
	 * @return array{
	 *     gtin: ?string, expiry: ?string, batch: ?string, serial: ?string,
	 *     expiryIsEndOfMonth: bool, raw: string, fields: array<string, string>,
	 *     warnings: list<string>
	 * }
	 */
	public function parse(string $payload): array {
		$warnings = [];
		$raw = $payload;

		// O prefixo de simbologia nao faz parte dos dados.
		$payload = preg_replace('/^\]d2/', '', $payload) ?? $payload;
		// Alguns leitores devolvem o GS como texto visivel.
		$payload = str_replace(['<GS>', '{GS}'], self::GS, $payload);

		$fields = [];
		$i = 0;
		$length = strlen($payload);

		while ($i < $length) {
			if ($payload[$i] === self::GS) {
				$i++;
				continue;
			}

			$ai = $this->readAi($payload, $i);
			if ($ai === null) {
				$warnings[] = sprintf(
					'Nao se reconheceu o identificador na posicao %d. O resto da cadeia foi ignorado.',
					$i
				);
				break;
			}

			$i += strlen($ai);

			if (isset(self::FIXED_LENGTH[$ai])) {
				$value = substr($payload, $i, self::FIXED_LENGTH[$ai]);
				if (strlen($value) < self::FIXED_LENGTH[$ai]) {
					$warnings[] = sprintf('O campo %s esta truncado e foi ignorado.', $ai);
					break;
				}
				$i += self::FIXED_LENGTH[$ai];
			} else {
				$end = strpos($payload, self::GS, $i);
				$value = $end === false ? substr($payload, $i) : substr($payload, $i, $end - $i);
				$i += strlen($value);
			}

			$fields[$ai] = $value;
		}

		[$expiry, $endOfMonth, $expiryWarning] = $this->date($fields['17'] ?? null);
		if ($expiryWarning !== null) {
			$warnings[] = $expiryWarning;
		}

		$gtin = $fields['01'] ?? null;
		if ($gtin !== null && !$this->gtinChecksumOk($gtin)) {
			// O GTIN tem digito de controlo. Falhar significa leitura
			// defeituosa: melhor nao o guardar do que guardar um codigo que
			// vai emparelhar com o medicamento errado na proxima leitura.
			$warnings[] = 'O codigo do produto nao passa o digito de controlo. Nao foi guardado.';
			$gtin = null;
		}

		return [
			'gtin' => $gtin,
			'expiry' => $expiry,
			'expiryIsEndOfMonth' => $endOfMonth,
			'batch' => $fields['10'] ?? null,
			'serial' => $fields['21'] ?? null,
			'fields' => $fields,
			'raw' => $raw,
			'warnings' => $warnings,
		];
	}

	/**
	 * Le o identificador de aplicacao a partir de uma posicao.
	 *
	 * Quase todos tem dois digitos, mas alguns tem tres ou quatro. Testa-se
	 * do mais especifico para o menos.
	 */
	private function readAi(string $payload, int $at): ?string {
		foreach ([4, 3, 2] as $size) {
			$candidate = substr($payload, $at, $size);
			if (strlen($candidate) < $size || !ctype_digit($candidate)) {
				continue;
			}
			if (isset(self::FIXED_LENGTH[$candidate]) || in_array($candidate, self::VARIABLE, true)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * AAMMDD -> AAAA-MM-DD.
	 *
	 * Com dia "00", a norma GS1 diz fim do mes. Interpretado ao pe da letra
	 * dava uma data invalida; posto a dia 1 encurtava a validade um mes
	 * inteiro, o que faria a app deitar fora medicamentos bons.
	 *
	 * @return array{0: ?string, 1: bool, 2: ?string}
	 */
	private function date(?string $value): array {
		if ($value === null || !preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
			return [null, false, $value === null ? null : 'A validade no codigo nao tem o formato AAMMDD.'];
		}

		$year = 2000 + (int)$m[1];
		$month = (int)$m[2];
		$day = (int)$m[3];

		if ($month < 1 || $month > 12) {
			return [null, false, sprintf('A validade no codigo traz o mes %s, que nao existe.', $m[2])];
		}

		$endOfMonth = $day === 0;
		if ($endOfMonth) {
			$day = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
				->format('t');
		}

		if (!checkdate($month, $day, $year)) {
			return [null, false, sprintf('A validade no codigo (%s) nao e uma data valida.', $value)];
		}

		return [sprintf('%04d-%02d-%02d', $year, $month, $day), $endOfMonth, null];
	}

	/**
	 * Digito de controlo do GTIN-14 (modulo 10, pesos 3 e 1 alternados).
	 */
	private function gtinChecksumOk(string $gtin): bool {
		if (!preg_match('/^\d{14}$/', $gtin)) {
			return false;
		}
		$sum = 0;
		for ($i = 0; $i < 13; $i++) {
			$sum += (int)$gtin[$i] * ($i % 2 === 0 ? 3 : 1);
		}
		return (10 - $sum % 10) % 10 === (int)$gtin[13];
	}
}
