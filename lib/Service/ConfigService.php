<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Service;

use OCA\Missivus\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IEmailValidator;
use Solvetus\Missivus\Auth\ClientAssertion;
use Solvetus\Missivus\Auth\Credentials;

/**
 * The settings model. Precedence for every key: the `missivus` array in
 * config.php wins over appconfig, which wins over the default. A value managed
 * in config.php is shown as such in the UI and is never copied into appconfig —
 * in particular a file-managed secret never reaches the database.
 */
class ConfigService {
	public const KEYS = [
		'enabled' => '0',
		'tenant_id' => '',
		'client_id' => '',
		'auth_method' => Credentials::METHOD_SECRET,
		'client_secret' => '',
		'certificate_path' => '',
		'certificate_passphrase' => '',
		'sender_mailbox' => '',
		'save_to_sent' => '0',
		'fallback_to_system' => '0',
	];

	/** Config.php-only escape hatches, deliberately without UI. */
	public const FILE_ONLY_KEYS = [
		'graph_base_url' => 'https://graph.microsoft.com',
		'login_base_url' => 'https://login.microsoftonline.com',
		'certificate_algorithm' => ClientAssertion::ALG_PS256,
	];

	public const SECRET_KEYS = ['client_secret', 'certificate_passphrase'];
	private const BOOL_KEYS = ['enabled', 'save_to_sent', 'fallback_to_system'];

	/**
	 * Nextcloud core stores every enabled app's state as the appconfig key
	 * "enabled" ("yes") inside the app's own namespace — so the master switch
	 * must live under a different storage key, or enabling the *app* would
	 * flip the *switch*. The public name (UI, config.php) stays "enabled".
	 */
	private const STORAGE_KEYS = ['enabled' => 'graph_enabled'];

	private function storageKey(string $key): string {
		return self::STORAGE_KEYS[$key] ?? $key;
	}

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private IEmailValidator $emailValidator,
		private IL10N $l,
	) {
	}

	/** @return array<string, mixed> */
	private function fileOverrides(): array {
		$overrides = $this->config->getSystemValue(Application::APP_ID, []);
		return is_array($overrides) ? $overrides : [];
	}

	public function hasOverride(string $key): bool {
		return array_key_exists($key, $this->fileOverrides());
	}

	public function get(string $key): string {
		$defaults = self::KEYS + self::FILE_ONLY_KEYS;
		if (!array_key_exists($key, $defaults)) {
			throw new \InvalidArgumentException('Unknown Missivus setting: ' . $key);
		}
		$overrides = $this->fileOverrides();
		if (array_key_exists($key, $overrides)) {
			return $this->normalise($key, $overrides[$key]);
		}
		if (array_key_exists($key, self::FILE_ONLY_KEYS)) {
			return $defaults[$key];
		}
		$stored = $this->appConfig->getValueString(Application::APP_ID, $this->storageKey($key), '', lazy: true);
		return $stored === '' ? $defaults[$key] : $this->normalise($key, $stored);
	}

	private function normalise(string $key, mixed $value): string {
		if (in_array($key, self::BOOL_KEYS, true)) {
			return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
		}
		return trim((string)$value);
	}

	public function getBool(string $key): bool {
		return $this->get($key) === '1';
	}

	public function isEnabled(): bool {
		return $this->getBool('enabled');
	}

	/**
	 * Store one UI-editable value. Values for keys managed in config.php are
	 * discarded, so a save from the settings page can never copy a
	 * file-managed value — a secret included — into the database. Secrets keep
	 * their stored value when the posted value is empty (write-only fields
	 * always render empty).
	 *
	 * @throws \InvalidArgumentException when the value fails validation
	 */
	public function set(string $key, string $value): void {
		if (!array_key_exists($key, self::KEYS)) {
			throw new \InvalidArgumentException('Unknown Missivus setting: ' . $key);
		}
		if ($this->hasOverride($key)) {
			return;
		}
		$isSecret = in_array($key, self::SECRET_KEYS, true);
		if ($isSecret && $value === '') {
			return;
		}
		$value = $this->normalise($key, $value);
		$this->validate($key, $value);
		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->storageKey($key),
			$value,
			lazy: true,
			sensitive: $isSecret,
		);
	}

	/** @throws \InvalidArgumentException */
	private function validate(string $key, string $value): void {
		if ($value === '') {
			// An empty value is a legitimate state while the switch is off.
			return;
		}
		$guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		$domain = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';
		switch ($key) {
			case 'tenant_id':
				if (!preg_match($guid, $value) && !preg_match($domain, $value)) {
					throw new \InvalidArgumentException($this->l->t('The Directory (tenant) ID must be a GUID or a domain name.'));
				}
				break;
			case 'client_id':
				if (!preg_match($guid, $value)) {
					throw new \InvalidArgumentException($this->l->t('The Application (client) ID must be a GUID.'));
				}
				break;
			case 'auth_method':
				if (!in_array($value, [Credentials::METHOD_SECRET, Credentials::METHOD_CERTIFICATE], true)) {
					throw new \InvalidArgumentException($this->l->t('Unknown authentication method.'));
				}
				break;
			case 'sender_mailbox':
				if (!$this->emailValidator->isValid($value)) {
					throw new \InvalidArgumentException($this->l->t('The sender mailbox must be an email address.'));
				}
				break;
			case 'certificate_path':
				if (!str_starts_with($value, '/') || str_contains($value, "\0")) {
					throw new \InvalidArgumentException($this->l->t('The certificate path must be an absolute path.'));
				}
				break;
			case 'client_secret':
			case 'certificate_passphrase':
				// A credential with whitespace or control characters, or one longer
				// than any Entra credential, is a copy-and-paste accident every time.
				// Nothing may ever print the value back, so the message stays generic.
				if (strlen($value) > 1024 || preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
					throw new \InvalidArgumentException($this->l->t('That does not look like a valid credential. Please copy the value again.'));
				}
				break;
		}
	}

	/**
	 * Whether a stored secret exists for the key, without revealing it.
	 */
	public function hasStoredValue(string $key): bool {
		return $this->get($key) !== '';
	}

	/**
	 * The one reason the transport could not send right now, or null when it
	 * can. The wording names the missing part; it never carries a value.
	 */
	public function getConfigurationProblem(): ?string {
		if ($this->get('tenant_id') === '') {
			return $this->l->t('The Directory (tenant) ID is not set.');
		}
		if ($this->get('client_id') === '') {
			return $this->l->t('The Application (client) ID is not set.');
		}
		if ($this->get('sender_mailbox') === '') {
			return $this->l->t('The sender mailbox is not set.');
		}
		if ($this->get('auth_method') === Credentials::METHOD_CERTIFICATE) {
			if ($this->get('certificate_path') === '') {
				return $this->l->t('The certificate path is not set.');
			}
		} elseif ($this->get('client_secret') === '') {
			return $this->l->t('The client secret is not set.');
		}
		return null;
	}

	public function buildCredentials(): Credentials {
		$credentials = new Credentials(
			$this->get('tenant_id'),
			$this->get('client_id'),
			$this->get('auth_method'),
		);
		if ($this->get('auth_method') === Credentials::METHOD_CERTIFICATE) {
			return $credentials->withCertificate(
				$this->get('certificate_path'),
				$this->get('certificate_passphrase'),
				$this->get('certificate_algorithm'),
			);
		}
		return $credentials->withClientSecret($this->get('client_secret'));
	}

	/**
	 * Remember the last failure (already redacted by the caller) so the admin
	 * settings page can surface it.
	 */
	public function recordFailure(string $redactedMessage): void {
		$this->appConfig->setValueString(Application::APP_ID, 'last_error', $redactedMessage, lazy: true);
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_at', (string)time(), lazy: true);
	}

	public function clearFailure(): void {
		$this->appConfig->setValueString(Application::APP_ID, 'last_error', '', lazy: true);
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_at', '', lazy: true);
	}

	/** @return array{message: string, at: int}|null */
	public function getLastFailure(): ?array {
		$message = $this->appConfig->getValueString(Application::APP_ID, 'last_error', '', lazy: true);
		if ($message === '') {
			return null;
		}
		return [
			'message' => $message,
			'at' => (int)$this->appConfig->getValueString(Application::APP_ID, 'last_error_at', '0', lazy: true),
		];
	}
}
