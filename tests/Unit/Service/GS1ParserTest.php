<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Service\GS1Parser;
use PHPUnit\Framework\TestCase;

/**
 * As cadeias destes testes seguem o formato GS1 real das caixas de
 * medicamentos. O GTIN usado passa o digito de controlo de proposito, para os
 * testes nao dependerem de o parser o ignorar.
 */
class GS1ParserTest extends TestCase {
	private GS1Parser $parser;
	private const GS = "\x1d";

	protected function setUp(): void {
		$this->parser = new GS1Parser();
	}

	/** Constroi um GTIN-14 valido a partir dos 13 primeiros digitos. */
	private function gtin(string $first13): string {
		$sum = 0;
		for ($i = 0; $i < 13; $i++) {
			$sum += (int)$first13[$i] * ($i % 2 === 0 ? 3 : 1);
		}
		return $first13 . ((10 - $sum % 10) % 10);
	}

	public function testLeOsQuatroCamposDeUmaCaixa(): void {
		$gtin = $this->gtin('0560123456789');
		$payload = '01' . $gtin . '17280331' . '10LOTE123' . self::GS . '21SERIE987';

		$r = $this->parser->parse($payload);

		$this->assertSame($gtin, $r['gtin']);
		$this->assertSame('2028-03-31', $r['expiry']);
		$this->assertSame('LOTE123', $r['batch']);
		$this->assertSame('SERIE987', $r['serial']);
		$this->assertSame([], $r['warnings']);
	}

	/**
	 * O caso que mais facilmente passa errado. Dia "00" significa FIM DO MES
	 * na norma GS1. Ao pe da letra dava uma data invalida; posto a dia 1
	 * encurtava a validade um mes inteiro, e a app deitava fora medicamentos
	 * bons.
	 */
	public function testDiaZeroSignificaFimDoMes(): void {
		$r = $this->parser->parse('01' . $this->gtin('0560123456789') . '17280300');

		$this->assertSame('2028-03-31', $r['expiry']);
		$this->assertTrue($r['expiryIsEndOfMonth']);
	}

	public function testFimDoMesEmFevereiroDeAnoBissexto(): void {
		$r = $this->parser->parse('17280200');

		$this->assertSame('2028-02-29', $r['expiry'], '2028 e bissexto');
		$this->assertTrue($r['expiryIsEndOfMonth']);
	}

	public function testFimDoMesEmFevereiroDeAnoComum(): void {
		$r = $this->parser->parse('17270200');

		$this->assertSame('2027-02-28', $r['expiry']);
	}

	public function testAnoDeDoisDigitosEDesteSeculo(): void {
		$r = $this->parser->parse('17280331');

		$this->assertSame('2028-03-31', $r['expiry'], '28 e 2028, nao 1928');
	}

	/**
	 * O lote tem comprimento variavel e termina no separador. Sem o
	 * respeitar, engole o campo seguinte -- e o lote passa a trazer o numero
	 * de serie colado.
	 */
	public function testOLoteTerminaNoSeparadorENaoEngoleOCampoSeguinte(): void {
		$r = $this->parser->parse('10AB12' . self::GS . '17280331');

		$this->assertSame('AB12', $r['batch']);
		$this->assertSame('2028-03-31', $r['expiry']);
	}

	public function testUmCampoVariavelNoFimDaCadeiaNaoPrecisaDeSeparador(): void {
		$r = $this->parser->parse('17280331' . '10FINAL');

		$this->assertSame('FINAL', $r['batch']);
		$this->assertSame('2028-03-31', $r['expiry']);
	}

	public function testIgnoraOPrefixoDeSimbologia(): void {
		$r = $this->parser->parse(']d2' . '17280331');

		$this->assertSame('2028-03-31', $r['expiry']);
		$this->assertSame([], $r['warnings']);
	}

	public function testAceitaOSeparadorEscritoComoTexto(): void {
		$r = $this->parser->parse('10AB12<GS>17280331');

		$this->assertSame('AB12', $r['batch']);
		$this->assertSame('2028-03-31', $r['expiry']);
	}

	/**
	 * O GTIN tem digito de controlo. Falhar significa leitura defeituosa --
	 * guardar um codigo errado fa-lo-ia emparelhar com o medicamento errado
	 * na leitura seguinte, que e pior do que nao o guardar.
	 */
	public function testGtinQueFalhaODigitoDeControloNaoEGuardado(): void {
		$r = $this->parser->parse('01' . '05601234567890' . '17280331');

		$this->assertNull($r['gtin']);
		$this->assertSame('2028-03-31', $r['expiry'], 'o resto continua a valer');
		$this->assertStringContainsString('digito de controlo', $r['warnings'][0]);
	}

	public function testMesInvalidoNaoProduzData(): void {
		$r = $this->parser->parse('17281331');

		$this->assertNull($r['expiry']);
		$this->assertStringContainsString('mes 13', $r['warnings'][0]);
	}

	public function testDataMalFormadaNaoProduzData(): void {
		$r = $this->parser->parse('17ABCDEF');

		$this->assertNull($r['expiry']);
		$this->assertNotSame([], $r['warnings']);
	}

	/**
	 * Uma cadeia que o parser nao percebe para de ser lida e diz onde parou,
	 * em vez de adivinhar o resto.
	 */
	public function testIdentificadorDesconhecidoInterrompeEAvisa(): void {
		$r = $this->parser->parse('17280331' . '99XPTO');

		$this->assertSame('2028-03-31', $r['expiry'], 'o que se leu antes vale');
		$this->assertStringContainsString('identificador', $r['warnings'][0]);
	}

	public function testCadeiaVaziaNaoRebenta(): void {
		$r = $this->parser->parse('');

		$this->assertNull($r['gtin']);
		$this->assertNull($r['expiry']);
		$this->assertSame([], $r['fields']);
	}

	public function testGuardaACadeiaOriginalParaSePoderVerificar(): void {
		$payload = ']d2' . '17280331';
		$r = $this->parser->parse($payload);

		$this->assertSame($payload, $r['raw']);
	}
}
