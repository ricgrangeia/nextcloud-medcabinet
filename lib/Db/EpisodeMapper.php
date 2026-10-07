<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Episode> */
class EpisodeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mcb_episodes', Episode::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): Episode {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @param string|null $query procura no motivo, no resultado e nas notas
	 * @return Episode[]
	 */
	public function findAll(string $userId, ?int $personId = null, ?string $query = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if ($personId !== null) {
			$qb->andWhere($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)));
		}

		if ($query !== null && trim($query) !== '') {
			$like = '%' . $this->db->escapeLikeParameter(trim($query)) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('reason', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('outcome', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('notes', $qb->createNamedParameter($like)),
			));
		}

		// Mais recente primeiro: "o que e que lhe demos a ultima vez" e a
		// pergunta que se faz, e a resposta esta no fim da lista cronologica.
		$qb->orderBy('started_at', 'DESC')->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
