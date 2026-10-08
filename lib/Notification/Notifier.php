<?php

declare(strict_types=1);

namespace OCA\MedCabinet\Notification;

use OCA\MedCabinet\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Transforma as notificacoes da app em texto.
 *
 * A notificacao de uma leitura tem de dizer uma coisa acima de todas: se ha
 * campos por confirmar. Dizer so "leitura concluida" convidava a gravar sem
 * olhar, e e precisamente no olhar que esta o valor disto.
 */
class Notifier implements INotifier {
	public function __construct(private IURLGenerator $url) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Armário de Medicamentos';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$p = $notification->getSubjectParameters();
		$link = $this->url->linkToRouteAbsolute('medcabinet.page.index') . '#/scan';

		switch ($notification->getSubject()) {
			case 'scan_done':
				$name = trim((string)($p['name'] ?? ''));
				$review = (int)($p['review'] ?? 0);

				$notification->setParsedSubject(
					$name === ''
						? 'Fotografias lidas, sem nome de medicamento'
						: sprintf('%s: proposta pronta', $name)
				);

				$parts = [];
				$expiry = trim((string)($p['expiry'] ?? ''));
				$parts[] = $expiry === ''
					? 'Não se leu a validade.'
					: sprintf('Validade proposta: %s.', $this->prettyDate($expiry));

				// O numero de campos por confirmar e a parte que nao pode
				// faltar: e a diferenca entre gravar e ir ver a caixa.
				if ($review === 0) {
					$parts[] = 'Nada para confirmar.';
				} elseif ($review === 1) {
					$parts[] = 'Há 1 campo a confirmar antes de gravar.';
				} else {
					$parts[] = sprintf('Há %d campos a confirmar antes de gravar.', $review);
				}
				$notification->setParsedMessage(implode(' ', $parts));
				break;

			case 'scan_failed':
				$photos = (int)($p['photos'] ?? 0);
				$notification->setParsedSubject('Não foi possível ler as fotografias');
				$notification->setParsedMessage(sprintf(
					'%s — %s',
					$photos === 1 ? '1 fotografia' : sprintf('%d fotografias', $photos),
					$p['error'] ?? 'razão desconhecida'
				));
				break;

			default:
				throw new UnknownNotificationException();
		}

		$notification->setLink($link);
		$notification->setIcon($this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app.svg')
		));

		return $notification;
	}

	private function prettyDate(string $iso): string {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
		return $date === false ? $iso : $date->format('d/m/Y');
	}
}
