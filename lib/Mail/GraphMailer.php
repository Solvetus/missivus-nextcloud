<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Mail;

use OC\Mail\Message as NextcloudMessage;
use OCA\Missivus\Adapter\HttpClient;
use OCA\Missivus\Adapter\Logger;
use OCA\Missivus\Adapter\TokenCache;
use OCA\Missivus\Service\ConfigService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Mail\Events\BeforeMessageSent;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\GraphMailer as GraphTransport;
use Solvetus\Missivus\Redactor;

/**
 * The IMailer decorator. Everything except send() delegates to core's mailer,
 * so message construction, templates and validation stay exactly Nextcloud's.
 * send() goes through Microsoft Graph when the app is enabled; while disabled
 * it is a pure pass-through.
 */
class GraphMailer implements IMailer {
	public function __construct(
		private IMailer $inner,
		private ConfigService $config,
		private MessageConverter $converter,
		private HttpClient $httpClient,
		private TokenCache $tokenCache,
		private Logger $logger,
		private IEventDispatcher $dispatcher,
	) {
	}

	public function createMessage(): IMessage {
		return $this->inner->createMessage();
	}

	public function createAttachment($data = null, $filename = null, $contentType = null): IAttachment {
		return $this->inner->createAttachment($data, $filename, $contentType);
	}

	public function createAttachmentFromPath(string $path, $contentType = null): IAttachment {
		return $this->inner->createAttachmentFromPath($path, $contentType);
	}

	public function createEMailTemplate(string $emailId, array $data = []): IEMailTemplate {
		return $this->inner->createEMailTemplate($emailId, $data);
	}

	public function validateMailAddress(string $email): bool {
		return $this->inner->validateMailAddress($email);
	}

	/**
	 * Mirrors core's contract: returns the failed recipients, throws when the
	 * message could not be sent. A Graph failure is logged at error level
	 * (redacted), recorded for the settings page, and then either rethrown
	 * (fallback off — the default) or handed to Nextcloud's own mailer
	 * (fallback on). Never silent, in either position of the switch.
	 *
	 * @return string[] failed recipients
	 * @throws \Exception
	 */
	public function send(IMessage $message): array {
		if (!$this->config->isEnabled()) {
			return $this->inner->send($message);
		}
		if (!($message instanceof NextcloudMessage)) {
			// Same guard as core's own mailer.
			throw new \InvalidArgumentException('Object not of type ' . NextcloudMessage::class);
		}

		try {
			$this->sendViaGraph($message);
			return [];
		} catch (\Throwable $e) {
			$redacted = $this->redactThrowable($e);
			$this->logger->error('Missivus could not send "' . $message->getSubject() . '" through Microsoft Graph: ' . $redacted->getMessage());
			$this->config->recordFailure($redacted->getMessage());
			if ($this->config->getBool('fallback_to_system')) {
				$this->logger->warning('Missivus: falling back to the Nextcloud system mailer.');
				return $this->inner->send($message);
			}
			throw $redacted;
		}
	}

	/**
	 * The test path: ignores the enabled switch and the fallback (a test that
	 * quietly succeeded over SMTP proves nothing about Graph) and throws the
	 * redacted failure for the caller to surface.
	 *
	 * @throws GraphException
	 */
	public function sendWithoutFallback(IMessage $message): void {
		if (!($message instanceof NextcloudMessage)) {
			throw new \InvalidArgumentException('Object not of type ' . NextcloudMessage::class);
		}
		try {
			$this->sendViaGraph($message);
		} catch (\Throwable $e) {
			throw $this->redactThrowable($e);
		}
	}

	public function getInner(): IMailer {
		return $this->inner;
	}

	/** @throws GraphException */
	private function sendViaGraph(NextcloudMessage $message): void {
		$problem = $this->config->getConfigurationProblem();
		if ($problem !== null) {
			throw new GraphException('Missivus is enabled but cannot send: ' . $problem);
		}

		// Core dispatches this inside its send(); listeners keep seeing every
		// message on the Graph path too. On the fallback path core dispatches
		// again — accepted and documented in PLAN.md.
		$this->dispatcher->dispatchTyped(new BeforeMessageSent($message));

		// Core would fabricate a default From here; on the Graph path the From
		// is the configured sender mailbox regardless, and reproducing the
		// fabricated address would only manufacture a bogus warning and
		// Reply-To on every send. An empty From converts warning-free.
		$transport = $this->buildTransport();
		$graphMessage = $this->converter->convert($message, $this->config->get('sender_mailbox'));
		foreach ($this->converter->getWarnings() as $warning) {
			$this->logger->warning($this->redactor()->redact($warning));
		}
		$transport->send($graphMessage);
	}

	private function buildTransport(): GraphTransport {
		$redactor = $this->redactor();
		$tokens = new TokenProvider(
			$this->config->buildCredentials(),
			$this->httpClient,
			$this->tokenCache,
			$redactor,
			$this->config->get('login_base_url'),
		);
		return new GraphTransport(
			$tokens,
			$this->httpClient,
			$redactor,
			$this->config->get('sender_mailbox'),
			$this->config->getBool('save_to_sent'),
			$this->config->get('graph_base_url'),
			$this->logger,
		);
	}

	/**
	 * Rebuilt per use so shape-matching still works while credentials are
	 * half-configured.
	 */
	private function redactor(): Redactor {
		return new Redactor($this->config->buildCredentials()->getSecretLiterals());
	}

	/**
	 * The single final redaction pass over anything that leaves as an
	 * exception: every string a failure carries goes through the redactor,
	 * whatever the failure was.
	 */
	private function redactThrowable(\Throwable $e): GraphException {
		$redactor = $this->redactor();
		if ($e instanceof GraphException) {
			return $e->redactedWith($redactor);
		}
		return new GraphException($redactor->redact($e->getMessage()), 0, '');
	}

	/**
	 * Redact an arbitrary string with the current credential literals — the
	 * OCS endpoint and occ commands run their output through this.
	 */
	public function redact(string $text): string {
		return $this->redactor()->redact($text);
	}
}
