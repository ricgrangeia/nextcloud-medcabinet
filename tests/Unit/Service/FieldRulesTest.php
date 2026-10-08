<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Service\FieldRules;
use PHPUnit\Framework\TestCase;

class FieldRulesTest extends TestCase {
	private FieldRules $rules;

	protected function setUp(): void {
		$this->rules = new FieldRules();
	}

	/**
	 * A razao de isto existir: o agente propoe, a app valida. Uma data que nao
	 * existe vem com ar de data, e "2028-02-30" passaria a validade para 1 de
	 * marco se se confiasse nela.
	 */
	public function testDataTemDeSerUmDiaQueExiste(): void {
		$this->assertFalse($this->rules->field('expiry', '2028-02-30')['ok']);
		$this->assertFalse($this->rules->field('expiry', '2027-02-29')['ok']);
		$this->assertTrue($this->rules->field('expiry', '2028-02-29')['ok']);
		$this->assertFalse($this->rules->field('expiry', '2028-13-01')['ok']);
		$this->assertFalse($this->rules->field('expiry', '31/03/2028')['ok']);
		$this->assertFalse($this->rules->field('expiry', 'março de 2028')['ok']);
	}

	public function testRecusaDizPorque(): void {
		$r = $this->rules->field('expiry', '2027-02-29');
		$this->assertFalse($r['ok']);
		$this->assertStringContainsString('28 dias', $r['why']);
	}

	/**
	 * Mes sem dia vale o ULTIMO dia do mes -- a mesma regra do dia "00" do
	 * codigo GS1 e do que esta impresso nas caixas. Posto a dia 1, encurtava a
	 * validade um mes inteiro e a app deitava fora caixas boas.
	 */
	public function testMesSemDiaValeAteAoUltimoDia(): void {
		$this->assertSame('2028-03-31', $this->rules->field('expiry', '2028-03')['value']);
		$this->assertSame('2028-02-29', $this->rules->field('expiry', '2028-02')['value']);
		$this->assertSame('2027-02-28', $this->rules->field('expiry', '2027-02')['value']);
	}

	public function testFormaTemDeSerUmaDasConhecidas(): void {
		$this->assertSame('xarope', $this->rules->field('form', 'Xarope')['value']);
		$this->assertSame('capsula', $this->rules->field('form', 'cápsula')['value']);
		$this->assertFalse($this->rules->field('form', 'pastilha mágica')['ok']);
	}

	public function testQuantidadeTemDeSerNumero(): void {
		$this->assertSame(20, $this->rules->field('unitsTotal', '20')['value']);
		$this->assertSame(20, $this->rules->field('unitsTotal', '20,4')['value']);
		$this->assertFalse($this->rules->field('unitsTotal', 'umas quantas')['ok']);
	}

	public function testLoteNaoLevaEspacos(): void {
		$this->assertSame('AB1234', $this->rules->field('batch', 'ab1234')['value']);
		$this->assertFalse($this->rules->field('batch', 'AB 1234')['ok']);
	}

	/**
	 * Um GTIN errado nao e um campo errado qualquer: e o que associa esta caixa
	 * a um medicamento na leitura seguinte. Associado ao errado, passa a
	 * preencher-se sozinho com o nome errado.
	 */
	public function testGtinTemDeBaterNoDigitoDeControlo(): void {
		$valid = $this->gtin('0560123456789');

		$this->assertSame($valid, $this->rules->field('gtin', $valid)['value']);

		$r = $this->rules->field('gtin', '05600000000017');
		$this->assertFalse($r['ok']);
		$this->assertStringContainsString('dígito de controlo', $r['why']);
	}

	public function testGtinCurtoEEsticadoComZeros(): void {
		// Um EAN-13 e um GTIN-14 com um zero a frente: o mesmo produto.
		$ean13 = substr($this->gtin('0560123456789'), 1);
		$this->assertSame(
			'0' . $ean13,
			$this->rules->field('gtin', $ean13)['value']
		);
	}

	public function testCodigoNacionalTemSeteDigitos(): void {
		$this->assertTrue($this->rules->field('cnp', '5678901')['ok']);
		$this->assertFalse($this->rules->field('cnp', '56789')['ok']);
	}

	public function testCampoDesconhecidoPassaMasECortado(): void {
		$r = $this->rules->field('location', str_repeat('a', 300));
		$this->assertTrue($r['ok']);
		$this->assertSame(192, mb_strlen($r['value']));
	}

	public function testListaSeparaOQueEntraDoQueERecusado(): void {
		$r = $this->rules->clean([
			'name' => 'Ben-u-ron',
			'expiry' => '2028-02-30',
			'form' => 'comprimido',
			'unitsTotal' => 'muitos',
			'substance' => null,
			'batch' => '',
		]);

		$this->assertSame(['name' => 'Ben-u-ron', 'form' => 'comprimido'], $r['values']);
		$this->assertArrayHasKey('expiry', $r['rejected']);
		$this->assertArrayHasKey('unitsTotal', $r['rejected']);
		// null e "" nao sao recusas: sao campos que nao vieram.
		$this->assertArrayNotHasKey('substance', $r['rejected']);
		$this->assertArrayNotHasKey('batch', $r['rejected']);
	}

	public function testListaVaziaNaoDaRecusas(): void {
		$this->assertSame(['values' => [], 'rejected' => []], $this->rules->clean([]));
	}

	/** Constroi um GTIN-14 valido a partir dos 13 primeiros digitos. */
	private function gtin(string $first13): string {
		$sum = 0;
		for ($i = 0; $i < 13; $i++) {
			$sum += (int)$first13[$i] * ($i % 2 === 0 ? 3 : 1);
		}
		return $first13 . ((10 - $sum % 10) % 10);
	}
}
