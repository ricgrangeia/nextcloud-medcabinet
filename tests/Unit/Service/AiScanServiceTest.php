<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\ScanJob;
use OCA\MedCabinet\Db\ScanJobMapper;
use OCA\MedCabinet\Service\AiScanService;
use OCA\MedCabinet\Service\BoxTextParser;
use OCA\MedCabinet\Service\GS1Parser;
use OCA\MedCabinet\Service\PhotoStore;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\IManager as ITaskManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AiScanServiceTest extends TestCase {
	private AiScanService $service;
	private MedicineMapper $medicines;

	protected function setUp(): void {
		$this->medicines = $this->createMock(MedicineMapper::class);

		$this->service = new AiScanService(
			$this->createMock(ScanJobMapper::class),
			new BoxTextParser(),
			new GS1Parser(),
			$this->medicines,
			$this->createMock(ITaskManager::class),
			$this->createMock(PhotoStore::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** Constroi um GTIN-14 valido a partir dos 13 primeiros digitos. */
	private function gtin(string $first13): string {
		$sum = 0;
		for ($i = 0; $i < 13; $i++) {
			$sum += (int)$first13[$i] * ($i % 2 === 0 ? 3 : 1);
		}
		return $first13 . ((10 - $sum % 10) % 10);
	}

	// ------------------------------------------- a resposta do modelo de visao

	public function testLeJsonDirecto(): void {
		$r = $this->service->parseVision(
			'{"name":"Ben-u-ron","substance":"paracetamol","strength":"500 mg","form":"comprimido"}'
		);

		$this->assertSame('Ben-u-ron', $r['values']['name']);
		$this->assertSame('paracetamol', $r['values']['substance']);
		$this->assertSame('comprimido', $r['values']['form']);
		$this->assertNull($r['error']);
	}

	/**
	 * Tolerante na forma: um modelo envolve o JSON em ``` ou escreve uma frase
	 * antes. Rejeitar por isso perdia uma leitura boa.
	 */
	public function testLeJsonEnvolvidoEmTextoOuCercas(): void {
		$fenced = "Aqui está:\n```json\n{\"name\":\"Brufen\"}\n```\nEspero que ajude.";
		$this->assertSame('Brufen', $this->service->parseVision($fenced)['values']['name']);

		$prose = 'Pelo que vejo nas fotografias, {"name":"Aspirina"} é o produto.';
		$this->assertSame('Aspirina', $this->service->parseVision($prose)['values']['name']);
	}

	public function testRespostaSemJsonNaoRebenta(): void {
		$r = $this->service->parseVision('Não consigo ver nada nestas fotografias.');
		$this->assertSame([], $r['values']);
		$this->assertStringContainsString('Não consigo', $r['raw']);
	}

	public function testCampoDeErroDoModelo(): void {
		$r = $this->service->parseVision('{"error":"isto é uma caixa de cereais"}');
		$this->assertSame('isto é uma caixa de cereais', $r['error']);
		$this->assertSame([], $r['values']);
	}

	public function testNullsEStringsVaziasNaoEntram(): void {
		$r = $this->service->parseVision('{"name":"X","substance":null,"batch":"","form":"null"}');
		$this->assertSame(['name' => 'X'], $r['values']);
	}

	/**
	 * Severa no conteudo: cada campo passa a sua propria validacao. Uma data
	 * que nao existe vem com ar de data -- e "2028-02-30" passaria a validade
	 * para 1 de marco se se confiasse nela.
	 */
	public function testDataInexistenteERejeitada(): void {
		$this->assertArrayNotHasKey(
			'expiry', $this->service->parseVision('{"expiry":"2028-02-30"}')['values']
		);
		$this->assertArrayNotHasKey(
			'expiry', $this->service->parseVision('{"expiry":"2028-13-01"}')['values']
		);
		$this->assertArrayNotHasKey(
			'expiry', $this->service->parseVision('{"expiry":"março de 2028"}')['values']
		);
		$this->assertArrayNotHasKey(
			'expiry', $this->service->parseVision('{"expiry":"1998-03-01"}')['values']
		);
	}

	public function testMesSemDiaValeAteAoUltimoDia(): void {
		$this->assertSame(
			'2028-02-29', $this->service->parseVision('{"expiry":"2028-02"}')['values']['expiry']
		);
	}

	public function testFormaForaDaListaERejeitada(): void {
		$this->assertArrayNotHasKey(
			'form', $this->service->parseVision('{"form":"pastilha mágica"}')['values']
		);
		$this->assertSame(
			'xarope', $this->service->parseVision('{"form":"Xarope"}')['values']['form']
		);
	}

	public function testQuantidadeTemDeSerNumero(): void {
		$this->assertArrayNotHasKey(
			'unitsTotal', $this->service->parseVision('{"unitsTotal":"umas quantas"}')['values']
		);
		$this->assertSame(
			20, $this->service->parseVision('{"unitsTotal":"20"}')['values']['unitsTotal']
		);
	}

	public function testCamposDesconhecidosSaoIgnorados(): void {
		$r = $this->service->parseVision('{"name":"X","preco":"4,50","titular":"Lab Y"}');
		$this->assertSame(['name' => 'X'], $r['values']);
	}

	// ------------------------------------------------------------- a proposta

	public function testTextoEModeloJuntos(): void {
		$ocr = "BEN-U-RON\nparacetamol 500 mg\n20 comprimidos\nLote AB1234\nVal.: 03/2028";
		$vision = '{"name":"Ben-u-ron","substance":"paracetamol","form":"comprimido","expiry":"2028-03-31"}';

		$r = $this->service->buildProposal('ric', $ocr, $vision);
		$p = $r['proposal'];

		$this->assertSame('Ben-u-ron', $p['values']['name']);
		$this->assertSame('2028-03-31', $p['values']['expiry']);
		$this->assertSame('AB1234', $p['values']['batch']);
		$this->assertSame('500 mg', $p['values']['strength']);
		// A validade foi proposta pelo modelo E esta no texto: confirmada.
		$this->assertNotContains('expiry', $p['unverified']);
		// Mas continua em needsReview: veio de leitura de texto, nao de um
		// codigo descodificado.
		$this->assertContains('expiry', $p['needsReview']);
		$this->assertFalse($p['canSaveDirectly']);
	}

	/**
	 * O centro de todo este desenho. O modelo devolve uma validade que o texto
	 * da imagem nao contem -- escreveu-a de cabeca -- e isso tem de aparecer.
	 */
	public function testValidadeQueOTextoNaoConfirmaFicaMarcada(): void {
		$ocr = "BEN-U-RON\nparacetamol 500 mg\n20 comprimidos";
		$vision = '{"name":"Ben-u-ron","expiry":"2029-07-31","batch":"ZZ9999"}';

		$p = $this->service->buildProposal('ric', $ocr, $vision)['proposal'];

		$this->assertSame('2029-07-31', $p['values']['expiry']);
		$this->assertContains('expiry', $p['unverified']);
		$this->assertContains('batch', $p['unverified']);
		$this->assertContains('expiry', $p['needsReview']);
		$this->assertNotEmpty(array_filter(
			$p['warnings'],
			static fn (string $w) => str_contains($w, 'confirmar no texto da imagem')
		));
	}

	/**
	 * O nome e classificacao, nao transcricao: um nome errado ve-se logo. Nao
	 * se exige que apareca no texto.
	 */
	public function testNomeNaoPrecisaDeApareceNoTexto(): void {
		$p = $this->service->buildProposal('ric', 'texto ilegível', '{"name":"Ben-u-ron"}')['proposal'];
		$this->assertSame('Ben-u-ron', $p['values']['name']);
		$this->assertNotContains('name', $p['unverified']);
	}

	/**
	 * Sem OCR nao ha com que confirmar, e entao NENHUM numero do modelo conta
	 * como lido. E a consequencia honesta de nao haver segunda fonte.
	 */
	public function testSemOcrOsNumerosDoModeloNaoSaoConfirmados(): void {
		$vision = '{"name":"Brufen","strength":"600 mg","expiry":"2030-01-31"}';

		$p = $this->service->buildProposal('ric', null, $vision)['proposal'];

		$this->assertContains('expiry', $p['unverified']);
		$this->assertContains('strength', $p['unverified']);
		$this->assertNotContains('name', $p['unverified']);
	}

	public function testSoTextoSemModelo(): void {
		$ocr = "ANTIBIÓTICO\namoxicilina 500 mg\n16 cápsulas\nLote: 7X21\nEXP 11/2027";

		$p = $this->service->buildProposal('ric', $ocr, null)['proposal'];

		$this->assertSame('2027-11-30', $p['values']['expiry']);
		$this->assertSame('7X21', $p['values']['batch']);
		$this->assertSame('capsula', $p['values']['form']);
		$this->assertSame([], $p['unverified']);
	}

	public function testNadaLidoNaoDaProposta(): void {
		$r = $this->service->buildProposal('ric', '   ', 'não sei o que isto é');
		$this->assertNull($r['proposal']);
	}

	/**
	 * A interpretacao legivel do codigo 2D impressa na caixa. Entra, mas com
	 * confianca de texto: os digitos vieram do OCR, nao do descodificador.
	 */
	public function testCodigoImpressoEntraComoTexto(): void {
		$gtin = $this->gtin('0560123456789');
		$ocr = "BEN-U-RON\n(01)$gtin(17)280331(10)AB1234\nVal 03/2028";

		$p = $this->service->buildProposal('ric', $ocr, null)['proposal'];

		$this->assertSame('2028-03-31', $p['values']['expiry']);
		$this->assertSame($gtin, $p['values']['gtin']);
		// Confianca "text" e nao "code": os digitos vieram do OCR, nao do
		// descodificador -- o digito de controlo apanha a maior parte dos
		// erros de um digito, nao todos.
		$this->assertSame('text', $p['fields']['gtin']['confidence']);
		$this->assertContains('gtin', $p['needsReview']);
	}

	/**
	 * E se o codigo impresso saiu mal lido, o digito de controlo apanha-o e o
	 * codigo nao entra. Um codigo errado associava esta caixa ao medicamento
	 * errado da proxima vez -- pior do que nao ter codigo nenhum.
	 */
	public function testCodigoImpressoComDigitoErradoNaoEntra(): void {
		$ocr = "BEN-U-RON\n(01)05600000000017(17)280331(10)AB1234\nVal 03/2028";

		$p = $this->service->buildProposal('ric', $ocr, null)['proposal'];

		$this->assertArrayNotHasKey('gtin', $p['values']);
		$this->assertSame('2028-03-31', $p['values']['expiry']);
	}

	public function testFaseFalhadaEDita(): void {
		$stages = [
			ScanJob::STAGE_OCR => ['status' => ScanJob::STAGE_FAILED, 'error' => 'sem fornecedor'],
		];

		$p = $this->service->buildProposal('ric', null, '{"name":"X"}', $stages)['proposal'];

		$this->assertNotEmpty(array_filter(
			$p['warnings'],
			static fn (string $w) => str_contains($w, 'transcrição do texto')
		));
	}

	public function testAvisaQuandoNaoLeuValidade(): void {
		$p = $this->service->buildProposal('ric', 'BRUFEN 600 mg', null)['proposal'];

		$this->assertArrayNotHasKey('expiry', $p['values']);
		$this->assertNotEmpty(array_filter(
			$p['warnings'],
			static fn (string $w) => str_contains($w, 'escreve-a a olhar para a caixa')
		));
	}

	public function testGuardaOQueCadaFonteDisse(): void {
		$r = $this->service->buildProposal('ric', 'Val 03/2028', '{"name":"X"}');

		$this->assertArrayHasKey('ocr', $r['sources']);
		$this->assertArrayHasKey('vision', $r['sources']);
		$this->assertSame('2028-03-31', $r['sources']['ocr']['values']['expiry']);
	}
}
