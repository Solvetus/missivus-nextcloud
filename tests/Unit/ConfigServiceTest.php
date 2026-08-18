<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Unit;

use OCA\Missivus\Tests\Support\MissivusTestCase;

class ConfigServiceTest extends MissivusTestCase {
	public function testTheDefaultsAreOffAndSafe(): void {
		$config = $this->configService();
		$this->assertFalse($config->isEnabled());
		$this->assertFalse($config->getBool('fallback_to_system'));
		$this->assertFalse($config->getBool('save_to_sent'));
		$this->assertSame('secret', $config->get('auth_method'));
		$this->assertSame('https://graph.microsoft.com', $config->get('graph_base_url'));
		$this->assertSame('https://login.microsoftonline.com', $config->get('login_base_url'));
	}

	public function testAConfigPhpValueWinsOverTheStoredValue(): void {
		$this->appConfigValues['tenant_id'] = 'stored.example.com';
		$this->systemValues['missivus'] = ['tenant_id' => 'file.example.com'];
		$config = $this->configService();
		$this->assertSame('file.example.com', $config->get('tenant_id'));
		$this->assertTrue($config->hasOverride('tenant_id'));
		$this->assertFalse($config->hasOverride('client_id'));
	}

	public function testASaveForAConfigPhpManagedKeyIsDiscarded(): void {
		$this->systemValues['missivus'] = ['client_secret' => 'file-managed-secret-value'];
		$config = $this->configService();
		$config->set('client_secret', 'attempted-ui-secret-value');
		$this->assertArrayNotHasKey('client_secret', $this->appConfigValues, 'a file-managed secret must never reach the database');
	}

	public function testAnEmptySecretSubmissionKeepsTheStoredSecret(): void {
		$this->appConfigValues['client_secret'] = 'stored-secret-value-here';
		$config = $this->configService();
		$config->set('client_secret', '');
		$this->assertSame('stored-secret-value-here', $this->appConfigValues['client_secret']);
	}

	public function testSecretsAreStoredSensitiveAndLazy(): void {
		$config = $this->configService();
		$config->set('client_secret', 'a-perfectly-fine-secret');
		$this->assertTrue($this->appConfigFlags['client_secret']['sensitive']);
		$this->assertTrue($this->appConfigFlags['client_secret']['lazy']);
		$config->set('tenant_id', 'contoso.onmicrosoft.com');
		$this->assertFalse($this->appConfigFlags['tenant_id']['sensitive']);
	}

	public function testAWhitespaceSecretIsRejectedWithoutBeingEchoed(): void {
		$config = $this->configService();
		try {
			$config->set('client_secret', "oops pasted with spaces");
			$this->fail('expected rejection');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringNotContainsString('oops', $e->getMessage());
		}
		$this->assertArrayNotHasKey('client_secret', $this->appConfigValues);
	}

	public function testAnOverlongSecretIsRejected(): void {
		$config = $this->configService();
		$this->expectException(\InvalidArgumentException::class);
		$config->set('client_secret', str_repeat('x', 1025));
	}

	public function testTenantAcceptsGuidAndDomainAndRejectsJunk(): void {
		$config = $this->configService();
		$config->set('tenant_id', '11111111-2222-3333-4444-555555555555');
		$config->set('tenant_id', 'contoso.onmicrosoft.com');
		$this->expectException(\InvalidArgumentException::class);
		$config->set('tenant_id', 'not a tenant!');
	}

	public function testClientIdMustBeAGuid(): void {
		$config = $this->configService();
		$config->set('client_id', '11111111-2222-3333-4444-555555555555');
		$this->expectException(\InvalidArgumentException::class);
		$config->set('client_id', 'contoso.onmicrosoft.com');
	}

	public function testTheSenderMailboxMustBeAnEmailAddress(): void {
		$config = $this->configService();
		$config->set('sender_mailbox', 'noreply@example.com');
		$this->expectException(\InvalidArgumentException::class);
		$config->set('sender_mailbox', 'not-an-address');
	}

	public function testTheCertificatePathMustBeAbsolute(): void {
		$config = $this->configService();
		$config->set('certificate_path', '/etc/ssl/missivus.pem');
		$this->expectException(\InvalidArgumentException::class);
		$config->set('certificate_path', 'relative/missivus.pem');
	}

	public function testTheAuthMethodIsWhitelisted(): void {
		$config = $this->configService();
		$config->set('auth_method', 'certificate');
		$this->expectException(\InvalidArgumentException::class);
		$config->set('auth_method', 'magic');
	}

	public function testBooleanShapesFromConfigPhpAreNormalised(): void {
		$this->systemValues['missivus'] = ['enabled' => true, 'fallback_to_system' => 'yes'];
		$config = $this->configService();
		$this->assertTrue($config->isEnabled());
		$this->assertTrue($config->getBool('fallback_to_system'));
	}

	public function testTheConfigurationProblemNamesTheFirstMissingPart(): void {
		$config = $this->configService();
		$this->assertStringContainsString('tenant', (string)$config->getConfigurationProblem());

		$this->configure(['client_secret' => '']);
		$this->assertStringContainsString('client secret', (string)$this->configService()->getConfigurationProblem());

		$this->configure();
		$this->assertNull($this->configService()->getConfigurationProblem());
	}

	public function testTheCertificateMethodNeedsAPathNotASecret(): void {
		$this->configure([
			'auth_method' => 'certificate',
			'client_secret' => '',
		]);
		$this->assertStringContainsString('certificate path', (string)$this->configService()->getConfigurationProblem());

		$this->appConfigValues['certificate_path'] = '/etc/ssl/missivus.pem';
		$this->assertNull($this->configService()->getConfigurationProblem());
	}

	public function testCertificateCredentialsAreAssembled(): void {
		$this->configure([
			'auth_method' => 'certificate',
			'certificate_path' => '/etc/ssl/missivus.pem',
			'certificate_passphrase' => 'a-passphrase-value',
			'client_secret' => '',
		]);
		$credentials = $this->configService()->buildCredentials();
		$this->assertTrue($credentials->usesCertificate());
		$this->assertSame('/etc/ssl/missivus.pem', $credentials->getCertificatePath());
	}

	public function testFailureRecordingRoundTrips(): void {
		$config = $this->configService();
		$this->assertNull($config->getLastFailure());
		$config->recordFailure('it broke, redacted');
		$failure = $config->getLastFailure();
		$this->assertSame('it broke, redacted', $failure['message']);
		$this->assertGreaterThan(0, $failure['at']);
		$config->clearFailure();
		$this->assertNull($config->getLastFailure());
	}

	public function testUnknownKeysAreRefusedOnReadAndWrite(): void {
		$config = $this->configService();
		try {
			$config->get('nope');
			$this->fail('expected rejection');
		} catch (\InvalidArgumentException) {
		}
		$this->expectException(\InvalidArgumentException::class);
		$config->set('graph_base_url', 'https://example.com');
	}
	public function testTheMasterSwitchIsStoredApartFromCoresAppEnablementFlag(): void {
		// Core writes appconfig missivus/enabled = "yes" when the *app* is
		// enabled; the *switch* must neither read nor collide with it.
		$this->appConfigValues['enabled'] = 'yes';
		$config = $this->configService();
		$this->assertFalse($config->isEnabled(), "core's app flag must not flip the switch");
		$config->set('enabled', '1');
		$this->assertSame('1', $this->appConfigValues['graph_enabled']);
		$this->assertSame('yes', $this->appConfigValues['enabled'], "core's flag stays untouched");
		$this->assertTrue($this->configService()->isEnabled());
	}
}
