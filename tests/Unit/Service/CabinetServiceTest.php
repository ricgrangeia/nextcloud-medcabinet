<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Service\CabinetService;
use PHPUnit\Framework\TestCase;

class CabinetServiceTest extends TestCase {
	private CabinetService $service;

	protected function setUp(): void {
		$this->service = new CabinetService();
	}

	private function pkg(array $over = []): array {
		return array_merge([
			'expiresAt' => '2028-01-31', 'openedAt' => null, 'discardedAt' => null,
			'unitsLeft' => 20.0, 'unitsTotal' => 20.0,
		], $over);
	}

	private function med(array $over = []): array {
		return array_merge(['form' => 'comprimido', 'daysAfterOpening' => null, 'unit' => 'comprimido'], $over);
	}

	public function testCaixaFechadaUsaAValidadeImpressa(): void {
		$s = $this->service->packageStatus($this->pkg(), $this->med(), '2026-10-07');

		$this->assertSame(CabinetService::STATUS_OK, $s['status']);
		$this->assertSame('2028-01-31', $s['expiry']['date']);
		$this->assertSame('printed', $s['expiry']['source']);
	}

	/**
	 * O comportamento central desta classe. Um xarope com a caixa a dizer
	 * 2028, aberto ha dois meses e valido 28 dias depois de aberto, esta mau.
	 * Mostrar 2028 a quem tem o frasco na mao nao e um detalhe por afinar: e
	 * dizer que esta bom o que ja nao esta.
	 */
	public function testAberturaManda_EUmXaropeDe2028PodeEstarForaDePrazo(): void {
		$s = $this->service->packageStatus(
			$this->pkg(['expiresAt' => '2028-01-31', 'openedAt' => '2026-08-01']),
			$this->med(['form' => 'xarope', 'daysAfterOpening' => 28]),
			'2026-10-07'
		);

		$this->assertSame(CabinetService::STATUS_EXPIRED, $s['status']);
		$this->assertSame('2026-08-29', $s['expiry']['date']);
		$this->assertSame('opened', $s['expiry']['source']);
		$this->assertSame('2028-01-31', $s['expiry']['printed'], 'a impressa continua visivel');
		$this->assertStringContainsString('a caixa diz 2028-01-31', $s['reason']);
	}

	/**
	 * Aberta, de uma forma que se estraga, e sem saber quanto dura: nao ha
	 * validade que se possa afirmar. Devolver a impressa seria dar por boa
	 * uma data que a abertura ja invalidou -- e o utilizador nao ficava a
	 * saber que faltava um dado.
	 */
	public function testFrascoAbertoSemSaberQuantoDuraNaoTemValidade(): void {
		$s = $this->service->packageStatus(
			$this->pkg(['openedAt' => '2026-09-01']),
			$this->med(['form' => 'colirio', 'daysAfterOpening' => null]),
			'2026-10-07'
		);

		$this->assertSame(CabinetService::STATUS_UNKNOWN, $s['status']);
		$this->assertNull($s['expiry']['date']);
		$this->assertTrue($s['expiry']['unknownAfterOpening']);
		$this->assertStringContainsString('dias apos abertura', $s['reason']);
	}

	/**
	 * Um blister aberto nao muda de validade por se ter tirado um
	 * comprimido. A regra da abertura e das formas que se estragam, nao de
	 * todas -- senao metade do armario aparecia como desconhecido.
	 */
	public function testComprimidoAbertoMantemAValidadeImpressa(): void {
		$s = $this->service->packageStatus(
			$this->pkg(['openedAt' => '2026-01-01']),
			$this->med(['form' => 'comprimido']),
			'2026-10-07'
		);

		$this->assertSame(CabinetService::STATUS_OK, $s['status']);
		$this->assertSame('2028-01-31', $s['expiry']['date']);
	}

	public function testAvisaAntesDeExpirar(): void {
		$s = $this->service->packageStatus(
			$this->pkg(['expiresAt' => '2026-10-20']), $this->med(), '2026-10-07'
		);

		$this->assertSame(CabinetService::STATUS_EXPIRING, $s['status']);
		$this->assertSame(13, $s['daysLeft']);
	}

	public function testSemValidadeRegistadaNaoSeInventa(): void {
		$s = $this->service->packageStatus(
			$this->pkg(['expiresAt' => null]), $this->med(), '2026-10-07'
		);

		$this->assertSame(CabinetService::STATUS_UNKNOWN, $s['status']);
		$this->assertNull($s['expiry']['date']);
	}

	/**
	 * A validade efetiva nunca e posterior a impressa: abrir nao prolonga
	 * nada. Se a conta der para depois da caixa, manda a caixa.
	 */
	public function testAbrirNuncaProlongaAValidade(): void {
		$e = $this->service->effectiveExpiry('2026-11-30', '2026-10-01', 365, 'xarope');

		$this->assertSame('2026-11-30', $e['date']);
		$this->assertSame('printed', $e['source']);
	}

	public function testPercentagemSoQuandoSeSabeOTotal(): void {
		$com = $this->service->packageStatus(
			$this->pkg(['unitsLeft' => 5.0, 'unitsTotal' => 20.0]), $this->med(), '2026-10-07'
		);
		$sem = $this->service->packageStatus(
			$this->pkg(['unitsLeft' => 5.0, 'unitsTotal' => null]), $this->med(), '2026-10-07'
		);

		$this->assertSame(0.25, $com['fractionLeft']);
		$this->assertNull($sem['fractionLeft'], 'sem total, "um quarto" nao quer dizer nada');
	}

	/**
	 * Gasta-se primeiro o que expira mais cedo, nao o que se comprou
	 * primeiro: duas caixas da mesma semana podem ter validades muito
	 * diferentes, e gastar a errada deita a outra fora.
	 */
	public function testGastaPrimeiroOQueExpiraMaisCedo(): void {
		$today = '2026-10-07';
		$a = $this->service->packageStatus($this->pkg(['expiresAt' => '2027-06-30']), $this->med(), $today) + ['ref' => 'a'];
		$b = $this->service->packageStatus($this->pkg(['expiresAt' => '2026-12-31']), $this->med(), $today) + ['ref' => 'b'];

		$ordem = array_column($this->service->useFirst([$a, $b]), 'ref');

		$this->assertSame(['b', 'a'], $ordem);
	}

	/**
	 * Com a mesma validade, usa-se a ja aberta. Abrir uma segunda caixa
	 * tendo uma aberta e garantir que uma delas se estraga.
	 */
	public function testComAMesmaValidadeUsaAJaAberta(): void {
		$today = '2026-10-07';
		$med = $this->med(['form' => 'xarope', 'daysAfterOpening' => 400]);
		$fechada = $this->service->packageStatus($this->pkg(['expiresAt' => '2027-01-31']), $med, $today)
			+ ['ref' => 'fechada', 'openedAt' => null];
		$aberta = $this->service->packageStatus(
			$this->pkg(['expiresAt' => '2027-01-31', 'openedAt' => '2026-10-01']), $med, $today
		) + ['ref' => 'aberta', 'openedAt' => '2026-10-01'];

		$ordem = array_column($this->service->useFirst([$fechada, $aberta]), 'ref');

		$this->assertSame(['aberta', 'fechada'], $ordem);
	}

	public function testExpiradasVaziasEDescartadasNaoEntramNaOrdem(): void {
		$today = '2026-10-07';
		$ok = $this->service->packageStatus($this->pkg(), $this->med(), $today) + ['ref' => 'ok'];
		$fora = $this->service->packageStatus($this->pkg(['expiresAt' => '2026-01-01']), $this->med(), $today) + ['ref' => 'fora'];
		$vazia = $this->service->packageStatus($this->pkg(['unitsLeft' => 0.0]), $this->med(), $today) + ['ref' => 'vazia'];
		$lixo = $this->service->packageStatus($this->pkg(['discardedAt' => '2026-09-01']), $this->med(), $today) + ['ref' => 'lixo'];

		$ordem = array_column($this->service->useFirst([$ok, $fora, $vazia, $lixo]), 'ref');

		$this->assertSame(['ok'], $ordem);
	}

	/**
	 * Uma embalagem sem validade conhecida vai para o fim da ordem: nao se
	 * gasta primeiro o que nao se sabe se presta.
	 */
	public function testSemValidadeConhecidaVaiParaOFim(): void {
		$today = '2026-10-07';
		$sabida = $this->service->packageStatus($this->pkg(['expiresAt' => '2027-01-31']), $this->med(), $today) + ['ref' => 'sabida'];
		$incognita = $this->service->packageStatus($this->pkg(['expiresAt' => null]), $this->med(), $today) + ['ref' => 'incognita'];

		$ordem = array_column($this->service->useFirst([$incognita, $sabida]), 'ref');

		$this->assertSame(['sabida', 'incognita'], $ordem);
	}
}
