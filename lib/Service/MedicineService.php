<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\Db\Medicine;
use OCA\MedCabinet\Db\MedicineMapper;
use OCA\MedCabinet\Db\Package;
use OCA\MedCabinet\Db\PackageMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Medicamentos e as caixas deles.
 *
 * O estado de cada caixa -- validade efetiva, quanto resta, qual usar
 * primeiro -- nunca e guardado: e sempre calculado pelo CabinetService a
 * partir dos dados em bruto. Guardado, ficava errado no dia em que se
 * registasse a abertura de um frasco, e nada no sistema saberia que tinha de
 * o recalcular.
 */
class MedicineService {
	public function __construct(
		private MedicineMapper $medicines,
		private PackageMapper $packages,
		private CabinetService $cabinet,
	) {
	}

	/** @return list<array> */
	public function findAll(string $userId, ?string $query, string $today): array {
		$medicines = $this->medicines->findAll($userId, $query);
		$byMedicine = $this->packages->findAllForMedicines(
			array_map(static fn (Medicine $m) => $m->getId(), $medicines)
		);

		$out = [];
		foreach ($medicines as $medicine) {
			$out[] = $this->decorate($medicine, $byMedicine[$medicine->getId()] ?? [], $today);
		}
		return $out;
	}

	/** @throws DoesNotExistException */
	public function detail(int $id, string $userId, string $today): array {
		$medicine = $this->medicines->find($id, $userId);
		return $this->decorate($medicine, $this->packages->findAllForMedicine($id), $today);
	}

	/**
	 * Junta ao medicamento o estado das suas caixas.
	 *
	 * @param Package[] $packages
	 */
	private function decorate(Medicine $medicine, array $packages, string $today): array {
		$plain = $medicine->jsonSerialize();
		$withStatus = [];
		$unitsLeft = 0.0;
		$anyUnits = false;

		foreach ($packages as $package) {
			$status = $this->cabinet->packageStatus(
				$package->jsonSerialize(),
				$plain,
				$today
			);
			$entry = $package->jsonSerialize() + $status;
			$withStatus[] = $entry;

			if ($package->getDiscardedAt() === null
				&& $status['status'] !== CabinetService::STATUS_EXPIRED
				&& $package->getUnitsLeft() !== null) {
				$unitsLeft += $package->getUnitsLeft();
				$anyUnits = true;
			}
		}

		$useFirst = $this->cabinet->useFirst($withStatus);

		return $plain + [
			'packages' => $withStatus,
			// Soma apenas o que esta utilizavel: contar caixas fora de prazo
			// no stock dava a entender que havia o que nao ha.
			'unitsUsable' => $anyUnits ? round($unitsLeft, 3) : null,
			'useFirstPackageId' => $useFirst[0]['id'] ?? null,
			'worstStatus' => $this->worstOf($withStatus),
		];
	}

	/**
	 * O estado que deve aparecer na lista: o pior das caixas utilizaveis.
	 *
	 * Uma caixa boa nao apaga uma fora de prazo que esta no armario a ocupar
	 * espaco e a enganar quem procura.
	 *
	 * @param list<array> $packages
	 */
	private function worstOf(array $packages): string {
		$order = [
			CabinetService::STATUS_EXPIRED => 0,
			CabinetService::STATUS_UNKNOWN => 1,
			CabinetService::STATUS_EXPIRING => 2,
			CabinetService::STATUS_OK => 3,
			CabinetService::STATUS_DISCARDED => 4,
		];
		$worst = null;
		foreach ($packages as $package) {
			if ($package['status'] === CabinetService::STATUS_DISCARDED) {
				continue;
			}
			if ($worst === null || $order[$package['status']] < $order[$worst]) {
				$worst = $package['status'];
			}
		}
		return $worst ?? CabinetService::STATUS_UNKNOWN;
	}

	public function create(string $userId, array $data): array {
		$medicine = new Medicine();
		$medicine->setUserId($userId);
		$this->apply($medicine, $data, true);
		$medicine->setCreatedAt(new \DateTimeImmutable());
		return $this->medicines->insert($medicine)->jsonSerialize();
	}

	/** @throws DoesNotExistException */
	public function update(int $id, string $userId, array $data): array {
		$medicine = $this->medicines->find($id, $userId);
		$this->apply($medicine, $data, false);
		return $this->medicines->update($medicine)->jsonSerialize();
	}

	private function apply(Medicine $medicine, array $data, bool $creating): void {
		if ($creating || array_key_exists('name', $data)) {
			$medicine->setName(trim((string)($data['name'] ?? '')));
		}
		foreach (['substance', 'strength', 'form', 'gtin', 'notes'] as $field) {
			if (array_key_exists($field, $data)) {
				$value = $data[$field];
				$medicine->{'set' . ucfirst($field)}(
					$value === null || trim((string)$value) === '' ? null : trim((string)$value)
				);
			}
		}
		if ($creating || array_key_exists('unit', $data)) {
			$unit = trim((string)($data['unit'] ?? ''));
			$medicine->setUnit($unit === '' ? 'unidade' : $unit);
		}
		if (array_key_exists('daysAfterOpening', $data)) {
			$days = $data['daysAfterOpening'];
			$medicine->setDaysAfterOpening($days === null || $days === '' ? null : max(1, (int)$days));
		}
	}

	/** @throws DoesNotExistException */
	public function delete(int $id, string $userId): void {
		$medicine = $this->medicines->find($id, $userId);
		$this->packages->deleteForMedicine($medicine->getId());
		$this->medicines->delete($medicine);
	}

	// ------------------------------------------------------------- Embalagens

	/**
	 * As caixas pertencem ao medicamento, e o medicamento ao utilizador. Toda
	 * a operacao sobre uma caixa passa por confirmar isso -- e a unica forma
	 * de o `user_id` nao ter de andar repetido na tabela das caixas.
	 *
	 * @throws DoesNotExistException
	 */
	private function assertOwned(int $medicineId, string $userId): Medicine {
		return $this->medicines->find($medicineId, $userId);
	}

	/** @throws DoesNotExistException */
	public function addPackage(int $medicineId, string $userId, array $data): array {
		$this->assertOwned($medicineId, $userId);

		$package = new Package();
		$package->setMedicineId($medicineId);
		$this->applyPackage($package, $data, true);
		$package->setCreatedAt(new \DateTimeImmutable());
		return $this->packages->insert($package)->jsonSerialize();
	}

	/** @throws DoesNotExistException */
	public function updatePackage(int $id, string $userId, array $data): array {
		$package = $this->packages->find($id);
		$this->assertOwned($package->getMedicineId(), $userId);
		$this->applyPackage($package, $data, false);
		return $this->packages->update($package)->jsonSerialize();
	}

	private function applyPackage(Package $package, array $data, bool $creating): void {
		if ($creating || array_key_exists('unitsTotal', $data)) {
			$total = $data['unitsTotal'] ?? null;
			$package->setUnitsTotal($total === null || $total === '' ? null : (float)$total);
		}
		if ($creating || array_key_exists('unitsLeft', $data)) {
			$left = $data['unitsLeft'] ?? null;
			// Ao registar uma caixa nova sem dizer quanto resta, resta tudo.
			$package->setUnitsLeft(
				$left === null || $left === ''
					? ($creating ? $package->getUnitsTotal() : null)
					: (float)$left
			);
		}
		foreach (['expiresAt', 'openedAt', 'discardedAt'] as $field) {
			if (array_key_exists($field, $data)) {
				$package->{'set' . ucfirst($field)}($this->date($data[$field]));
			}
		}
		foreach (['batch', 'location'] as $field) {
			if (array_key_exists($field, $data)) {
				$value = $data[$field];
				$package->{'set' . ucfirst($field)}(
					$value === null || trim((string)$value) === '' ? null : trim((string)$value)
				);
			}
		}
		if ($creating || array_key_exists('source', $data)) {
			$source = (string)($data['source'] ?? Package::SOURCE_MANUAL);
			$package->setSource(in_array($source, [
				Package::SOURCE_MANUAL, Package::SOURCE_DATAMATRIX, Package::SOURCE_PRESCRIPTION,
			], true) ? $source : Package::SOURCE_MANUAL);
		}
	}

	/** @throws DoesNotExistException */
	public function deletePackage(int $id, string $userId): void {
		$package = $this->packages->find($id);
		$this->assertOwned($package->getMedicineId(), $userId);
		$this->packages->delete($package);
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
