<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Mail;

use OCA\Missivus\Adapter\HttpClient;
use OCA\Missivus\Adapter\Logger;
use OCA\Missivus\Adapter\TokenCache;
use OCA\Missivus\Service\ConfigService;
use OCP\Defaults;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Mail\IMailer;

/**
 * Builds the decorator around core's mailer. Exists so the boot closure stays
 * a one-liner and everything else is autowired from the app container.
 */
class MailerFactory {
	public function __construct(
		private ConfigService $config,
		private MessageConverter $converter,
		private HttpClient $httpClient,
		private TokenCache $tokenCache,
		private Logger $logger,
		private IEventDispatcher $dispatcher,
		private Defaults $defaults,
	) {
	}

	public function decorate(IMailer $inner): GraphMailer {
		return new GraphMailer(
			$inner,
			$this->config,
			$this->converter,
			$this->httpClient,
			$this->tokenCache,
			$this->logger,
			$this->dispatcher,
			$this->defaults,
		);
	}
}
