<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Para que serviu: uma pessoa, um motivo, um intervalo, quem assistiu.
 *
 * E o centro da app. O mesmo medicamento serve fins diferentes em epocas
 * diferentes, e por isso a finalidade vive aqui e nao no medicamento --
 * guardada la, perdia-se a distincao que torna o registo util meses depois.
 *
 * O `prescriber` faz parte da proveniencia: "receitado pelo Dr. X" vale
 * diferente de "demos nos".
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?int getPersonId()
 * @method void setPersonId(?int $personId)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method ?\DateTimeImmutable getStartedAt()
 * @method void setStartedAt(?\DateTimeImmutable $startedAt)
 * @method ?\DateTimeImmutable getEndedAt()
 * @method void setEndedAt(?\DateTimeImmutable $endedAt)
 * @method ?string getPrescriber()
 * @method void setPrescriber(?string $prescriber)
 * @method ?string getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class Episode extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected ?int $personId = null;
	protected string $reason = '';
	protected ?\DateTimeImmutable $startedAt = null;
	protected ?\DateTimeImmutable $endedAt = null;
	protected ?string $prescriber = null;
	protected ?string $outcome = null;
	protected ?string $notes = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('personId', Types::INTEGER);
		$this->addType('startedAt', Types::DATE_IMMUTABLE);
		$this->addType('endedAt', Types::DATE_IMMUTABLE);
		$this->addType('outcome', Types::TEXT);
		$this->addType('notes', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'personId' => $this->personId,
			'reason' => $this->reason,
			'startedAt' => $this->startedAt?->format('Y-m-d'),
			'endedAt' => $this->endedAt?->format('Y-m-d'),
			'prescriber' => $this->prescriber,
			'outcome' => $this->outcome,
			'notes' => $this->notes,
		];
	}
}
