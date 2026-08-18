<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Unit;

use OCA\Missivus\Tests\Support\MissivusTestCase;
use OCP\Mail\Events\BeforeMessageSent;
use Solvetus\Missivus\Exception\GraphException;

class MailerTest extends MissivusTestCase {
	public function testWhenSwitchedOffNextcloudsOwnMailerIsUsedWithoutTouchingGraph(): void {
		$this->configure(['enabled' => '0']);
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		$this->assertSame([], $mailer->send($message));
		$this->assertCount(1, $this->innerSends);
		$this->assertCount(0, $this->httpRequests, 'being switched off must not touch Graph');
		$this->assertSame([], $this->logged('error'));
	}

	public function testEnabledSendRejectsForeignMessageObjectsLikeCoreDoes(): void {
		$this->configure();
		$mailer = $this->makeMailer();
		$this->expectException(\InvalidArgumentException::class);
		$mailer->send($this->createStub(\OCP\Mail\IMessage::class));
	}

	public function testTheHappyPathSendsThroughGraphAndReturnsNoFailedRecipients(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org' => 'Some One']);
		$message->setSubject('Hello');
		$message->setPlainBody('Body');

		$this->assertSame([], $mailer->send($message));
		$this->assertCount(0, $this->innerSends);
		$this->assertCount(2, $this->httpRequests);
		$this->assertSame(
			'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token',
			$this->httpRequests[0]['url']
		);
		$this->assertSame(
			'https://graph.microsoft.com/v1.0/users/noreply%40example.com/sendMail',
			$this->httpRequests[1]['url']
		);
		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertSame('Hello', $payload['message']['subject']);
		$this->assertSame('noreply@example.com', $payload['message']['from']['emailAddress']['address']);
		$this->assertFalse($payload['saveToSentItems']);
	}

	public function testBeforeMessageSentIsDispatchedOnTheGraphPath(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$this->assertCount(1, $this->dispatchedEvents);
		$this->assertInstanceOf(BeforeMessageSent::class, $this->dispatchedEvents[0]);
		$this->assertSame($message, $this->dispatchedEvents[0]->getMessage());
	}

	public function testSaveToSentItemsIsHonoured(): void {
		$this->configure(['save_to_sent' => '1']);
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertTrue($payload['saveToSentItems']);
	}

	public function testADifferingFromIsForcedWithAWarningAndKeptAsReplyTo(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setFrom(['ceo@example.com' => 'The CEO']);
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertSame('noreply@example.com', $payload['message']['from']['emailAddress']['address']);
		$this->assertSame('The CEO', $payload['message']['from']['emailAddress']['name']);
		$this->assertSame('ceo@example.com', $payload['message']['replyTo'][0]['emailAddress']['address']);
		$warnings = $this->logged('warning');
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('forcing From', $warnings[0]);
		$this->assertStringContainsString('ceo@example.com', $warnings[0]);
		$this->assertStringContainsString('mail_from_address', $warnings[0]);
	}

	public function testAMatchingFromProducesNoWarning(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setFrom(['NoReply@Example.com' => 'Nextcloud']);
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$this->assertSame([], $this->logged('warning'));
		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertArrayNotHasKey('replyTo', $payload['message']);
	}

	public function testAnExplicitReplyToIsNeverClobberedByTheForcedFrom(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setFrom(['ceo@example.com']);
		$message->setReplyTo(['support@example.com' => 'Support']);
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertCount(1, $payload['message']['replyTo']);
		$this->assertSame('support@example.com', $payload['message']['replyTo'][0]['emailAddress']['address']);
	}

	public function testAnEmptyFromConvertsWarningFree(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);
		$mailer->send($message);

		$this->assertSame([], $this->logged('warning'));
		$payload = json_decode($this->httpRequests[1]['options']['body'], true);
		$this->assertArrayNotHasKey('replyTo', $payload['message']);
	}

	public function testWithTheFallbackOffAFailureIsLoggedRecordedAndRethrown(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(503, '{"error":{"code":"ServiceUnavailable"}}');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->send($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertStringContainsString('ServiceUnavailable', $e->getMessage() . $e->getResponseBody());
		}
		$this->assertCount(0, $this->innerSends, 'fallback off must never reach the inner mailer');
		$errors = $this->logged('error');
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('ServiceUnavailable', $errors[0]);
		$this->assertNotSame('', $this->appConfigValues['last_error'] ?? '', 'the failure must surface on the settings page');
	}

	public function testWithTheFallbackOnTheInnerMailerSendsAndTheFailureIsStillLogged(): void {
		$this->configure(['fallback_to_system' => '1']);
		$this->queueToken();
		$this->queueResponse(503, '{"error":{"code":"ServiceUnavailable"}}');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		$this->assertSame([], $mailer->send($message));
		$this->assertCount(1, $this->innerSends);
		$this->assertStringContainsString('ServiceUnavailable', $this->logged('error')[0]);
		$this->assertStringContainsString('falling back', implode(' ', $this->logged('warning')));
	}

	public function testEnabledButUnconfiguredFailsLoudlyNamingTheMissingPart(): void {
		$this->configure(['client_secret' => '']);
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->send($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertStringContainsString('client secret', $e->getMessage());
		}
		$this->assertCount(0, $this->httpRequests, 'a configuration problem must not produce traffic');
		$this->assertCount(1, $this->logged('error'));
	}

	public function testSendWithoutFallbackIgnoresTheEnabledSwitchAndTheFallback(): void {
		$this->configure(['enabled' => '0', 'fallback_to_system' => '1']);
		$this->queueToken();
		$this->queueResponse(400, '{"error":{"code":"ErrorInvalidRecipients"}}');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->sendWithoutFallback($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertStringContainsString('ErrorInvalidRecipients', $e->getMessage() . $e->getResponseBody());
		}
		$this->assertCount(0, $this->innerSends, 'the test path must never quietly succeed over the fallback');
		$this->assertCount(2, $this->httpRequests, 'the test path sends even while the switch is off');
	}

	public function testAnEntraErrorEchoingTheSecretIsRedactedEverywhere(): void {
		$this->configure();
		$this->queueResponse(401, '{"error":"invalid_client","error_description":"Bad secret hunter2-correct-horse-battery supplied"}');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->send($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertNoSecretIn($e->getMessage(), 'the exception message');
			$this->assertNoSecretIn($e->getResponseBody(), 'the exception body');
			$this->assertStringContainsString('invalid_client', $e->getMessage() . $e->getResponseBody());
		}
		foreach ($this->logLines as $line) {
			$this->assertNoSecretIn($line['message'], 'the log');
		}
		$this->assertNoSecretIn($this->appConfigValues['last_error'] ?? '', 'the settings-page surface');
	}

	public function testAPoisonedGraphBaseUrlNeverLeaksItsCredentials(): void {
		$this->configure();
		$this->systemValues['missivus'] = [
			'graph_base_url' => 'https://admin:hunter2-correct-horse-battery@graph.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE',
		];
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->send($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertNoSecretIn($e->getMessage(), 'the exception');
			$this->assertStringContainsString('graph_base_url', $e->getMessage());
		}
		$this->assertCount(0, $this->httpRequests, 'nothing may leave the process towards a poisoned endpoint');
		foreach ($this->logLines as $line) {
			$this->assertNoSecretIn($line['message'], 'the log');
		}
		$this->assertNoSecretIn($this->appConfigValues['last_error'] ?? '', 'the settings-page surface');
	}

	public function testAPoisonedLoginBaseUrlNeverLeaksItsCredentials(): void {
		$this->configure();
		$this->systemValues['missivus'] = [
			'login_base_url' => 'https://login.evil.test/?client_secret=SECRET-VALUE',
		];
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);

		try {
			$mailer->send($message);
			$this->fail('expected a GraphException');
		} catch (GraphException $e) {
			$this->assertNoSecretIn($e->getMessage(), 'the exception');
			$this->assertStringContainsString('login_base_url', $e->getMessage());
		}
		$this->assertCount(0, $this->httpRequests);
		foreach ($this->logLines as $line) {
			$this->assertNoSecretIn($line['message'], 'the log');
		}
	}

	public function testTheTokenIsCachedAndReused(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(202, '');
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$first = $this->makeMessage();
		$first->setTo(['someone@example.org']);
		$second = $this->makeMessage();
		$second->setTo(['other@example.org']);

		$mailer->send($first);
		$mailer->send($second);
		$tokenRequests = array_filter(
			$this->httpRequests,
			static fn (array $r): bool => str_contains($r['url'], 'oauth2/v2.0/token')
		);
		$this->assertCount(1, $tokenRequests, 'the second send must reuse the cached token');
		$this->assertGreaterThan(0, count($this->cacheWrites));
		$this->assertSame(3300, $this->cacheWrites[0]['ttl'], 'expires_in minus the safety margin');
	}

	public function testALargeAttachmentTakesTheDraftUploadSessionPath(): void {
		$this->configure();
		$this->queueToken();
		$this->queueResponse(201, '{"id":"DRAFT-1"}');
		$this->queueResponse(201, '{"uploadUrl":"https://outlook.office.com/upload/SESSION-CAPABILITY-URL","expirationDateTime":"2026-01-01T00:00:00Z"}');
		$this->queueResponse(200, '{}');
		$this->queueResponse(201, '{}');
		$this->queueResponse(202, '');
		$mailer = $this->makeMailer();
		$message = $this->makeMessage();
		$message->setTo(['someone@example.org']);
		$message->setSubject('Big');
		$message->getSymfonyEmail()->attach(str_repeat('A', 4 * 1024 * 1024), 'big.bin', 'application/octet-stream');

		$this->assertSame([], $mailer->send($message));
		$urls = array_column($this->httpRequests, 'url');
		$this->assertStringContainsString('/messages', $urls[1], 'a draft is created');
		$this->assertStringContainsString('createUploadSession', $urls[2]);
		$this->assertStringContainsString('outlook.office.com/upload', $urls[3], 'chunks go to the session URL');
		$this->assertStringContainsString('/send', end($urls));
	}

	public function testEverythingElseDelegatesToTheInnerMailer(): void {
		$this->configure();
		$mailer = $this->makeMailer();
		$this->assertInstanceOf(\OC\Mail\Message::class, $mailer->createMessage());
		$this->assertTrue($mailer->validateMailAddress('valid@example.com'));
		$this->assertFalse($mailer->validateMailAddress('not-an-address'));
	}
}
