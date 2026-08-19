<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Unit;

use OCA\Missivus\AppInfo\Application;
use OCA\Missivus\Controller\ApiController;
use OCA\Missivus\Mail\MailerFactory;
use OCA\Missivus\Tests\Support\MissivusTestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;

class ApiControllerTest extends MissivusTestCase {
	private function makeController(): ApiController {
		return new ApiController(
			Application::APP_ID,
			$this->createStub(IRequest::class),
			$this->configService(),
			$this->makeMailer(),
			$this->createStub(MailerFactory::class),
			$this->emailValidator(),
			$this->l10n(),
		);
	}

	public function testNeitherEndpointOptsOutOfAdminOrCsrfChecks(): void {
		foreach (['saveSettings', 'testEmail'] as $method) {
			$reflection = new \ReflectionMethod(ApiController::class, $method);
			$attributes = array_map(
				static fn (\ReflectionAttribute $a): string => $a->getName(),
				$reflection->getAttributes()
			);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attributes, "$method must stay admin-only");
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attributes, "$method must keep the CSRF check");
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attributes, "$method must never be public");
			$this->assertContains('OCP\AppFramework\Http\Attribute\ApiRoute', $attributes);
		}
	}

	public function testSavingAnUnknownKeyIsRefused(): void {
		$response = $this->makeController()->saveSettings(['no_such_key' => 'x']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testAValidationErrorComesBackAsA400WithTheMessage(): void {
		// A bad client id is refused and nothing is stored.
		$response = $this->makeController()->saveSettings(['client_id' => 'not-a-guid']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('GUID', $response->getData()['message']);
		$this->assertArrayNotHasKey('client_id', $this->appConfigValues);
	}

	public function testAValidSaveStoresAndReportsTheRemainingProblem(): void {
		$response = $this->makeController()->saveSettings([
			'tenant_id' => 'contoso.onmicrosoft.com',
			'client_id' => '11111111-2222-3333-4444-555555555555',
		]);
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('contoso.onmicrosoft.com', $this->appConfigValues['tenant_id']);
		$this->assertStringContainsString('sender mailbox', (string)$response->getData()['configProblem']);
	}

	public function testAnInvalidTestRecipientIsRefusedBeforeAnythingIsSent(): void {
		$this->configure();
		$response = $this->makeController()->testEmail('not-an-address');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertCount(0, $this->httpRequests);
	}

	public function testAFailedTestReturnsTheRedactedMicrosoftError(): void {
		$this->configure();
		$this->queueResponse(401, '{"error":"invalid_client","error_description":"secret hunter2-correct-horse-battery rejected"}');
		$response = $this->makeController()->testEmail('admin@example.org');
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('invalid_client', $data['message']);
		$this->assertNoSecretIn($data['message'], 'the OCS response');
	}

	public function testASuccessfulTestReportsItAndClearsTheRecordedFailure(): void {
		$this->configure();
		$this->appConfigValues['last_error'] = 'old failure';
		$this->queueToken();
		$this->queueResponse(202, '');
		$response = $this->makeController()->testEmail('admin@example.org');
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertStringContainsString('admin@example.org', $data['message']);
		$this->assertSame('', $this->appConfigValues['last_error']);
	}

	public function testTheTestPathIgnoresTheFallbackEvenWhenItIsOn(): void {
		$this->configure(['fallback_to_system' => '1']);
		$this->queueToken();
		$this->queueResponse(500, '{"error":{"code":"InternalServerError"}}');
		$response = $this->makeController()->testEmail('admin@example.org');
		$this->assertFalse($response->getData()['success']);
		$this->assertCount(0, $this->innerSends, 'a test that quietly succeeded over the fallback proves nothing');
	}
}
