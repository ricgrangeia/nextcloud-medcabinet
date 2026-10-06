<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

/**
 * O estado do armario: validades, stock e o que esta prestes a acabar.
 *
 * Nao tem dependencias e nao toca na base de dados -- recebe os dados em
 * bruto de uma embalagem e devolve o que se conclui deles. E assim porque a
 * conclusao que aqui se tira e a que a app existe para dar, e tem de se poder
 * verificar sem servidor nenhum a volta.
 *
 * A regra que molda esta classe: **a validade impressa na caixa deixa de
 * valer no dia em que a embalagem e aberta**. Um xarope, um colirio ou uma
 * insulina duram semanas depois de abertos, mesmo com a caixa a dizer 2028.
 * Mostrar a data impressa a quem tem o frasco aberto ha dois meses nao e um
 * detalhe por afinar: e dizer que esta bom o que ja nao esta.
 *
 * Por isso a validade efetiva e `min(impressa, abertura + dias apos abertura)`
 * -- e quando nao se sabe os dias apos abertura de uma forma que os exige, o
 * estado devolvido diz que nao se sabe, em vez de assumir a data impressa.
 */
class CabinetService {
	/** A partir de quantos dias se considera que esta a expirar. */
	public const EXPIRING_SOON_DAYS = 30;

	public const STATUS_OK = 'ok';
	public const STATUS_EXPIRING = 'expiring';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_UNKNOWN = 'unknown';
	public const STATUS_DISCARDED = 'discarded';

	/**
	 * Formas em que a data impressa deixa de valer depressa depois de abrir.
	 *
	 * Um comprimido num blister nao muda por se ter tirado um; um frasco
	 * aberto muda. Quando uma destas formas esta aberta e nao se sabe quantos
	 * dias dura, a validade fica por saber em vez de se usar a impressa.
	 */
	private const PERISHABLE_ONCE_OPEN = ['xarope', 'colirio', 'gotas', 'suspensao', 'solucao', 'insulina', 'pomada', 'creme'];

	/**
	 * A validade que conta, e de onde veio.
	 *
	 * @return array{
	 *     date: ?string, source: ?string, printed: ?string,
	 *     afterOpening: ?string, unknownAfterOpening: bool
	 * }
	 */
	public function effectiveExpiry(
		?string $printed,
		?string $openedAt,
		?int $daysAfterOpening,
		?string $form,
	): array {
		$afterOpening = null;
		if ($openedAt !== null && $daysAfterOpening !== null && $daysAfterOpening > 0) {
			$afterOpening = (new \DateTimeImmutable($openedAt))
				->modify('+' . $daysAfterOpening . ' days')
				->format('Y-m-d');
		}

		// Aberta, de uma forma que se estraga, e sem saber quanto dura: nao
		// ha validade que se possa afirmar. Devolver a impressa seria dar por
		// boa uma data que a abertura ja invalidou.
		$unknown = $openedAt !== null
			&& $afterOpening === null
			&& $this->perishableOnceOpen($form);

		if ($unknown) {
			return [
				'date' => null, 'source' => null, 'printed' => $printed,
				'afterOpening' => null, 'unknownAfterOpening' => true,
			];
		}

		$candidates = array_filter([$printed, $afterOpening]);
		if ($candidates === []) {
			return [
				'date' => null, 'source' => null, 'printed' => null,
				'afterOpening' => null, 'unknownAfterOpening' => false,
			];
		}

		$date = min($candidates);

		return [
			'date' => $date,
			// Qual das duas mandou. Util para explicar porque e que uma caixa
			// com validade 2028 aparece como fora de prazo.
			'source' => $date === $afterOpening && $afterOpening !== $printed ? 'opened' : 'printed',
			'printed' => $printed,
			'afterOpening' => $afterOpening,
			'unknownAfterOpening' => false,
		];
	}

	/**
	 * O estado de uma embalagem, hoje.
	 *
	 * @param array $package com expiresAt, openedAt, discardedAt, unitsLeft, unitsTotal
	 * @param array $medicine com form, daysAfterOpening, unit
	 */
	public function packageStatus(array $package, array $medicine, string $today): array {
		$expiry = $this->effectiveExpiry(
			$package['expiresAt'] ?? null,
			$package['openedAt'] ?? null,
			$medicine['daysAfterOpening'] ?? null,
			$medicine['form'] ?? null,
		);

		$status = match (true) {
			($package['discardedAt'] ?? null) !== null => self::STATUS_DISCARDED,
			$expiry['date'] === null => self::STATUS_UNKNOWN,
			$expiry['date'] < $today => self::STATUS_EXPIRED,
			$this->daysBetween($today, $expiry['date']) <= self::EXPIRING_SOON_DAYS => self::STATUS_EXPIRING,
			default => self::STATUS_OK,
		};

		$left = $package['unitsLeft'] ?? null;
		$total = $package['unitsTotal'] ?? null;

		return [
			'status' => $status,
			'expiry' => $expiry,
			'daysLeft' => $expiry['date'] === null ? null : $this->daysBetween($today, $expiry['date']),
			'unitsLeft' => $left,
			'unitsTotal' => $total,
			// Percentagem so quando se sabe o total: sem ele, "metade" nao
			// quer dizer nada.
			'fractionLeft' => ($left !== null && $total !== null && $total > 0)
				? round($left / $total, 3)
				: null,
			'isEmpty' => $left !== null && $left <= 0,
			'reason' => $this->explain($status, $expiry),
		];
	}

	/**
	 * Ordena embalagens pela que deve ser usada primeiro.
	 *
	 * Primeiro a que expira mais cedo, nao a mais antiga -- duas caixas do
	 * mesmo medicamento compradas na mesma semana podem ter validades muito
	 * diferentes, e gastar a errada deita a outra fora. As ja abertas vem a
	 * frente das fechadas com a mesma validade: abrir uma segunda caixa tendo
	 * uma aberta e garantir que uma delas se estraga.
	 *
	 * @param list<array> $packages cada um com o resultado de packageStatus()
	 * @return list<array>
	 */
	public function useFirst(array $packages): array {
		$usable = array_values(array_filter($packages, static fn (array $p) =>
			!in_array($p['status'], [self::STATUS_EXPIRED, self::STATUS_DISCARDED], true)
			&& ($p['isEmpty'] ?? false) === false));

		usort($usable, static function (array $a, array $b) {
			// Sem validade conhecida vai para o fim: nao se gasta primeiro o
			// que nao se sabe se presta.
			$da = $a['expiry']['date'] ?? '9999-12-31';
			$db = $b['expiry']['date'] ?? '9999-12-31';
			if ($da !== $db) {
				return $da <=> $db;
			}
			$oa = ($a['openedAt'] ?? null) !== null ? 0 : 1;
			$ob = ($b['openedAt'] ?? null) !== null ? 0 : 1;
			return $oa <=> $ob;
		});

		return $usable;
	}

	private function explain(string $status, array $expiry): ?string {
		if ($status === self::STATUS_UNKNOWN && $expiry['unknownAfterOpening']) {
			return 'Esta aberta e nao se sabe quantos dias dura depois de aberta. '
				. 'A data impressa ja nao serve. Preenche "dias apos abertura" no medicamento.';
		}
		if ($status === self::STATUS_UNKNOWN) {
			return 'Sem validade registada.';
		}
		if (in_array($status, [self::STATUS_EXPIRED, self::STATUS_EXPIRING], true)
			&& $expiry['source'] === 'opened') {
			return sprintf(
				'Conta a abertura, nao a caixa: a caixa diz %s, mas aberta vale ate %s.',
				$expiry['printed'] ?? '?', $expiry['afterOpening']
			);
		}
		return null;
	}

	private function perishableOnceOpen(?string $form): bool {
		if ($form === null) {
			return false;
		}
		$folded = strtr(mb_strtolower($form), ['á'=>'a','ã'=>'a','ç'=>'c','é'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ú'=>'u']);
		foreach (self::PERISHABLE_ONCE_OPEN as $needle) {
			if (str_contains($folded, $needle)) {
				return true;
			}
		}
		return false;
	}

	private function daysBetween(string $from, string $to): int {
		$a = new \DateTimeImmutable($from);
		$b = new \DateTimeImmutable($to);
		return (int)$a->diff($b)->format('%r%a');
	}
}
