<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Service\BoxTextParser;
use OCA\MedCabinet\Service\FieldRules;
use OCA\MedCabinet\Service\GS1Parser;
use OCA\MedCabinet\Service\MedicineService;
use OCA\MedCabinet\Service\ScanService;
use PHPUnit\Framework\TestCase;

class ScanServiceTest extends TestCase {
	private ScanService $service;
	private MedicineMapper $medicines;
	private MedicineService $medicineService;

	protected function setUp(): void {
		$this->medicines = $this->createMock(MedicineMapper::class);
		$this->medicineService = $this->createMock(MedicineService::class);

		$this->service = new ScanService(
			new GS1Parser(),
			new BoxTextParser(),
			new FieldRules(),
			$this->medicines,
			$this->medicineService,
		);
	}

	// ----------------------------------------------------- texto lido da caixa

	public function testLeOsCamposDoTexto(): void {
		$text = "BEN-U-RON\nparacetamol 500 mg\n20 comprimidos\nLote: AB1234\nVal.: 03/2028";

		$p = $this->service->fromText($text, 'ric')['proposal'];

		$this->assertSame('2028-03-31', $p['values']['expiry']);
		$this->assertSame('AB1234', $p['values']['batch']);
		$this->assertSame('500 mg', $p['values']['strength']);
		$this->assertSame('comprimido', $p['values']['form']);
		$this->assertSame(20, $p['values']['unitsTotal']);
		// Veio de texto: confirma-se antes de gravar.
		$this->assertContains('expiry', $p['needsReview']);
		$this->assertFalse($p['canSaveDirectly']);
	}

	/**
	 * O centro do desenho. O agente propoe uma validade que o texto que ele
	 * proprio mandou nao contem -- escreveu-a de cabeca -- e isso tem de
	 * aparecer em vez de passar.
	 */
	public function testCampoPropostoQueOTextoNaoConfirmaFicaMarcado(): void {
		$text = "BEN-U-RON\nparacetamol 500 mg\n20 comprimidos";
		$proposed = ['name' => 'Ben-u-ron', 'expiry' => '2029-07-31', 'batch' => 'ZZ9999'];

		$p = $this->service->fromText($text, 'ric', $proposed)['proposal'];

		$this->assertSame('2029-07-31', $p['values']['expiry']);
		$this->assertContains('expiry', $p['unverified']);
		$this->assertContains('batch', $p['unverified']);
		$this->assertNotEmpty(array_filter(
			$p['warnings'],
			static fn (string $w) => str_contains($w, 'confirmar no texto da imagem')
		));
	}

	public function testCampoPropostoQueOTextoConfirmaNaoFicaMarcado(): void {
		$text = "BRUFEN\nibuprofeno 600 mg\nLote 7X21\nEXP 11/2027";
		$proposed = ['expiry' => '2027-11-30', 'batch' => '7X21'];

		$p = $this->service->fromText($text, 'ric', $proposed)['proposal'];

		$this->assertSame([], $p['unverified']);
		$this->assertSame('2027-11-30', $p['values']['expiry']);
	}

	/**
	 * O nome e classificacao, nao transcricao: um nome errado ve-se logo. Nao
	 * se exige que apareca no texto.
	 */
	public function testNomeNaoPrecisaDeApareceNoTexto(): void {
		$p = $this->service->fromText('texto ilegível', 'ric', ['name' => 'Ben-u-ron'])['proposal'];

		$this->assertSame('Ben-u-ron', $p['values']['name']);
		$this->assertNotContains('name', $p['unverified']);
	}

	/**
	 * Um campo mal formado nao entra, e diz-se porque. Recusar em silencio
	 * deixava o agente a pensar que tinha gravado a validade.
	 */
	public function testCampoMalFormadoERecusadoComRazao(): void {
		$r = $this->service->fromText('Val 03/2028', 'ric', ['expiry' => '2028-02-30']);

		$this->assertArrayHasKey('expiry', $r['rejected']);
		// A validade que fica e a que as regras encontraram no texto, nao a
		// que veio mal.
		$this->assertSame('2028-03-31', $r['proposal']['values']['expiry']);
	}

	public function testAvisaQuandoNaoHaValidadeNoTexto(): void {
		$p = $this->service->fromText('BRUFEN 600 mg', 'ric')['proposal'];

		$this->assertArrayNotHasKey('expiry', $p['values']);
		$this->assertNotEmpty(array_filter(
			$p['warnings'],
			static fn (string $w) => str_contains($w, 'escreve-a a olhar para a caixa')
		));
	}

	public function testDevolveOQueAsRegrasEncontraramEOndeOEncontraram(): void {
		$r = $this->service->fromText("Lote AB1234\nVal 03/2028", 'ric');

		$this->assertSame('2028-03-31', $r['read']['values']['expiry']);
		$this->assertSame('Val 03/2028', $r['read']['evidence']['expiry']);
	}

	// -------------------------------------------------------- codigo e juncao

	public function testCodigoDescodificadoEExacto(): void {
		$gtin = $this->gtin('0560123456789');
		$payload = '01' . $gtin . '17280331' . '10AB1234';

		$p = $this->service->fromCode($payload, 'ric')['proposal'];

		$this->assertSame('2028-03-31', $p['values']['expiry']);
		$this->assertSame('code', $p['fields']['expiry']['confidence']);
		$this->assertNotContains('expiry', $p['needsReview']);
	}

	/**
	 * O codigo manda no texto: e verificavel e o texto nao. E o desacordo tem
	 * de aparecer, nao ser resolvido em silencio.
	 */
	public function testCodigoGanhaAoTextoEODesacordoAparece(): void {
		$gtin = $this->gtin('0560123456789');

		$r = $this->service->merge([
			['payload' => '01' . $gtin . '17280331', 'from' => 'código'],
			['text' => 'Val.: 07/2029', 'from' => 'texto'],
		], 'ric');

		$this->assertSame('2028-03-31', $r['proposal']['values']['expiry']);
		$this->assertArrayHasKey('expiry', $r['proposal']['conflicts']);
		$this->assertContains('expiry', $r['proposal']['needsReview']);
	}

	public function testJuntaDuasLeiturasDePaineisDiferentes(): void {
		$r = $this->service->merge([
			['text' => "BEN-U-RON\nparacetamol 500 mg", 'from' => 'frente'],
			['text' => "Lote AB1234\nVal 03/2028", 'from' => 'painel de trás'],
		], 'ric');

		$this->assertSame('500 mg', $r['proposal']['values']['strength']);
		$this->assertSame('AB1234', $r['proposal']['values']['batch']);
		$this->assertSame('2028-03-31', $r['proposal']['values']['expiry']);
	}

	/**
	 * Campos sem texto de onde os confirmar: escritos por uma pessoa valem por
	 * quem os escreveu, vindos de um modelo nao ha com que os confirmar.
	 */
	public function testEscritoAMaoValeContraPropostoSemTexto(): void {
		$manual = $this->service->merge([
			['values' => ['expiry' => '2028-03-31'], 'manual' => true, 'from' => 'eu'],
		], 'ric')['proposal'];

		$this->assertSame([], $manual['unverified']);
		$this->assertTrue($manual['canSaveDirectly']);

		$agent = $this->service->merge([
			['values' => ['expiry' => '2028-03-31'], 'from' => 'agente'],
		], 'ric')['proposal'];

		$this->assertContains('expiry', $agent['unverified']);
		$this->assertFalse($agent['canSaveDirectly']);
	}

	// ------------------------------------------------------------------ gravar

	/**
	 * Se um campo nao passa, NADA e gravado. Uma caixa gravada sem a validade
	 * que se achava que ia gravada fica um registo com ar de completo -- pior
	 * do que nenhum.
	 */
	public function testNaoGravaNadaSeUmCampoNaoPassar(): void {
		$this->medicineService->expects($this->never())->method('create');
		$this->medicineService->expects($this->never())->method('addPackage');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Nada foi gravado');

		$this->service->apply(['name' => 'Ben-u-ron', 'expiry' => '2028-02-30'], 'ric');
	}

	public function testExigeNomeQuandoOMedicamentoENovo(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Falta o nome');

		$this->service->apply(['expiry' => '2028-03-31'], 'ric');
	}

	public function testGravaComOsCamposNormalizados(): void {
		$this->medicineService->method('create')->willReturn(['id' => 7]);
		$this->medicineService->expects($this->once())
			->method('addPackage')
			->with(7, 'ric', $this->callback(static function (array $data): bool {
				// "2028-03" chega como mes e e gravado como ultimo dia.
				return $data['expiresAt'] === '2028-03-31' && $data['batch'] === 'AB1234';
			}))
			->willReturn(['id' => 11]);

		$r = $this->service->apply([
			'name' => 'Ben-u-ron', 'expiry' => '2028-03', 'batch' => 'ab1234',
		], 'ric');

		$this->assertSame(7, $r['medicineId']);
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
