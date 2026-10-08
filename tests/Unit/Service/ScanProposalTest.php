<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Tests\Unit\Service;

use OCA\MedCabinet\Service\ScanProposal;
use PHPUnit\Framework\TestCase;

class ScanProposalTest extends TestCase {
	private ScanProposal $proposal;

	protected function setUp(): void {
		$this->proposal = new ScanProposal();
	}

	/**
	 * O caso que motiva a classe: a frente da caixa da o nome, o painel do
	 * DataMatrix da a validade e o lote. Nenhuma foto sozinha chega.
	 */
	public function testVariasFotosCompletamUmaCaixa(): void {
		$this->proposal->observe(
			['name' => 'Brufen', 'strength' => '600 mg'],
			ScanProposal::CONFIDENCE_TEXT, 'foto 1 (frente)'
		);
		$this->proposal->observe(
			['expiry' => '2028-03-31', 'batch' => 'AB12', 'gtin' => '05601234567897'],
			ScanProposal::CONFIDENCE_CODE, 'foto 2 (DataMatrix)'
		);

		$r = $this->proposal->result();

		$this->assertSame('Brufen', $r['values']['name']);
		$this->assertSame('2028-03-31', $r['values']['expiry']);
		$this->assertSame('AB12', $r['values']['batch']);
	}

	/**
	 * O que vem de um codigo e exacto; o que vem de texto numa foto e um
	 * palpite com boa aparencia. Por isso o segundo tem de ser confirmado --
	 * um "7" lido como "1" desloca a validade seis anos e continua a parecer
	 * uma data normal.
	 */
	public function testOQueVemDeTextoFicaParaConfirmar(): void {
		$this->proposal->observe(['expiry' => '2028-03-31'], ScanProposal::CONFIDENCE_TEXT, 'foto');

		$r = $this->proposal->result();

		$this->assertSame(['expiry'], $r['needsReview']);
		$this->assertFalse($r['canSaveDirectly']);
	}

	public function testOQueVemDeUmCodigoPodeSerGravadoDirectamente(): void {
		$this->proposal->observe(
			['expiry' => '2028-03-31', 'batch' => 'AB12'],
			ScanProposal::CONFIDENCE_CODE, 'DataMatrix'
		);

		$r = $this->proposal->result();

		$this->assertSame([], $r['needsReview']);
		$this->assertTrue($r['canSaveDirectly']);
	}

	/**
	 * Um codigo ganha a um texto no mesmo campo, porque e verificavel. Mas o
	 * desacordo nao desaparece: fica registado, e o campo passa a precisar de
	 * confirmacao.
	 */
	public function testOCodigoGanhaAoTextoMasODesacordoApareceu(): void {
		$this->proposal->observe(['expiry' => '2028-01-31'], ScanProposal::CONFIDENCE_TEXT, 'foto 1');
		$this->proposal->observe(['expiry' => '2028-03-31'], ScanProposal::CONFIDENCE_CODE, 'DataMatrix');

		$r = $this->proposal->result();

		$this->assertSame('2028-03-31', $r['values']['expiry'], 'o codigo manda');
		$this->assertContains('expiry', $r['needsReview'], 'mas discordaram');
		$this->assertFalse($r['canSaveDirectly']);
		$this->assertStringContainsString('discordam', $r['warnings'][0]);
	}

	/**
	 * Entre duas fontes igualmente fiaveis nao se escolhe a sorte: fica a
	 * primeira e o desacordo e mostrado a quem decide.
	 */
	public function testEntreFontesIguaisNaoSeEscolheASorte(): void {
		$this->proposal->observe(['expiry' => '2028-01-31'], ScanProposal::CONFIDENCE_CODE, 'caixa');
		$this->proposal->observe(['expiry' => '2029-01-31'], ScanProposal::CONFIDENCE_CODE, 'blister');

		$r = $this->proposal->result();

		$this->assertContains('expiry', $r['needsReview']);
		$this->assertCount(2, $r['conflicts']['expiry']);
		$this->assertFalse($r['canSaveDirectly']);
	}

	public function testDuasFontesDeAcordoNaoSaoConflito(): void {
		$this->proposal->observe(['expiry' => '2028-03-31'], ScanProposal::CONFIDENCE_TEXT, 'foto');
		$this->proposal->observe(['expiry' => '2028-03-31'], ScanProposal::CONFIDENCE_CODE, 'DataMatrix');

		$r = $this->proposal->result();

		$this->assertSame([], $r['conflicts']);
		$this->assertSame([], $r['needsReview'], 'a fonte mais fiavel passa a responder pelo campo');
		$this->assertTrue($r['canSaveDirectly']);
	}

	public function testOQueEEscritoAMaoManda(): void {
		$this->proposal->observe(['name' => 'Brufen'], ScanProposal::CONFIDENCE_TEXT, 'foto');
		$this->proposal->observe(['name' => 'Brufen 600'], ScanProposal::CONFIDENCE_MANUAL, 'eu');

		$r = $this->proposal->result();

		$this->assertSame('Brufen 600', $r['values']['name']);
	}

	public function testCamposVaziosNaoEntram(): void {
		$this->proposal->observe(
			['name' => 'Brufen', 'batch' => null, 'strength' => ''],
			ScanProposal::CONFIDENCE_CODE, 'codigo'
		);

		$r = $this->proposal->result();

		$this->assertSame(['name'], array_keys($r['values']));
	}

	public function testUmaPropostaVaziaNaoSePodeGravar(): void {
		$r = $this->proposal->result();

		$this->assertSame([], $r['values']);
		$this->assertFalse($r['canSaveDirectly'], 'nada para gravar nao e o mesmo que pronto a gravar');
	}

	public function testCadaCampoDizDeOndeVeio(): void {
		$this->proposal->observe(['expiry' => '2028-03-31'], ScanProposal::CONFIDENCE_CODE, 'DataMatrix da caixa');

		$r = $this->proposal->result();

		$this->assertSame('DataMatrix da caixa', $r['fields']['expiry']['from']);
		$this->assertSame(ScanProposal::CONFIDENCE_CODE, $r['fields']['expiry']['confidence']);
	}
}
