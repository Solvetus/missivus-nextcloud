<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Settings;

use OCA\Missivus\AppInfo\Application;
use OCA\Missivus\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private ConfigService $config,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');

		$values = [];
		$overridden = [];
		foreach (array_keys(ConfigService::KEYS) as $key) {
			// Secrets never travel to the page — only whether one is stored.
			$values[$key] = in_array($key, ConfigService::SECRET_KEYS, true)
				? ''
				: $this->config->get($key);
			$overridden[$key] = $this->config->hasOverride($key);
		}

		return new TemplateResponse(Application::APP_ID, 'admin', [
			'values' => $values,
			'overridden' => $overridden,
			'hasClientSecret' => $this->config->hasStoredValue('client_secret'),
			'hasCertificatePassphrase' => $this->config->hasStoredValue('certificate_passphrase'),
			'configProblem' => $this->config->getConfigurationProblem(),
			'lastFailure' => $this->config->getLastFailure(),
		]);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 10;
	}
}
