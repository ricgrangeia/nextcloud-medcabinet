<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Catalogar uma caixa a partir de fotografias, com a IA do Nextcloud.
 *
 * Nao e sincrono porque nao pode ser: um modelo de visao a olhar para tres
 * fotografias leva mais do que um pedido web aguenta, e quem fechar o
 * separador perderia o resultado. As fotos entram, isto fica em fila, e a
 * resposta chega pelas notificacoes.
 *
 * As fases ficam num campo JSON e nao em colunas: a IA responde por evento,
 * uma fase por evento, e pode responder em qualquer ordem -- o que interessa
 * e saber quando todas ja responderam.
 */
#[CreateTable(table: 'mcb_scan_jobs', description: 'Photos of a box waiting to be read by the local AI')]
class Version1001Date20261008120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mcb_scan_jobs')) {
			return $schema;
		}

		$t = $schema->createTable('mcb_scan_jobs');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
		$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// pending | running | done | failed
		$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		// Os ficheiros ficam nos Ficheiros DELE, nao no appdata. Duas razoes:
		// a TaskProcessing so aceita ficheiros a que o utilizador tem acesso,
		// e a fotografia da caixa e a prova de onde a validade saiu -- vale
		// mais guardada onde ele a encontra do que escondida.
		$t->addColumn('file_ids', Types::TEXT, ['notnull' => false]);
		$t->addColumn('file_names', Types::TEXT, ['notnull' => false]);
		// Por fase: que tarefa foi pedida, em que estado esta, e o que
		// devolveu. ocr = transcrever; vision = reconhecer o produto.
		$t->addColumn('stages', Types::TEXT, ['notnull' => false]);
		$t->addColumn('proposal', Types::TEXT, ['notnull' => false]);
		$t->addColumn('error', Types::TEXT, ['notnull' => false]);
		$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
		$t->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

		$t->setPrimaryKey(['id']);
		$t->addIndex(['user_id'], 'mcb_scan_uid_idx');
		$t->addIndex(['status'], 'mcb_scan_status_idx');

		return $schema;
	}
}
