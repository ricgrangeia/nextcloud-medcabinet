<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Medicine> */
class MedicineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mcb_medicines', Medicine::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): Medicine {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * Procura por nome comercial OU substancia ativa.
	 *
	 * As duas juntas, porque e assim que a pergunta aparece: umas vezes
	 * sabe-se o nome da caixa, outras sabe-se a substancia que o medico
	 * disse. Procurar so por uma delas falhava metade das vezes.
	 *
	 * @return Medicine[]
	 */
	public function findAll(string $userId, ?string $query = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if ($query !== null && trim($query) !== '') {
			$like = '%' . $this->db->escapeLikeParameter(trim($query)) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('substance', $qb->createNamedParameter($like)),
			));
		}

		$qb->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Pelo codigo da caixa. E o que faz a segunda leitura da mesma embalagem
	 * preencher-se sozinha.
	 */
	public function findByGtin(string $gtin, string $userId): ?Medicine {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('gtin', $qb->createNamedParameter($gtin)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}
}
