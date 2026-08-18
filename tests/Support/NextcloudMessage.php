<?php

declare(strict_types=1);

/**
 * A faithful test double of \OC\Mail\Message (private core API, not part of
 * the nextcloud/ocp dev package): the same wrapper around a real Symfony
 * Email, with the same getter shapes the converter and decorator rely on.
 * Mirrored from lib/private/Mail/Message.php on stable33/stable34.
 *
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OC\Mail;

use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMessage;
use Symfony\Component\Mime\Email;

class Message implements IMessage {
	private array $to = [];
	private array $from = [];
	private array $replyTo = [];
	private array $cc = [];
	private array $bcc = [];
	private string $subject = '';
	private string $plainBody = '';

	public function __construct(
		private Email $symfonyEmail,
		private bool $plainTextOnly,
	) {
	}

	public function attach(IAttachment $attachment): IMessage {
		throw new \LogicException('Tests attach parts through getSymfonyEmail(), as core ultimately does.');
	}

	public function attachInline(string $body, string $name, ?string $contentType = null): IMessage {
		$this->symfonyEmail->embed($body, $name, $contentType);
		return $this;
	}

	public function setFrom(array $addresses): IMessage {
		$this->from = $addresses;
		return $this;
	}

	public function getFrom(): array {
		return $this->from;
	}

	public function setReplyTo(array $addresses): IMessage {
		$this->replyTo = $addresses;
		return $this;
	}

	public function getReplyTo(): array {
		return $this->replyTo;
	}

	public function setTo(array $recipients): IMessage {
		$this->to = $recipients;
		return $this;
	}

	public function getTo(): array {
		return $this->to;
	}

	public function setCc(array $recipients): IMessage {
		$this->cc = $recipients;
		return $this;
	}

	public function getCc(): array {
		return $this->cc;
	}

	public function setBcc(array $recipients): IMessage {
		$this->bcc = $recipients;
		return $this;
	}

	public function getBcc(): array {
		return $this->bcc;
	}

	public function setSubject(string $subject): IMessage {
		$this->subject = $subject;
		return $this;
	}

	public function getSubject(): string {
		return $this->subject;
	}

	public function setPlainBody(string $body): IMessage {
		$this->plainBody = $body;
		$this->symfonyEmail->text($body);
		return $this;
	}

	public function getPlainBody(): string {
		return $this->plainBody;
	}

	public function setHtmlBody(string $body): IMessage {
		if (!$this->plainTextOnly) {
			$this->symfonyEmail->html($body);
		}
		return $this;
	}

	public function useTemplate(IEMailTemplate $emailTemplate): IMessage {
		throw new \LogicException('Templates are core behaviour, not under test here.');
	}

	public function setAutoSubmitted(string $value): IMessage {
		$headers = $this->symfonyEmail->getHeaders();
		if ($headers->has('Auto-Submitted')) {
			$headers->remove('Auto-Submitted');
		}
		$headers->addTextHeader('Auto-Submitted', $value);
		return $this;
	}

	public function getAutoSubmitted(): string {
		$headers = $this->symfonyEmail->getHeaders();
		return $headers->has('Auto-Submitted')
			? $headers->get('Auto-Submitted')->getBodyAsString() : 'no';
	}

	public function getSymfonyEmail(): Email {
		return $this->symfonyEmail;
	}
}
