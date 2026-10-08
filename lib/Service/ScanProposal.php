<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

/**
 * Junta o que varias fontes dizem sobre uma mesma caixa, numa proposta.
 *
 * Uma caixa precisa de mais do que uma foto: o nome esta na frente, o
 * DataMatrix com o lote e a validade esta noutro painel, e os dias apos
 * abertura estao no folheto. Cada foto contribui com o que sabe, e isto
 * junta-as.
 *
 * Nao tem dependencias e nao grava nada. A razao e a mesma de sempre, e aqui
 * e mais forte: **uma proposta nao e um registo**. O que vem de um codigo e
 * exacto e pode ser gravado; o que vem de texto lido de uma fotografia e um
 * palpite com boa aparencia -- um "7" lido como "1" desloca a validade seis
 * anos e continua a parecer uma data perfeitamente normal. Por isso cada
 * campo guarda de onde veio e com que confianca, e os desacordos entre fotos
 * aparecem em vez de serem resolvidos a sorte.
 */
class ScanProposal {
	/** Lido de um codigo de barras: exacto, verificavel por digito de controlo. */
	public const CONFIDENCE_CODE = 'code';
	/** Lido de texto numa fotografia: para confirmar. */
	public const CONFIDENCE_TEXT = 'text';
	/** Escrito por quem esta a usar a app. */
	public const CONFIDENCE_MANUAL = 'manual';

	/**
	 * Quais campos podem ser gravados sem confirmacao.
	 *
	 * So os que vem de um codigo. Nao e desconfianca da leitura de texto: e
	 * que o erro dela e silencioso, e uma validade errada nesta app tem a
	 * consequencia exacta que ela existe para evitar.
	 */
	private const TRUSTED = [self::CONFIDENCE_CODE, self::CONFIDENCE_MANUAL];

	private array $fields = [];
	private array $conflicts = [];
	private array $warnings = [];

	/**
	 * Acrescenta o que uma fonte diz.
	 *
	 * @param array<string, mixed> $values campos observados, null e ignorado
	 * @param string $confidence CONFIDENCE_*
	 * @param string $from de onde veio, para se poder explicar depois
	 * @param bool $verified se o valor foi confirmado contra a fonte. Falso
	 *   quando um modelo de visao propoe um numero que o texto lido da imagem
	 *   nao contem -- ou seja, quando o modelo o escreveu de cabeca.
	 */
	public function observe(array $values, string $confidence, string $from, bool $verified = true): void {
		foreach ($values as $field => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$existing = $this->fields[$field] ?? null;

			if ($existing === null) {
				$this->fields[$field] = [
					'value' => $value, 'confidence' => $confidence, 'from' => $from,
					'verified' => $verified,
				];
				continue;
			}

			if ((string)$existing['value'] === (string)$value) {
				// Duas fontes a dizer o mesmo: fica a mais fiavel das duas, e
				// entre iguais fica a confirmada -- o valor e o mesmo, mas
				// poder dizer que se confirmou muda o que se faz com ele.
				$better = $this->rank($confidence) > $this->rank($existing['confidence'])
					|| ($this->rank($confidence) === $this->rank($existing['confidence'])
						&& $verified && !($existing['verified'] ?? true));
				if ($better) {
					$this->fields[$field] = [
						'value' => $value, 'confidence' => $confidence, 'from' => $from,
					];
				}
				continue;
			}

			// Discordam. Um codigo ganha a um texto, porque e verificavel.
			// Entre fontes da mesma confianca nao se escolhe: fica o primeiro
			// e o desacordo e registado, para quem decide o ver.
			if ($this->rank($confidence) > $this->rank($existing['confidence'])) {
				$this->fields[$field] = [
					'value' => $value, 'confidence' => $confidence, 'from' => $from,
					'verified' => $verified,
				];
			}

			$this->conflicts[$field][] = [
				'value' => $value, 'confidence' => $confidence, 'from' => $from,
				'verified' => $verified,
			];
			if (!$this->hasConflictEntry($field, $existing)) {
				$this->conflicts[$field][] = $existing;
			}
		}
	}

	public function warn(string $message): void {
		if (!in_array($message, $this->warnings, true)) {
			$this->warnings[] = $message;
		}
	}

	/**
	 * @return array{
	 *     fields: array<string, array>, values: array<string, mixed>,
	 *     conflicts: array<string, list<array>>, needsReview: list<string>,
	 *     unverified: list<string>, warnings: list<string>, canSaveDirectly: bool
	 * }
	 */
	public function result(): array {
		$values = [];
		$needsReview = [];
		$unverified = [];

		foreach ($this->fields as $field => $entry) {
			$values[$field] = $entry['value'];
			if (!in_array($entry['confidence'], self::TRUSTED, true)) {
				$needsReview[] = $field;
			}
			// Nao confirmado nunca e de confianca, venha de onde vier: e um
			// valor que ninguem conseguiu encontrar na fonte.
			if (($entry['verified'] ?? true) === false) {
				$unverified[] = $field;
				if (!in_array($field, $needsReview, true)) {
					$needsReview[] = $field;
				}
			}
		}

		foreach (array_keys($this->conflicts) as $field) {
			if (!in_array($field, $needsReview, true)) {
				$needsReview[] = $field;
			}
		}

		sort($needsReview);

		foreach ($this->conflicts as $field => $entries) {
			$this->warn(sprintf(
				'As fontes discordam sobre "%s": %s. Confirma qual vale.',
				$field,
				implode(' / ', array_map(
					static fn (array $e) => sprintf('%s (%s)', $e['value'], $e['from']),
					$entries
				))
			));
		}

		if ($unverified !== []) {
			sort($unverified);
			$this->warn(sprintf(
				'Não foi possível confirmar no texto da imagem: %s. Um modelo que não encontra o '
				. 'campo tende a escrever um valor plausível em vez de nenhum -- confirma na caixa '
				. 'antes de gravar.',
				implode(', ', $unverified)
			));
		}

		return [
			'fields' => $this->fields,
			'values' => $values,
			'conflicts' => $this->conflicts,
			'needsReview' => $needsReview,
			'unverified' => $unverified,
			'warnings' => $this->warnings,
			// Verdadeiro so quando tudo o que esta preenchido vem de fonte
			// fiavel e nada discorda. E o que decide se a app pode gravar
			// sozinha ou tem de perguntar.
			'canSaveDirectly' => $needsReview === [] && $this->fields !== [],
		];
	}

	private function rank(string $confidence): int {
		return match ($confidence) {
			self::CONFIDENCE_MANUAL => 3,
			self::CONFIDENCE_CODE => 2,
			self::CONFIDENCE_TEXT => 1,
			default => 0,
		};
	}

	private function hasConflictEntry(string $field, array $entry): bool {
		foreach ($this->conflicts[$field] ?? [] as $existing) {
			if ((string)$existing['value'] === (string)$entry['value']
				&& $existing['from'] === $entry['from']) {
				return true;
			}
		}
		return false;
	}
}
