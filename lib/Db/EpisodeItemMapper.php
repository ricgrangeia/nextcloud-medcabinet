<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<EpisodeItem> */
class EpisodeItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mcb_episode_items', EpisodeItem::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): EpisodeItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return EpisodeItem[] */
	public function findAllForEpisode(int $episodeId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('episode_id', $qb->createNamedParameter($episodeId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $episodeIds
	 * @return array<int, EpisodeItem[]>
	 */
	public function findAllForEpisodes(array $episodeIds): array {
		if ($episodeIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('episode_id', $qb->createNamedParameter($episodeIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$grouped = [];
		foreach ($this->findEntities($qb) as $item) {
			$grouped[$item->getEpisodeId()][] = $item;
		}
		return $grouped;
	}

	/**
	 * Os episodios em que um medicamento foi usado. E a consulta que responde
	 * a "para que e que isto serviu".
	 *
	 * @return EpisodeItem[]
	 */
	public function findAllForMedicine(int $medicineId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('medicine_id', $qb->createNamedParameter($medicineId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteForEpisode(int $episodeId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('episode_id', $qb->createNamedParameter($episodeId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
