<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Um medicamento usado num episodio.
 *
 * A `posology` e texto livre de proposito: "1 comprimido de 8 em 8 horas, 8
 * dias" guarda-se como veio escrito. Interpretar posologia em campos
 * estruturados e inventar precisao que a receita nao tem -- e o que esta
 * escrito e o que vale, se houver duvida depois.
 *
 * @method int getEpisodeId()
 * @method void setEpisodeId(int $episodeId)
 * @method int getMedicineId()
 * @method void setMedicineId(int $medicineId)
 * @method ?string getPosology()
 * @method void setPosology(?string $posology)
 * @method ?\DateTimeImmutable getStartedAt()
 * @method void setStartedAt(?\DateTimeImmutable $startedAt)
 * @method ?\DateTimeImmutable getEndedAt()
 * @method void setEndedAt(?\DateTimeImmutable $endedAt)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class EpisodeItem extends Entity implements \JsonSerializable {
	protected int $episodeId = 0;
	protected int $medicineId = 0;
	protected ?string $posology = null;
	protected ?\DateTimeImmutable $startedAt = null;
	protected ?\DateTimeImmutable $endedAt = null;
	protected ?string $notes = null;

	public function __construct() {
		$this->addType('episodeId', Types::INTEGER);
		$this->addType('medicineId', Types::INTEGER);
		$this->addType('startedAt', Types::DATE_IMMUTABLE);
		$this->addType('endedAt', Types::DATE_IMMUTABLE);
		$this->addType('notes', Types::TEXT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'episodeId' => $this->episodeId,
			'medicineId' => $this->medicineId,
			'posology' => $this->posology,
			'startedAt' => $this->startedAt?->format('Y-m-d'),
			'endedAt' => $this->endedAt?->format('Y-m-d'),
			'notes' => $this->notes,
		];
	}
}
