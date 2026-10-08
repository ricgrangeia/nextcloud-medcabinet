<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

/**
 * Le os campos de uma embalagem a partir do texto que a IA extraiu da foto.
 *
 * Isto nao chama modelo nenhum. Recebe texto e aplica regras -- de proposito.
 * A validade, o lote e a dosagem sao **transcricao**, nao interpretacao: a
 * resposta certa esta impressa na caixa, letra a letra. Um modelo de
 * linguagem a "ler" uma data pode devolver uma data plausivel que nao esta
 * la, e isso e exactamente o erro que esta app existe para evitar. Uma
 * expressao regular sobre o texto lido nao inventa: ou encontra o que esta
 * escrito, ou nao encontra nada.
 *
 * O que a IA faz melhor -- dizer que aquilo e um Ben-u-ron e nao um Brufen --
 * fica para o modelo de visao. Classificar e dele; transcrever e daqui.
 */
class BoxTextParser {
	/**
	 * Formas farmaceuticas que aparecem escritas nas caixas portuguesas.
	 * A ordem importa: as mais especificas primeiro, senao "solucao oral"
	 * perde para "solucao" e "comprimido efervescente" para "comprimido".
	 */
	private const FORMS = [
		'comprimido revestido' => 'comprimido',
		'comprimido efervescente' => 'comprimido',
		'comprimido' => 'comprimido',
		'capsula' => 'capsula',
		'xarope' => 'xarope',
		'suspensao oral' => 'suspensao',
		'suspensao' => 'suspensao',
		// Antes de "solucao": um colirio e quase sempre "colirio em solucao",
		// e perder isso troca um frasco de olhos por um xarope -- com a
		// validade depois de aberto completamente diferente.
		'colirio' => 'colirio',
		'gotas orais' => 'gotas',
		'gotas' => 'gotas',
		'solucao oral' => 'solucao',
		'solucao injetavel' => 'solucao',
		'solucao' => 'solucao',
		'pomada' => 'pomada',
		'creme' => 'creme',
		'gel' => 'gel',
		'supositorio' => 'supositorio',
		'saqueta' => 'saqueta',
		'po para solucao' => 'saqueta',
		'ampola' => 'ampola',
		'granulado' => 'saqueta',
		'adesivo transdermico' => 'adesivo',
		'inalador' => 'inalador',
		'spray' => 'spray',
	];

	/** Em que se conta o stock, por forma. */
	private const UNITS = [
		'comprimido' => 'comprimido',
		'capsula' => 'capsula',
		'xarope' => 'ml',
		'suspensao' => 'ml',
		'solucao' => 'ml',
		'colirio' => 'gota',
		'gotas' => 'gota',
		'saqueta' => 'saqueta',
		'ampola' => 'ampola',
		'supositorio' => 'supositorio',
		'adesivo' => 'adesivo',
		'inalador' => 'puff',
		'spray' => 'puff',
	];

	/**
	 * @return array{
	 *     values: array<string, mixed>, evidence: array<string, string>,
	 *     warnings: list<string>, expiryIsEndOfMonth: bool, gs1: ?string
	 * }
	 */
	public function parse(string $text): array {
		$values = [];
		$evidence = [];
		$warnings = [];
		$endOfMonth = false;

		$flat = $this->flatten($text);

		// A interpretacao legivel do codigo 2D, que muitas caixas imprimem
		// debaixo dele: (01)05600000000017(17)280331(10)AB123. Se estiver la,
		// vale mais do que procurar cada campo a mao -- vem na ordem da norma
		// e o GTIN tem digito de controlo, que se pode conferir.
		$gs1 = $this->bracketedGs1($text);

		$expiry = $this->expiry($flat);
		if ($expiry !== null) {
			$values['expiry'] = $expiry['date'];
			$evidence['expiry'] = $expiry['raw'];
			$endOfMonth = $expiry['endOfMonth'];
			if ($expiry['endOfMonth']) {
				$warnings[] = 'A caixa dá só o mês de validade ("' . $expiry['raw']
					. '"). Vale até ao último dia desse mês, e é essa a data proposta.';
			}
			if ($expiry['ambiguousYear']) {
				$warnings[] = 'O ano da validade vinha com dois dígitos ("' . $expiry['raw']
					. '"); foi lido como ' . substr($expiry['date'], 0, 4) . '. Confirma.';
			}
		}

		$batch = $this->batch($text);
		if ($batch !== null) {
			$values['batch'] = $batch['value'];
			$evidence['batch'] = $batch['raw'];
		}

		$strength = $this->strength($flat);
		if ($strength !== null) {
			$values['strength'] = $strength['value'];
			$evidence['strength'] = $strength['raw'];
		}

		$form = $this->form($flat);
		if ($form !== null) {
			$values['form'] = $form;
			if (isset(self::UNITS[$form])) {
				$values['unit'] = self::UNITS[$form];
			}
		}

		$units = $this->unitsTotal($flat);
		if ($units !== null) {
			$values['unitsTotal'] = $units['value'];
			$values['unitsLeft'] = $units['value'];
			$evidence['unitsTotal'] = $units['raw'];
		}

		$cnp = $this->cnp($text);
		if ($cnp !== null) {
			$values['cnp'] = $cnp['value'];
			$evidence['cnp'] = $cnp['raw'];
		}

		return [
			'values' => $values,
			'evidence' => $evidence,
			'warnings' => $warnings,
			'expiryIsEndOfMonth' => $endOfMonth,
			'gs1' => $gs1,
		];
	}

	/**
	 * Diz se um valor aparece literalmente no texto lido.
	 *
	 * E isto que separa uma leitura de um palpite. Quando o modelo de visao
	 * propoe uma validade ou um lote, confere-se contra o texto que saiu da
	 * imagem: se nao estiver la, o modelo escreveu-o de cabeca. Compara-se so
	 * digitos e letras, porque "03/2028", "03-2028" e "032028" sao a mesma
	 * coisa impressa de maneiras diferentes.
	 */
	public function corroborates(string $text, string $value): bool {
		$needle = $this->onlyAlnum($value);
		if ($needle === '' || strlen($needle) < 3) {
			// Valores curtos demais acertam por acaso em qualquer texto; dizer
			// que estao confirmados seria pior do que nao dizer nada.
			return false;
		}

		$haystack = $this->onlyAlnum($text);
		if (str_contains($haystack, $needle)) {
			return true;
		}

		// Uma data normalizada (2028-03-31) raramente esta escrita assim na
		// caixa, que diz "03/2028". Confere-se tambem pelo ano e mes juntos,
		// nas duas ordens em que se imprimem.
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
			$yyyy = $m[1];
			$mm = $m[2];
			$yy = substr($yyyy, 2, 2);
			foreach ([$mm . $yyyy, $yyyy . $mm, $mm . $yy, $yy . $mm, $yy . $mm . $m[3]] as $shape) {
				if (str_contains($haystack, $shape)) {
					return true;
				}
			}
		}

		return false;
	}

	// ------------------------------------------------------------- Interno

	private function expiry(string $text): ?array {
		// Com rotulo primeiro. "Val.", "EXP", "Valido ate", "Utilizar ate".
		$labels = 'val(?:idade)?|exp(?:iry|\.)?|valido ate|utilizar ate|usar ate';
		$patterns = [
			// 2028-03-31 / 2028-03
			'/(?:' . $labels . ')\D{0,12}?(\d{4})[-\/.](\d{1,2})(?:[-\/.](\d{1,2}))?/iu',
			// 31/03/2028 / 03/2028 / 03/28
			'/(?:' . $labels . ')\D{0,12}?(?:(\d{1,2})[-\/.])?(\d{1,2})[-\/.](\d{2,4})/iu',
		];

		foreach ($patterns as $i => $pattern) {
			if (preg_match($pattern, $text, $m) !== 1) {
				continue;
			}
			$raw = trim($m[0]);

			if ($i === 0) {
				$year = (int)$m[1];
				$month = (int)$m[2];
				$day = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;
				$ambiguous = false;
			} else {
				$yearRaw = $m[3];
				$year = (int)$yearRaw;
				$ambiguous = strlen($yearRaw) === 2;
				if ($ambiguous) {
					$year += 2000;
				}
				$month = (int)$m[2];
				$day = ($m[1] ?? '') !== '' ? (int)$m[1] : null;
			}

			$built = $this->buildDate($year, $month, $day);
			if ($built === null) {
				continue;
			}

			return [
				'date' => $built,
				'raw' => $raw,
				'endOfMonth' => $day === null,
				'ambiguousYear' => $ambiguous,
			];
		}

		return null;
	}

	/**
	 * Monta a data, com a mesma regra do codigo: so mes significa o ULTIMO dia
	 * desse mes. Arredondar para o dia 1 encurtava a validade um mes inteiro e
	 * punha a app a deitar fora caixas boas.
	 */
	private function buildDate(int $year, int $month, ?int $day): ?string {
		if ($month < 1 || $month > 12) {
			return null;
		}
		// Uma validade impressa fora desta janela nao e uma validade: e um
		// numero qualquer que a leitura apanhou.
		if ($year < 2000 || $year > 2100) {
			return null;
		}

		$first = \DateTimeImmutable::createFromFormat(
			'!Y-m-d', sprintf('%04d-%02d-01', $year, $month)
		);
		if ($first === false) {
			return null;
		}

		if ($day === null) {
			return $first->format('Y-m-t');
		}
		if ($day < 1 || $day > (int)$first->format('t')) {
			return null;
		}

		return $first->setDate($year, $month, $day)->format('Y-m-d');
	}

	private function batch(string $text): ?array {
		$pattern = '/\b(?:lote|lot|l\.)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9\-\/]{1,19})/iu';
		if (preg_match($pattern, $text, $m) !== 1) {
			return null;
		}
		return ['value' => strtoupper(trim($m[1], '-/')), 'raw' => trim($m[0])];
	}

	private function strength(string $text): ?array {
		// mg/ml antes de mg, senao "100 mg/ml" fica "100 mg" -- que e outra
		// dosagem, e numa suspensao pediatrica a diferenca e a dose da crianca.
		$pattern = '/(\d+(?:[.,]\d+)?)\s*(mg\s*\/\s*ml|mcg\s*\/\s*ml|ui\s*\/\s*ml|mg|mcg|ug|µg|g|ml|ui|%)\b/iu';
		if (preg_match($pattern, $text, $m) !== 1) {
			return null;
		}
		$amount = str_replace(',', '.', $m[1]);
		$unit = strtolower(preg_replace('/\s+/', '', $m[2]));
		$unit = $unit === 'ug' ? 'mcg' : $unit;
		$unit = $unit === 'ui' ? 'UI' : $unit;

		// Zeros a direita so se tiram depois da virgula ("1,50" -> "1.5"). Num
		// inteiro sao o numero: tirar-lhos faz de 500 mg um 5 mg, que e uma
		// dose cem vezes menor com o mesmo aspecto de dose.
		if (str_contains($amount, '.')) {
			$amount = rtrim(rtrim($amount, '0'), '.');
		}

		return ['value' => $amount . ' ' . $unit, 'raw' => trim($m[0])];
	}

	private function form(string $text): ?string {
		$lower = mb_strtolower($text);
		foreach (self::FORMS as $needle => $form) {
			if (str_contains($lower, $needle)) {
				return $form;
			}
		}
		return null;
	}

	private function unitsTotal(string $text): ?array {
		$pattern = '/\b(\d{1,4})\s*(comprimidos?|capsulas?|saquetas?|ampolas?|supositorios?|drageias?|adesivos?)\b/iu';
		if (preg_match($pattern, $text, $m) !== 1) {
			// Liquidos: "200 ml" e o conteudo da embalagem.
			if (preg_match('/\b(\d{1,4})\s*ml\b(?!\s*\/)/iu', $text, $m2) === 1) {
				return ['value' => (int)$m2[1], 'raw' => trim($m2[0])];
			}
			return null;
		}
		return ['value' => (int)$m[1], 'raw' => trim($m[0])];
	}

	/**
	 * Codigo Nacional do Medicamento: 7 digitos. Exige-se o rotulo. Sem ele
	 * havia sete digitos quaisquer numa caixa cheia de numeros -- e um codigo
	 * errado associa esta caixa ao medicamento errado da proxima vez.
	 */
	private function cnp(string $text): ?array {
		$pattern = '/\b(?:c\.?\s?n\.?\s?p\.?(?:\s?e\.?\s?m\.?)?|cod(?:igo)?\.?\s*nacional)\s*[:.\-]?\s*(\d{7})\b/iu';
		if (preg_match($pattern, $this->deaccent($text), $m) !== 1) {
			return null;
		}
		return ['value' => $m[1], 'raw' => trim($m[0])];
	}

	/**
	 * A interpretacao legivel do codigo GS1, se a caixa a imprimir:
	 * "(01)05600000000017(17)280331(10)AB123" -> cadeia sem parenteses, que o
	 * GS1Parser sabe ler. Os campos de comprimento variavel ficam terminados
	 * por GS, como na norma, porque e isso que impede o lote de engolir o
	 * campo seguinte.
	 */
	private function bracketedGs1(string $text): ?string {
		if (preg_match_all('/\((\d{2,4})\)\s*([^()\s]+)/', $text, $all, PREG_SET_ORDER) === 0) {
			return null;
		}

		$variable = ['10', '21', '240', '710', '711', '712', '713'];
		$out = '';
		$seen = 0;

		foreach ($all as $m) {
			$ai = $m[1];
			$value = $m[2];
			if (!in_array($ai, ['01', '17', '10', '21', '11', '15'], true)) {
				continue;
			}
			$seen++;
			$out .= $ai . $value;
			if (in_array($ai, $variable, true)) {
				$out .= "\x1d";
			}
		}

		return $seen >= 2 ? rtrim($out, "\x1d") : null;
	}

	/**
	 * Sem acentos e com os espacos normalizados, mas **com as maiusculas**.
	 *
	 * As regras correm todas com /i, por isso nao precisam de minusculas -- e
	 * o texto que elas devolvem vai para "evidence", que existe para se poder
	 * comparar com o que esta impresso na caixa. "Val 03/2028" compara-se;
	 * "val 03/2028" compara-se pior.
	 */
	private function flatten(string $text): string {
		return preg_replace('/\s+/u', ' ', $this->deaccent($text));
	}

	private function deaccent(string $text): string {
		return strtr($text, [
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
			'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
			'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ú' => 'u',
			'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
			'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
			'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Õ' => 'O',
			'Ô' => 'O', 'Ú' => 'U', 'Ç' => 'C',
		]);
	}

	private function onlyAlnum(string $value): string {
		return mb_strtolower((string)preg_replace('/[^a-z0-9]/i', '', $this->deaccent($value)));
	}
}
