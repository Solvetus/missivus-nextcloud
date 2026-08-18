<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Unit;

use OC\Mail\Message as NextcloudMessage;
use OCA\Missivus\Mail\MessageConverter;
use OCA\Missivus\Tests\Support\MissivusTestCase;
use Symfony\Component\Mime\Email;

class MessageConverterTest extends MissivusTestCase {
	private const SENDER = 'noreply@example.com';

	private function convert(NextcloudMessage $message): array {
		$converter = new MessageConverter();
		$graphMessage = $converter->convert($message, self::SENDER);
		return [$graphMessage->toGraphMessage(), $converter->getWarnings(), $graphMessage];
	}

	public function testEveryRecipientKindIsMappedWithAndWithoutNames(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org' => 'To Person', 'plain@example.org']);
		$message->setCc(['cc@example.org' => 'Cc Person']);
		$message->setBcc(['bcc@example.org']);
		[$graph] = $this->convert($message);

		$this->assertSame('to@example.org', $graph['toRecipients'][0]['emailAddress']['address']);
		$this->assertSame('To Person', $graph['toRecipients'][0]['emailAddress']['name']);
		$this->assertSame('plain@example.org', $graph['toRecipients'][1]['emailAddress']['address']);
		$this->assertArrayNotHasKey('name', $graph['toRecipients'][1]['emailAddress']);
		$this->assertSame('cc@example.org', $graph['ccRecipients'][0]['emailAddress']['address']);
		$this->assertSame('bcc@example.org', $graph['bccRecipients'][0]['emailAddress']['address']);
	}

	public function testHtmlWinsAsTheBodyAndPlainRidesAsTheAlternative(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setPlainBody('plain text');
		$message->setHtmlBody('<p>rich</p>');
		[$graph] = $this->convert($message);

		$this->assertSame('HTML', $graph['body']['contentType']);
		$this->assertSame('<p>rich</p>', $graph['body']['content']);
	}

	public function testPlainOnlyStaysText(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setPlainBody('plain text');
		[$graph] = $this->convert($message);

		$this->assertSame('Text', $graph['body']['contentType']);
		$this->assertSame('plain text', $graph['body']['content']);
	}

	public function testTheSubjectSurvives(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setSubject('Résumé — updated');
		[$graph] = $this->convert($message);

		$this->assertSame('Résumé — updated', $graph['subject']);
	}

	public function testARegularAttachmentRoundTrips(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->getSymfonyEmail()->attach("PDF-BYTES\x00\x01", 'report.pdf', 'application/pdf');
		[, , $graphMessage] = $this->convert($message);

		$attachment = $graphMessage->getAttachments()[0]->toGraphFileAttachment();
		$this->assertSame('#microsoft.graph.fileAttachment', $attachment['@odata.type']);
		$this->assertSame('report.pdf', $attachment['name']);
		$this->assertSame('application/pdf', $attachment['contentType']);
		$this->assertSame("PDF-BYTES\x00\x01", base64_decode($attachment['contentBytes']));
		$this->assertArrayNotHasKey('isInline', $attachment);
	}

	public function testAnInlineAttachmentCarriesItsContentId(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setHtmlBody('<img src="cid:logo.png">');
		$message->attachInline('PNG-BYTES', 'logo.png', 'image/png');
		[, , $graphMessage] = $this->convert($message);

		$attachment = $graphMessage->getAttachments()[0]->toGraphFileAttachment();
		$this->assertTrue($attachment['isInline']);
		$this->assertSame('logo.png', $attachment['contentId']);
	}

	public function testForcedFromKeepsTheRequestedDisplayName(): void {
		$message = $this->makeMessage();
		$message->setFrom(['ceo@example.com' => 'The CEO']);
		$message->setTo(['to@example.org']);
		[$graph, $warnings] = $this->convert($message);

		$this->assertSame(self::SENDER, $graph['from']['emailAddress']['address']);
		$this->assertSame('The CEO', $graph['from']['emailAddress']['name']);
		$this->assertCount(1, $warnings);
	}

	public function testAnEmptyFromProducesNeitherWarningNorReplyTo(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		[$graph, $warnings] = $this->convert($message);

		$this->assertSame(self::SENDER, $graph['from']['emailAddress']['address']);
		$this->assertSame([], $warnings);
		$this->assertArrayNotHasKey('replyTo', $graph);
	}

	public function testAnAutoSubmittedHeaderIsDroppedWithAWarningNamingIt(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setAutoSubmitted('auto-generated');
		[, $warnings] = $this->convert($message);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('Auto-Submitted', $warnings[0]);
	}

	public function testAListUnsubscribeHeaderIsDroppedWithAWarningNamingIt(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->getSymfonyEmail()->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:leave@example.com>');
		[, $warnings] = $this->convert($message);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('List-Unsubscribe', $warnings[0]);
	}

	public function testABodylessMessageStillConverts(): void {
		$message = $this->makeMessage();
		$message->setTo(['to@example.org']);
		$message->setSubject('only a subject');
		[$graph] = $this->convert($message);

		$this->assertSame('only a subject', $graph['subject']);
	}
}
