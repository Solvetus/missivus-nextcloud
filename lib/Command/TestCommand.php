<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Command;

use OCA\Missivus\Mail\GraphMailer;
use OCA\Missivus\Mail\MailerFactory;
use OCP\Mail\IEmailValidator;
use OCP\Mail\IMailer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends Command {
	public function __construct(
		private IMailer $mailer,
		private MailerFactory $mailerFactory,
		private IEmailValidator $emailValidator,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('missivus:test')
			->setDescription('Send a test email through Microsoft Graph (ignores the enabled switch and the fallback)')
			->addArgument('address', InputArgument::REQUIRED, 'Recipient email address');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$address = trim((string)$input->getArgument('address'));
		if (!$this->emailValidator->isValid($address)) {
			$output->writeln('<error>Not a valid email address.</error>');
			return 1;
		}

		$mailer = $this->mailer instanceof GraphMailer
			? $this->mailer
			: $this->mailerFactory->decorate($this->mailer);

		$message = $mailer->createMessage();
		$message->setTo([$address]);
		$message->setSubject('Missivus test email');
		$message->setPlainBody('This message was sent from your Nextcloud through Microsoft Graph by the Missivus app. Delivery works.');

		try {
			$mailer->sendWithoutFallback($message);
		} catch (\Throwable $e) {
			// GraphException messages arrive redacted; anything else goes
			// through the same final pass.
			$output->writeln('<error>Sending through Microsoft Graph failed:</error>');
			$output->writeln($mailer->redact($e->getMessage()));
			return 1;
		}
		$output->writeln('Test email sent to ' . $address . ' through Microsoft Graph.');
		return 0;
	}
}
