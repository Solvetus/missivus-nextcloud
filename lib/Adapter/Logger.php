<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Adapter;

use OCA\Missivus\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Solvetus\Missivus\Contract\LoggerInterface as TransportLoggerInterface;

/**
 * Adapts the PSR logger to the transport's three-method contract. Messages
 * arrive pre-redacted from the transport; nothing here may add detail.
 */
class Logger implements TransportLoggerInterface {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function error($message): void {
		$this->logger->error((string)$message, ['app' => Application::APP_ID]);
	}

	#[\Override]
	public function warning($message): void {
		$this->logger->warning((string)$message, ['app' => Application::APP_ID]);
	}

	#[\Override]
	public function info($message): void {
		$this->logger->info((string)$message, ['app' => Application::APP_ID]);
	}
}
