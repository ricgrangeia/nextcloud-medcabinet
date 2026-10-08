<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Service\BoxTextParser;
use PHPUnit\Framework\TestCase;

class BoxTextParserTest extends TestCase {
	private BoxTextParser $parser;

	protected function setUp(): void {
		$this->parser = new BoxTextParser();
	}

	public function testLeUmaCaixaTipica(): void {
		$text = "BEN-U-RON\nparacetamol 500 mg\n20 comprimidos\nLote: AB1234\nVal.: 03/2028";

		$r = $this->parser->parse($text);

		$this->assertSame('2028-03-31', $r['values']['expiry']);
		$this->assertSame('AB1234', $r['values']['batch']);
		$this->assertSame('500 mg', $r['values']['strength']);
		$this->assertSame('comprimido', $r['values']['form']);
		$this->assertSame(20, $r['values']['unitsTotal']);
	}

	/**
	 * A regra que mais facilmente passava: so mes significa o ULTIMO dia desse
	 * mes. Posto a dia 1, a app deitava fora caixas boas um mes antes.
	 */
	public function testMesSemDiaValeAteAoUltimoDia(): void {
		$this->assertSame('2028-02-29', $this->parser->parse('EXP 02/2028')['values']['expiry']);
		$this->assertSame('2027-02-28', $this->parser->parse('EXP 02/2027')['values']['expiry']);
		$this->assertSame('2026-11-30', $this->parser->parse('VAL 11/2026')['values']['expiry']);
		$this->assertTrue($this->parser->parse('EXP 02/2028')['expiryIsEndOfMonth']);
	}

	public function testDiaExplicitoFicaNoDia(): void {
		$r = $this->parser->parse('Validade: 15/06/2029');
		$this->assertSame('2029-06-15', $r['values']['expiry']);
		$this->assertFalse($r['expiryIsEndOfMonth']);
	}

	public function testFormatoIso(): void {
		$this->assertSame('2030-09-30', $this->parser->parse('EXP: 2030-09')['values']['expiry']);
		$this->assertSame('2030-09-14', $this->parser->parse('EXP: 2030-09-14')['values']['expiry']);
	}

	public function testAnoDeDoisDigitosAvisa(): void {
		$r = $this->parser->parse('Val. 03/28');
		$this->assertSame('2028-03-31', $r['values']['expiry']);
		$this->assertNotEmpty(array_filter(
			$r['warnings'],
			static fn (string $w) => str_contains($w, 'dois dígitos')
		));
	}

	/**
	 * Sem rotulo nao se adivinha. Uma caixa tem numeros por todo o lado -- o
	 * preco, o peso, o numero de registo -- e qualquer um deles daria uma data
	 * com ar de validade.
	 */
	public function testNumeroSoltoNaoEValidade(): void {
		$r = $this->parser->parse("BRUFEN\n600 mg\n0123456 7890");
		$this->assertArrayNotHasKey('expiry', $r['values']);
	}

	public function testDataImpossivelEIgnorada(): void {
		$this->assertArrayNotHasKey('expiry', $this->parser->parse('Val. 13/2028')['values']);
		$this->assertArrayNotHasKey('expiry', $this->parser->parse('Val. 31/02/2028')['values']);
		$this->assertArrayNotHasKey('expiry', $this->parser->parse('Val. 03/1998')['values']);
	}

	/**
	 * "100 mg/ml" nao e "100 mg". Numa suspensao pediatrica a diferenca entre
	 * as duas e a dose que a crianca leva.
	 */
	public function testDosagemPorMililitroNaoPerdeODenominador(): void {
		$r = $this->parser->parse('Paracetamol 100 mg/ml suspensão oral 60 ml');
		$this->assertSame('100 mg/ml', $r['values']['strength']);
		$this->assertSame('suspensao', $r['values']['form']);
		$this->assertSame('ml', $r['values']['unit']);
		$this->assertSame(60, $r['values']['unitsTotal']);
	}

	public function testFormaMaisEspecificaGanha(): void {
		$this->assertSame(
			'comprimido',
			$this->parser->parse('20 comprimidos revestidos por película')['values']['form']
		);
		$this->assertSame('colirio', $this->parser->parse('colírio em solução')['values']['form']);
	}

	public function testCodigoNacionalExigeRotulo(): void {
		$this->assertSame('5678901', $this->parser->parse('C.N.P. 5678901')['values']['cnp']);
		$this->assertArrayNotHasKey('cnp', $this->parser->parse('5678901')['values']);
	}

	/**
	 * A interpretacao legivel do codigo 2D, impressa debaixo dele. Vale mais
	 * do que procurar campo a campo, e o GTIN traz digito de controlo.
	 */
	public function testInterpretacaoLegivelDoCodigo(): void {
		$r = $this->parser->parse('(01)05600000000017(17)280331(10)AB1234');
		$this->assertNotNull($r['gs1']);
		$this->assertStringStartsWith('0105600000000017', $r['gs1']);
		$this->assertStringContainsString("10AB1234", $r['gs1']);
	}

	public function testUmSoIdentificadorNaoEUmCodigo(): void {
		$this->assertNull($this->parser->parse('(01)05600000000017')['gs1']);
	}

	// ---------------------------------------------------------- corroboracao

	/**
	 * O guarda que separa uma leitura de um palpite: se o valor nao esta no
	 * texto da imagem, o modelo escreveu-o de cabeca.
	 */
	public function testConfirmaValorQueEstaNoTexto(): void {
		$text = 'Lote AB1234 Val. 03/2028';
		$this->assertTrue($this->parser->corroborates($text, 'AB1234'));
		$this->assertTrue($this->parser->corroborates($text, '2028-03-31'));
		$this->assertFalse($this->parser->corroborates($text, 'XY9999'));
		$this->assertFalse($this->parser->corroborates($text, '2029-07-31'));
	}

	public function testConfirmaIgnorandoPontuacao(): void {
		$this->assertTrue($this->parser->corroborates('VAL 03-2028', '2028-03-31'));
		$this->assertTrue($this->parser->corroborates('EXP 2028/03', '2028-03-31'));
	}

	/**
	 * Valores curtos acertam por acaso em qualquer texto cheio de numeros.
	 * Dizer que estao confirmados e pior do que nao dizer nada.
	 */
	public function testValorCurtoNaoSeConsideraConfirmado(): void {
		$this->assertFalse($this->parser->corroborates('500 mg 20 comprimidos', '20'));
	}

	public function testTextoVazioNaoConfirmaNada(): void {
		$this->assertFalse($this->parser->corroborates('', 'AB1234'));
	}
}
