<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(table: 'mcb_people', description: 'Household members a medicine can be for')]
#[CreateTable(table: 'mcb_medicines', description: 'A medicine as a product: name, active substance, strength, form')]
#[CreateTable(table: 'mcb_packages', description: 'One physical box in the cabinet: units left, expiry, when opened')]
#[CreateTable(table: 'mcb_episodes', description: 'What a medicine was taken FOR: a person, a reason, a span')]
#[CreateTable(table: 'mcb_episode_items', description: 'A medicine used within an episode, with its posology')]
class Version1000Date20261007000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mcb_people')) {
			$t = $schema->createTable('mcb_people');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('birth_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'mcb_people_uid_idx');
		}

		if (!$schema->hasTable('mcb_medicines')) {
			$t = $schema->createTable('mcb_medicines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// O nome comercial, que e o que esta escrito na caixa.
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 192]);
			// A substancia ativa e o que torna a pesquisa util: "Brufen" e
			// "ibuprofeno" tem de encontrar-se um ao outro, e sem catalogo
			// externo so se consegue registando-a.
			$t->addColumn('substance', Types::STRING, ['notnull' => false, 'length' => 192]);
			$t->addColumn('strength', Types::STRING, ['notnull' => false, 'length' => 64]);
			// comprimido | capsula | xarope | colirio | pomada | saqueta | ...
			$t->addColumn('form', Types::STRING, ['notnull' => false, 'length' => 32]);
			// A unidade em que se conta o stock: comprimido, ml, gota, puff.
			// Nao e a caixa: meia caixa e meio comprimido existem.
			$t->addColumn('unit', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'unidade']);
			// Quantos dias dura depois de aberto. E o campo que impede o
			// aviso de validade de mentir: um xarope com a caixa a dizer 2028
			// estraga-se semanas depois de aberto.
			$t->addColumn('days_after_opening', Types::INTEGER, ['notnull' => false]);
			// Codigo do produto lido da DataMatrix da caixa. Guardado para a
			// proxima leitura da mesma embalagem se preencher sozinha.
			$t->addColumn('gtin', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'mcb_med_uid_idx');
			$t->addIndex(['substance'], 'mcb_med_subst_idx');
			$t->addIndex(['gtin'], 'mcb_med_gtin_idx');
		}

		if (!$schema->hasTable('mcb_packages')) {
			$t = $schema->createTable('mcb_packages');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('medicine_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('units_total', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('units_left', Types::FLOAT, ['notnull' => false]);
			// A validade impressa na caixa.
			$t->addColumn('expires_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			// Quando foi aberta. Com days_after_opening, da a validade
			// efetiva -- que e a que conta, e nunca e posterior a impressa.
			$t->addColumn('opened_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('batch', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 128]);
			// manual | datamatrix | prescription -- de onde veio o registo.
			// Saber que a validade foi lida da caixa e nao escrita a mao muda
			// a confianca que se lhe da.
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']);
			$t->addColumn('discarded_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['medicine_id'], 'mcb_pkg_med_idx');
			$t->addIndex(['expires_at'], 'mcb_pkg_exp_idx');
		}

		if (!$schema->hasTable('mcb_episodes')) {
			$t = $schema->createTable('mcb_episodes');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false, 'length' => 20, 'unsigned' => true]);
			// O motivo, por palavras dele: "otite", "dor de dentes", "entorse
			// no tornozelo". E por aqui que a pesquisa vai procurar.
			$t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('started_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('ended_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			// Quem assistiu. Faz parte da proveniencia: "receitado pelo Dr. X"
			// vale diferente de "demos nos".
			$t->addColumn('prescriber', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('outcome', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'mcb_epi_uid_idx');
			$t->addIndex(['person_id'], 'mcb_epi_person_idx');
			$t->addIndex(['started_at'], 'mcb_epi_start_idx');
		}

		if (!$schema->hasTable('mcb_episode_items')) {
			$t = $schema->createTable('mcb_episode_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('episode_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$t->addColumn('medicine_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			// Como veio escrito: "1 comprimido de 8 em 8 horas, 8 dias".
			// Texto livre de proposito: interpretar posologia e inventar.
			$t->addColumn('posology', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('started_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('ended_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['episode_id'], 'mcb_epiitem_epi_idx');
			$t->addIndex(['medicine_id'], 'mcb_epiitem_med_idx');
		}

		return $schema;
	}
}
