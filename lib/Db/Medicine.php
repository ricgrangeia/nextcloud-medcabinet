<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Um medicamento como produto -- nao uma caixa.
 *
 * A `substance` e o campo que torna a pesquisa util: sem catalogo externo, e
 * a unica maneira de "Brufen" e "ibuprofeno" se encontrarem um ao outro.
 *
 * O `daysAfterOpening` e o que impede o aviso de validade de mentir. Ver
 * CabinetService.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getSubstance()
 * @method void setSubstance(?string $substance)
 * @method ?string getStrength()
 * @method void setStrength(?string $strength)
 * @method ?string getForm()
 * @method void setForm(?string $form)
 * @method string getUnit()
 * @method void setUnit(string $unit)
 * @method ?int getDaysAfterOpening()
 * @method void setDaysAfterOpening(?int $daysAfterOpening)
 * @method ?string getGtin()
 * @method void setGtin(?string $gtin)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class Medicine extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $name = '';
	protected ?string $substance = null;
	protected ?string $strength = null;
	protected ?string $form = null;
	protected string $unit = 'unidade';
	protected ?int $daysAfterOpening = null;
	protected ?string $gtin = null;
	protected ?string $notes = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('daysAfterOpening', Types::INTEGER);
		$this->addType('notes', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'substance' => $this->substance,
			'strength' => $this->strength,
			'form' => $this->form,
			'unit' => $this->unit,
			'daysAfterOpening' => $this->daysAfterOpening,
			'gtin' => $this->gtin,
			'notes' => $this->notes,
		];
	}
}
