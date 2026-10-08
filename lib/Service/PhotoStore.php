<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Service;

use OCA\MedCabinet\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;

/**
 * Onde ficam as fotografias das caixas.
 *
 * Ficam nos Ficheiros do proprio utilizador, nao no appdata da app. Duas
 * razoes que apontam para o mesmo lado: a TaskProcessing so aceita ficheiros
 * a que o utilizador tem acesso, e a fotografia da caixa e a prova de onde a
 * validade saiu -- vale mais guardada onde ele a encontra do que escondida
 * num sitio que so a app conhece.
 *
 * Esta separado do AiScanService de proposito. Aqui nao ha decisao nenhuma,
 * so acesso a ficheiros; la esta a unica coisa que vale a pena testar, e
 * testa-se sem precisar de um servidor de ficheiros.
 */
class PhotoStore {
	private const CONFIG_FOLDER = 'ai_photos_folder';
	private const DEFAULT_FOLDER = 'Medicamentos/Caixas';
	private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
	) {
	}

	public function folderPath(): string {
		$path = trim($this->appConfig->getValueString(
			Application::APP_ID, self::CONFIG_FOLDER, self::DEFAULT_FOLDER
		));
		return trim($path === '' ? self::DEFAULT_FOLDER : $path, '/');
	}

	/**
	 * @param list<array{name: string, bytes: string}> $photos
	 * @return array{fileIds: list<int>, names: list<string>}
	 * @throws ScanException
	 */
	public function store(string $userId, array $photos): array {
		$folder = $this->ensureFolder($userId);
		$stamp = (new \DateTimeImmutable())->format('Ymd-His');

		$ids = [];
		$names = [];

		foreach ($photos as $i => $photo) {
			$extension = strtolower((string)pathinfo($photo['name'], PATHINFO_EXTENSION));
			if (!in_array($extension, self::EXTENSIONS, true)) {
				$extension = 'jpg';
			}
			$name = sprintf('%s-%d.%s', $stamp, $i + 1, $extension);
			$file = $folder->newFile($name, $photo['bytes']);
			$ids[] = $file->getId();
			$names[] = $name;
		}

		return ['fileIds' => $ids, 'names' => $names];
	}

	/**
	 * A TaskProcessing recusa ficheiros a que o utilizador nao tem acesso, com
	 * uma excecao generica la dentro. Confere-se aqui para a mensagem poder
	 * dizer qual o ficheiro, em vez de so "falhou".
	 *
	 * @param list<int> $fileIds
	 * @throws ScanException
	 */
	public function assertReadable(string $userId, array $fileIds): void {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($fileIds as $fileId) {
			if ($userFolder->getFirstNodeById((int)$fileId) === null) {
				throw new ScanException(sprintf(
					'O ficheiro %d não existe nos teus ficheiros, ou não tens acesso a ele.',
					$fileId
				));
			}
		}
	}

	/** @throws ScanException */
	private function ensureFolder(string $userId): Folder {
		$current = $this->rootFolder->getUserFolder($userId);

		foreach (explode('/', $this->folderPath()) as $segment) {
			if ($segment === '') {
				continue;
			}
			$next = $current->nodeExists($segment)
				? $current->get($segment)
				: $current->newFolder($segment);
			if (!$next instanceof Folder) {
				throw new ScanException(sprintf(
					'"%s" já existe nos teus ficheiros e não é uma pasta.', $this->folderPath()
				));
			}
			$current = $next;
		}

		return $current;
	}
}
