<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ScanJob> */
class ScanJobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mcb_scan_jobs', ScanJob::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): ScanJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findForUser(int $id, string $userId): ScanJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return ScanJob[] */
	public function findAllForUser(string $userId, int $limit = 30): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Os que ficaram pendurados: a tarefa foi aceite pela IA e a resposta
	 * nunca chegou. Sem isto um modelo que nao responde deixa a leitura em
	 * "a correr" para sempre, e quem a enviou fica a olhar.
	 *
	 * @return ScanJob[]
	 */
	public function findStale(\DateTimeImmutable $before, int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[ScanJob::PENDING, ScanJob::RUNNING], IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter(
				$before, IQueryBuilder::PARAM_DATETIME_IMMUTABLE
			)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
