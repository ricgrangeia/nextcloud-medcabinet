<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Package> */
class PackageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mcb_packages', Package::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Package {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Package[] */
	public function findAllForMedicine(int $medicineId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('medicine_id', $qb->createNamedParameter($medicineId, IQueryBuilder::PARAM_INT)))
			->orderBy('expires_at', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $medicineIds
	 * @return array<int, Package[]> indexado por medicine_id
	 */
	public function findAllForMedicines(array $medicineIds): array {
		if ($medicineIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('medicine_id', $qb->createNamedParameter($medicineIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('expires_at', 'ASC')->addOrderBy('id', 'ASC');

		$grouped = [];
		foreach ($this->findEntities($qb) as $package) {
			$grouped[$package->getMedicineId()][] = $package;
		}
		return $grouped;
	}

	public function deleteForMedicine(int $medicineId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('medicine_id', $qb->createNamedParameter($medicineId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
