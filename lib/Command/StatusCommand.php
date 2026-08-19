<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Command;

use OCA\Missivus\Service\ConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command {
	public function __construct(
		private ConfigService $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('missivus:status')
			->setDescription('Show the resolved Missivus configuration (never secrets) and the last failure');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = [
			'enabled' => $this->config->getBool('enabled') ? 'yes' : 'no',
			'tenant_id' => $this->valueOrUnset('tenant_id'),
			'client_id' => $this->valueOrUnset('client_id'),
			'auth_method' => $this->config->get('auth_method'),
			'client_secret' => $this->secretState('client_secret'),
			'certificate_path' => $this->valueOrUnset('certificate_path'),
			'certificate_passphrase' => $this->secretState('certificate_passphrase'),
			'sender_mailbox' => $this->valueOrUnset('sender_mailbox'),
			'save_to_sent' => $this->config->getBool('save_to_sent') ? 'yes' : 'no',
			'fallback_to_system' => $this->config->getBool('fallback_to_system') ? 'yes' : 'no',
		];
		foreach ($rows as $key => $value) {
			$source = $this->config->hasOverride($key) ? ' (config.php)' : '';
			$output->writeln(sprintf('%-24s %s%s', $key . ':', $value, $source));
		}

		$problem = $this->config->getConfigurationProblem();
		$output->writeln('');
		$output->writeln($problem === null
			? 'Configuration: complete, ready to send.'
			: 'Configuration problem: ' . $problem);

		$failure = $this->config->getLastFailure();
		if ($failure !== null) {
			$output->writeln(sprintf(
				'Last failure (%s): %s',
				date('c', $failure['at']),
				$failure['message']
			));
		}
		return 0;
	}

	private function valueOrUnset(string $key): string {
		$value = $this->config->get($key);
		return $value === '' ? '(not set)' : $value;
	}

	/** The state of a secret, never its value. */
	private function secretState(string $key): string {
		return $this->config->hasStoredValue($key) ? 'set' : '(not set)';
	}
}
