<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\Db\Person;
use OCA\MedCabinet\Db\PersonMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class PersonService {
	public function __construct(private PersonMapper $people) {
	}

	/** @return list<array> */
	public function findAll(string $userId): array {
		return array_map(
			static fn (Person $p) => $p->jsonSerialize(),
			$this->people->findAll($userId)
		);
	}

	public function create(string $userId, string $name, ?\DateTimeImmutable $birthDate, ?string $notes): array {
		$person = new Person();
		$person->setUserId($userId);
		$person->setName($name);
		$person->setBirthDate($birthDate);
		$person->setNotes($notes);
		$person->setCreatedAt(new \DateTimeImmutable());
		return $this->people->insert($person)->jsonSerialize();
	}

	/** @throws DoesNotExistException */
	public function update(int $id, string $userId, ?string $name, ?\DateTimeImmutable $birthDate, ?string $notes): array {
		$person = $this->people->find($id, $userId);
		if ($name !== null) {
			$person->setName($name);
		}
		if ($birthDate !== null) {
			$person->setBirthDate($birthDate);
		}
		if ($notes !== null) {
			$person->setNotes($notes);
		}
		return $this->people->update($person)->jsonSerialize();
	}

	/** @throws DoesNotExistException */
	public function delete(int $id, string $userId): void {
		$this->people->delete($this->people->find($id, $userId));
	}
}
