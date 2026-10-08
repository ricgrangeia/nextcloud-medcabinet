<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Fotografias de uma caixa a espera de serem lidas pela IA.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getFileIds()
 * @method void setFileIds(?string $fileIds)
 * @method ?string getFileNames()
 * @method void setFileNames(?string $fileNames)
 * @method ?string getStages()
 * @method void setStages(?string $stages)
 * @method ?string getProposal()
 * @method void setProposal(?string $proposal)
 * @method ?string getError()
 * @method void setError(?string $error)
 * @method ?\DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(?\DateTimeImmutable $createdAt)
 * @method ?\DateTimeImmutable getFinishedAt()
 * @method void setFinishedAt(?\DateTimeImmutable $finishedAt)
 */
class ScanJob extends Entity implements \JsonSerializable {
	public const PENDING = 'pending';
	public const RUNNING = 'running';
	public const DONE = 'done';
	public const FAILED = 'failed';

	/** Transcrever o que esta impresso: validade, lote, dosagem. */
	public const STAGE_OCR = 'ocr';
	/** Reconhecer o produto: que medicamento e, que forma, que substancia. */
	public const STAGE_VISION = 'vision';

	public const STAGE_WAITING = 'waiting';
	public const STAGE_OK = 'ok';
	public const STAGE_FAILED = 'failed';

	protected string $userId = '';
	protected string $status = self::PENDING;
	protected ?string $fileIds = null;
	protected ?string $fileNames = null;
	protected ?string $stages = null;
	protected ?string $proposal = null;
	protected ?string $error = null;
	protected ?\DateTimeImmutable $createdAt = null;
	protected ?\DateTimeImmutable $finishedAt = null;

	public function __construct() {
		$this->addType('fileIds', Types::TEXT);
		$this->addType('fileNames', Types::TEXT);
		$this->addType('stages', Types::TEXT);
		$this->addType('proposal', Types::TEXT);
		$this->addType('error', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
		$this->addType('finishedAt', Types::DATETIME_IMMUTABLE);
	}

	/** @return list<int> */
	public function fileIdList(): array {
		$decoded = json_decode((string)$this->fileIds, true);
		return is_array($decoded) ? array_map('intval', array_values($decoded)) : [];
	}

	/** @return list<string> */
	public function fileNameList(): array {
		$decoded = json_decode((string)$this->fileNames, true);
		return is_array($decoded) ? array_map('strval', array_values($decoded)) : [];
	}

	/** @return array<string, array{taskId: ?int, status: string, text: ?string, error: ?string}> */
	public function stageMap(): array {
		$decoded = json_decode((string)$this->stages, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setStageMap(array $stages): void {
		$this->setStages(json_encode($stages, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Verdadeiro quando nenhuma fase esta a espera. Nao e o mesmo que ter
	 * corrido bem: uma fase que falhou tambem ja respondeu, e a proposta
	 * monta-se com o que houver.
	 */
	public function stagesSettled(): bool {
		foreach ($this->stageMap() as $stage) {
			if (($stage['status'] ?? self::STAGE_WAITING) === self::STAGE_WAITING) {
				return false;
			}
		}
		return true;
	}

	public function jsonSerialize(): array {
		$stages = [];
		foreach ($this->stageMap() as $name => $stage) {
			// O texto em bruto nao vai no resumo: sao paginas de OCR, e quem
			// o quiser pede o detalhe. Vai o que se precisa para saber o ponto.
			$stages[$name] = [
				'status' => $stage['status'] ?? self::STAGE_WAITING,
				'taskId' => $stage['taskId'] ?? null,
				'error' => $stage['error'] ?? null,
			];
		}

		return [
			'id' => $this->id,
			'status' => $this->status,
			'files' => $this->fileNameList(),
			'fileIds' => $this->fileIdList(),
			'stages' => $stages,
			'proposal' => $this->proposal === null ? null : json_decode($this->proposal, true),
			'error' => $this->error,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
