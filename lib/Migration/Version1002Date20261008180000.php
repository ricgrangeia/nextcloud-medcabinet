<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\Attributes\DropTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fora a fila de leitura de fotografias.
 *
 * Esta app deixou de tratar imagens. Quem fotografa a caixa e le o que esta
 * nela e o agente (appsagent), que ja tem modelo de visao e descobre estas
 * rotas sozinho; aqui fica o registo e as regras do dominio.
 *
 * A tabela so se larga se existir: num servidor que nunca correu a versao que
 * a criou, nao ha nada para largar.
 */
#[DropTable(table: 'mcb_scan_jobs')]
class Version1002Date20261008180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mcb_scan_jobs')) {
			return null;
		}

		$schema->dropTable('mcb_scan_jobs');

		return $schema;
	}
}
