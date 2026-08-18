<?php

declare(strict_types=1);

/**
 * Missivus — send Nextcloud email through the Microsoft Graph API.
 *
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\AppInfo;

use OCA\Missivus\Mail\GraphMailer;
use OCA\Missivus\Mail\MailerFactory;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'missivus';

	public function __construct() {
		parent::__construct(self::APP_ID);
		self::registerVendorAutoloader();
	}

	/**
	 * Nextcloud's app autoloader only maps OCA\Missivus\ onto lib/. The vendored
	 * transport keeps its upstream Solvetus\Missivus\ namespace (it is shipped
	 * byte-identical across the Missivus family), so it gets its own tiny loader.
	 */
	public static function registerVendorAutoloader(): void {
		static $registered = false;
		if ($registered) {
			return;
		}
		$registered = true;
		spl_autoload_register(static function (string $class): void {
			$prefix = 'Solvetus\\Missivus\\';
			if (!str_starts_with($class, $prefix)) {
				return;
			}
			$path = __DIR__ . '/../Vendor/Solvetus/Missivus/'
				. str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require $path;
			}
		});
	}

	public function register(IRegistrationContext $context): void {
		// Settings, sections and occ commands are declared in appinfo/info.xml.
	}

	public function boot(IBootContext $context): void {
		// The seam. IMailer is a lazy service in the server container and
		// SimpleContainer::registerService replaces an existing registration,
		// so core and every app resolve the decorator from here on. Resolving
		// core's mailer first reuses core's own factory wiring instead of
		// duplicating it. A failure here must degrade to "mail works as
		// before", never to "no mail".
		try {
			$server = $context->getServerContainer();
			$inner = $server->get(IMailer::class);
			if ($inner instanceof GraphMailer) {
				return;
			}
			$appContainer = $context->getAppContainer();
			$server->registerService(IMailer::class, static function () use ($appContainer, $inner) {
				/** @var MailerFactory $factory */
				$factory = $appContainer->get(MailerFactory::class);
				return $factory->decorate($inner);
			});
		} catch (\Throwable $e) {
			try {
				$context->getServerContainer()->get(LoggerInterface::class)->error(
					'Missivus could not replace the system mailer; Nextcloud\'s own mailer stays active: ' . $e->getMessage(),
					['app' => self::APP_ID, 'exception' => $e]
				);
			} catch (\Throwable) {
				// Logging is best-effort; mail keeps working either way.
			}
		}
	}
}
