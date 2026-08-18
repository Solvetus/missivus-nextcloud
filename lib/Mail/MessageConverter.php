<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Mail;

use OC\Mail\Message as NextcloudMessage;
use Solvetus\Missivus\Attachment;
use Solvetus\Missivus\Message as GraphMessage;

/**
 * Converts Nextcloud's message (a wrapper around a Symfony Email) into the
 * transport's Graph message. Pure: collects warnings instead of logging, so
 * callers decide where they go after redaction.
 */
class MessageConverter {
	/** @var string[] */
	private array $warnings = [];

	/**
	 * @param NextcloudMessage $message the message as core built it, From already defaulted
	 * @param string $senderMailbox the configured shared mailbox; From is always forced to it
	 */
	public function convert(NextcloudMessage $message, string $senderMailbox): GraphMessage {
		$this->warnings = [];
		$graphMessage = new GraphMessage();

		[$requestedFrom, $fromName] = $this->firstAddress($message->getFrom());
		$graphMessage->setFrom($senderMailbox, $fromName);
		$fromWasForced = $requestedFrom !== ''
			&& strcasecmp($requestedFrom, $senderMailbox) !== 0;
		if ($fromWasForced) {
			$this->warnings[] = sprintf(
				'Missivus: forcing From to %s; the message asked for %s. Point mail_from_address/mail_domain at the shared mailbox to silence this.',
				$senderMailbox,
				$requestedFrom
			);
		}

		foreach ($this->addressPairs($message->getReplyTo()) as [$address, $name]) {
			$graphMessage->addReplyTo($address, $name);
		}
		if ($fromWasForced && !$graphMessage->hasReplyTo()) {
			// Replies still reach whoever the message claimed to come from.
			$graphMessage->addReplyTo($requestedFrom, $fromName);
		}

		foreach ($this->addressPairs($message->getTo()) as [$address, $name]) {
			$graphMessage->addTo($address, $name);
		}
		foreach ($this->addressPairs($message->getCc()) as [$address, $name]) {
			$graphMessage->addCc($address, $name);
		}
		foreach ($this->addressPairs($message->getBcc()) as [$address, $name]) {
			$graphMessage->addBcc($address, $name);
		}

		$graphMessage->setSubject($message->getSubject());

		$symfonyEmail = $message->getSymfonyEmail();
		$html = $symfonyEmail->getHtmlBody();
		if (is_resource($html)) {
			$html = stream_get_contents($html) ?: '';
		}
		if (is_string($html) && $html !== '') {
			$graphMessage->setHtmlBody($html);
		}
		$plain = $message->getPlainBody();
		if ($plain === '') {
			$text = $symfonyEmail->getTextBody();
			if (is_resource($text)) {
				$text = stream_get_contents($text) ?: '';
			}
			$plain = is_string($text) ? $text : '';
		}
		if ($plain !== '') {
			$graphMessage->setTextBody($plain);
		}

		foreach ($symfonyEmail->getAttachments() as $part) {
			$body = $part->getBody();
			$name = $part->getFilename() ?? $part->getName() ?? 'attachment';
			$contentId = '';
			if ($part->getDisposition() === 'inline') {
				// Symfony embeds parts under their name and the HTML references
				// cid:<name>; an explicitly set content id wins.
				$contentId = $part->hasContentId() ? $part->getContentId() : $name;
			}
			$graphMessage->addAttachment(new Attachment(
				$name,
				$body,
				$part->getContentType(),
				$contentId
			));
		}

		// Graph's JSON sendMail only accepts custom internetMessageHeaders whose
		// name starts with "x-", and the vendored transport (unchanged by rule)
		// carries no custom headers at all — so headers that cannot ride are
		// dropped with a warning naming them, exactly as the WordPress port does.
		$autoSubmitted = $message->getAutoSubmitted();
		if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
			$this->warnings[] = 'Missivus: the Auto-Submitted header does not survive the trip through Graph sendMail and was dropped.';
		}
		if ($symfonyEmail->getHeaders()->has('List-Unsubscribe')) {
			$this->warnings[] = 'Missivus: the List-Unsubscribe header does not survive the trip through Graph sendMail and was dropped.';
		}

		return $graphMessage;
	}

	/**
	 * Warnings produced by the last convert() call. Callers must redact before
	 * logging — the requested From address is operator data, not a secret, but
	 * the single-final-pass rule applies to every logged string.
	 *
	 * @return string[]
	 */
	public function getWarnings(): array {
		return $this->warnings;
	}

	/**
	 * Nextcloud address arrays come as ['mail@example.com' => 'Name'] or
	 * plain ['mail@example.com'] entries, mixed.
	 *
	 * @param array<int|string, string> $addresses
	 * @return list<array{0: string, 1: string}>
	 */
	private function addressPairs(array $addresses): array {
		$pairs = [];
		foreach ($addresses as $key => $value) {
			if (is_string($key)) {
				$pairs[] = [$key, (string)$value];
			} else {
				$pairs[] = [(string)$value, ''];
			}
		}
		return $pairs;
	}

	/** @return array{0: string, 1: string} address and display name, '' when absent */
	private function firstAddress(array $addresses): array {
		foreach ($this->addressPairs($addresses) as $pair) {
			return $pair;
		}
		return ['', ''];
	}
}
