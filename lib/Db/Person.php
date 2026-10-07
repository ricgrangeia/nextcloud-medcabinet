<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Quem na casa toma medicamentos.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?\DateTimeImmutable getBirthDate()
 * @method void setBirthDate(?\DateTimeImmutable $birthDate)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class Person extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $name = '';
	protected ?\DateTimeImmutable $birthDate = null;
	protected ?string $notes = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('birthDate', Types::DATE_IMMUTABLE);
		$this->addType('notes', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'birthDate' => $this->birthDate?->format('Y-m-d'),
			'notes' => $this->notes,
		];
	}
}
