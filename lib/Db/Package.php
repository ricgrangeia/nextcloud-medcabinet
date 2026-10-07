<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Uma caixa fisica no armario.
 *
 * O stock conta-se em unidades do medicamento -- comprimidos, ml, gotas --
 * nao em caixas: meia caixa e meio comprimido existem.
 *
 * `openedAt` nao e informacao acessoria: e o que decide a validade real de um
 * xarope ou de um colirio. Ver CabinetService.
 *
 * @method int getMedicineId()
 * @method void setMedicineId(int $medicineId)
 * @method ?float getUnitsTotal()
 * @method void setUnitsTotal(?float $unitsTotal)
 * @method ?float getUnitsLeft()
 * @method void setUnitsLeft(?float $unitsLeft)
 * @method ?\DateTimeImmutable getExpiresAt()
 * @method void setExpiresAt(?\DateTimeImmutable $expiresAt)
 * @method ?\DateTimeImmutable getOpenedAt()
 * @method void setOpenedAt(?\DateTimeImmutable $openedAt)
 * @method ?string getBatch()
 * @method void setBatch(?string $batch)
 * @method ?string getLocation()
 * @method void setLocation(?string $location)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method ?\DateTimeImmutable getDiscardedAt()
 * @method void setDiscardedAt(?\DateTimeImmutable $discardedAt)
 */
class Package extends Entity implements \JsonSerializable {
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_DATAMATRIX = 'datamatrix';
	public const SOURCE_PRESCRIPTION = 'prescription';

	protected int $medicineId = 0;
	protected ?float $unitsTotal = null;
	protected ?float $unitsLeft = null;
	protected ?\DateTimeImmutable $expiresAt = null;
	protected ?\DateTimeImmutable $openedAt = null;
	protected ?string $batch = null;
	protected ?string $location = null;
	protected string $source = self::SOURCE_MANUAL;
	protected ?\DateTimeImmutable $discardedAt = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('medicineId', Types::INTEGER);
		$this->addType('unitsTotal', Types::FLOAT);
		$this->addType('unitsLeft', Types::FLOAT);
		$this->addType('expiresAt', Types::DATE_IMMUTABLE);
		$this->addType('openedAt', Types::DATE_IMMUTABLE);
		$this->addType('discardedAt', Types::DATE_IMMUTABLE);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'medicineId' => $this->medicineId,
			'unitsTotal' => $this->unitsTotal,
			'unitsLeft' => $this->unitsLeft,
			'expiresAt' => $this->expiresAt?->format('Y-m-d'),
			'openedAt' => $this->openedAt?->format('Y-m-d'),
			'batch' => $this->batch,
			'location' => $this->location,
			'source' => $this->source,
			'discardedAt' => $this->discardedAt?->format('Y-m-d'),
		];
	}
}
