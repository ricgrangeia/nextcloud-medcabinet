<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\Db\Episode;
use OCA\MedCabinet\Db\EpisodeItem;
use OCA\MedCabinet\Db\EpisodeItemMapper;
use OCA\MedCabinet\Db\EpisodeMapper;
use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\PersonMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Os episodios: para que serviu cada medicamento, a quem e por indicacao de
 * quem.
 *
 * E o que responde, meses depois, a "o que e que lhe demos a ultima vez que
 * isto aconteceu" -- com proveniencia, nao como sugestao. A app diz o que foi
 * usado e quem o indicou; nunca o que se deve tomar.
 */
class EpisodeService {
	public function __construct(
		private EpisodeMapper $episodes,
		private EpisodeItemMapper $items,
		private MedicineMapper $medicines,
		private PersonMapper $people,
	) {
	}

	/**
	 * @return list<array>
	 */
	public function findAll(string $userId, ?int $personId = null, ?string $query = null): array {
		$episodes = $this->episodes->findAll($userId, $personId, $query);
		$byEpisode = $this->items->findAllForEpisodes(
			array_map(static fn (Episode $e) => $e->getId(), $episodes)
		);

		$names = $this->medicineNames($userId);
		$people = $this->personNames($userId);

		$out = [];
		foreach ($episodes as $episode) {
			$out[] = $this->decorate($episode, $byEpisode[$episode->getId()] ?? [], $names, $people);
		}
		return $out;
	}

	/** @throws DoesNotExistException */
	public function detail(int $id, string $userId): array {
		$episode = $this->episodes->find($id, $userId);
		return $this->decorate(
			$episode,
			$this->items->findAllForEpisode($id),
			$this->medicineNames($userId),
			$this->personNames($userId)
		);
	}

	/**
	 * O historico de um medicamento: em que episodios entrou.
	 *
	 * E a resposta directa a "para que e que isto serviu", e vem com data,
	 * pessoa e quem receitou -- os tres dados que fazem a diferenca entre um
	 * registo e um palpite.
	 *
	 * @throws DoesNotExistException
	 */
	public function forMedicine(int $medicineId, string $userId): array {
		$medicine = $this->medicines->find($medicineId, $userId);
		$people = $this->personNames($userId);

		$uses = [];
		foreach ($this->items->findAllForMedicine($medicine->getId()) as $item) {
			try {
				$episode = $this->episodes->find($item->getEpisodeId(), $userId);
			} catch (DoesNotExistException) {
				continue;
			}
			$uses[] = [
				'episodeId' => $episode->getId(),
				'reason' => $episode->getReason(),
				'person' => $episode->getPersonId() === null
					? null
					: ($people[$episode->getPersonId()] ?? null),
				'prescriber' => $episode->getPrescriber(),
				'startedAt' => $episode->getStartedAt()?->format('Y-m-d'),
				'endedAt' => $episode->getEndedAt()?->format('Y-m-d'),
				'posology' => $item->getPosology(),
				'outcome' => $episode->getOutcome(),
			];
		}

		return ['medicine' => $medicine->jsonSerialize(), 'uses' => $uses];
	}

	/**
	 * @param EpisodeItem[] $items
	 * @param array<int, string> $medicineNames
	 * @param array<int, string> $personNames
	 */
	private function decorate(Episode $episode, array $items, array $medicineNames, array $personNames): array {
		$plainItems = [];
		foreach ($items as $item) {
			$plainItems[] = $item->jsonSerialize() + [
				'medicineName' => $medicineNames[$item->getMedicineId()] ?? null,
			];
		}

		return $episode->jsonSerialize() + [
			'personName' => $episode->getPersonId() === null
				? null
				: ($personNames[$episode->getPersonId()] ?? null),
			'items' => $plainItems,
		];
	}

	/** @return array<int, string> */
	private function medicineNames(string $userId): array {
		$names = [];
		foreach ($this->medicines->findAll($userId) as $medicine) {
			$label = $medicine->getName();
			if ($medicine->getStrength() !== null) {
				$label .= ' ' . $medicine->getStrength();
			}
			$names[$medicine->getId()] = $label;
		}
		return $names;
	}

	/** @return array<int, string> */
	private function personNames(string $userId): array {
		$names = [];
		foreach ($this->people->findAll($userId) as $person) {
			$names[$person->getId()] = $person->getName();
		}
		return $names;
	}

	public function create(string $userId, array $data): array {
		$episode = new Episode();
		$episode->setUserId($userId);
		$this->apply($episode, $data, true, $userId);
		$episode->setCreatedAt(new \DateTimeImmutable());
		$episode = $this->episodes->insert($episode);

		foreach ($data['items'] ?? [] as $raw) {
			$this->addItemTo($episode->getId(), $userId, (array)$raw);
		}

		return $this->detail($episode->getId(), $userId);
	}

	/** @throws DoesNotExistException */
	public function update(int $id, string $userId, array $data): array {
		$episode = $this->episodes->find($id, $userId);
		$this->apply($episode, $data, false, $userId);
		$this->episodes->update($episode);
		return $this->detail($id, $userId);
	}

	private function apply(Episode $episode, array $data, bool $creating, string $userId): void {
		if ($creating || array_key_exists('reason', $data)) {
			$episode->setReason(trim((string)($data['reason'] ?? '')));
		}
		if (array_key_exists('personId', $data)) {
			$personId = $data['personId'];
			if ($personId === null || $personId === '') {
				$episode->setPersonId(null);
			} else {
				// Confirma que a pessoa e dele antes de a associar.
				$episode->setPersonId($this->people->find((int)$personId, $userId)->getId());
			}
		}
		foreach (['startedAt', 'endedAt'] as $field) {
			if ($creating || array_key_exists($field, $data)) {
				$episode->{'set' . ucfirst($field)}($this->date($data[$field] ?? null));
			}
		}
		foreach (['prescriber', 'outcome', 'notes'] as $field) {
			if (array_key_exists($field, $data)) {
				$value = $data[$field];
				$episode->{'set' . ucfirst($field)}(
					$value === null || trim((string)$value) === '' ? null : trim((string)$value)
				);
			}
		}
	}

	/** @throws DoesNotExistException */
	public function addItemTo(int $episodeId, string $userId, array $data): array {
		$episode = $this->episodes->find($episodeId, $userId);
		$medicine = $this->medicines->find((int)($data['medicineId'] ?? 0), $userId);

		$item = new EpisodeItem();
		$item->setEpisodeId($episode->getId());
		$item->setMedicineId($medicine->getId());
		$item->setPosology($this->text($data['posology'] ?? null));
		$item->setStartedAt($this->date($data['startedAt'] ?? null));
		$item->setEndedAt($this->date($data['endedAt'] ?? null));
		$item->setNotes($this->text($data['notes'] ?? null));

		return $this->items->insert($item)->jsonSerialize();
	}

	/** @throws DoesNotExistException */
	public function deleteItem(int $id, string $userId): void {
		$item = $this->items->find($id);
		// Confirma a posse pelo episodio, que e quem tem o user_id.
		$this->episodes->find($item->getEpisodeId(), $userId);
		$this->items->delete($item);
	}

	/** @throws DoesNotExistException */
	public function delete(int $id, string $userId): void {
		$episode = $this->episodes->find($id, $userId);
		$this->items->deleteForEpisode($episode->getId());
		$this->episodes->delete($episode);
	}

	private function text(mixed $value): ?string {
		return $value === null || trim((string)$value) === '' ? null : trim((string)$value);
	}

	private function date(mixed $value): ?\DateTimeImmutable {
		if ($value === null || trim((string)$value) === '') {
			return null;
		}
		try {
			return new \DateTimeImmutable((string)$value);
		} catch (\Exception) {
			return null;
		}
	}
}
