<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Support;

use OC\Mail\Message as NextcloudMessage;
use OCA\Missivus\Adapter\HttpClient;
use OCA\Missivus\Adapter\Logger;
use OCA\Missivus\Adapter\TokenCache;
use OCA\Missivus\Mail\GraphMailer;
use OCA\Missivus\Mail\MessageConverter;
use OCA\Missivus\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IEmailValidator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mime\Email;

abstract class MissivusTestCase extends TestCase {
	/** @var array<string, string> stored appconfig values */
	protected array $appConfigValues = [];
	/** @var array<string, array{lazy: bool, sensitive: bool}> flags of the last write per key */
	protected array $appConfigFlags = [];
	/** @var array<string, mixed> config.php system values */
	protected array $systemValues = [];
	/** @var list<array{method: string, url: string, options: array}> */
	protected array $httpRequests = [];
	/** @var list<array{0: int, 1: string}> scripted status/body pairs, consumed in order */
	protected array $httpQueue = [];
	/** @var array<string, string> the distributed cache behind the token cache */
	protected array $cacheValues = [];
	/** @var list<array{key: string, ttl: int}> */
	protected array $cacheWrites = [];
	/** @var list<Event> */
	protected array $dispatchedEvents = [];
	/** @var list<array{level: string, message: string}> */
	protected array $logLines = [];
	/** @var list<IMessage> messages the inner (core) mailer was asked to send */
	protected array $innerSends = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appConfigValues = [];
		$this->appConfigFlags = [];
		$this->systemValues = [];
		$this->httpRequests = [];
		$this->httpQueue = [];
		$this->cacheValues = [];
		$this->cacheWrites = [];
		$this->dispatchedEvents = [];
		$this->logLines = [];
		$this->innerSends = [];
	}

	/**
	 * A working secret-auth configuration; override pieces per test. Note the
	 * master switch is stored as graph_enabled — "enabled" is core's own
	 * app-enablement flag (see ConfigService::STORAGE_KEYS). Tests that switch
	 * Missivus off pass ['graph_enabled' => '0'].
	 */
	protected function configure(array $overrides = []): void {
		$this->appConfigValues = array_merge([
			'graph_enabled' => '1',
			'tenant_id' => 'contoso.onmicrosoft.com',
			'client_id' => '11111111-2222-3333-4444-555555555555',
			'auth_method' => 'secret',
			'client_secret' => 'hunter2-correct-horse-battery',
			'sender_mailbox' => 'noreply@example.com',
		], $overrides);
	}

	protected function appConfig(): IAppConfig {
		$stub = $this->createStub(IAppConfig::class);
		$stub->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string
				=> $this->appConfigValues[$key] ?? $default
		);
		$stub->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->appConfigValues[$key] = $value;
				$this->appConfigFlags[$key] = ['lazy' => $lazy, 'sensitive' => $sensitive];
				return true;
			}
		);
		return $stub;
	}

	protected function systemConfig(): IConfig {
		$stub = $this->createStub(IConfig::class);
		$stub->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->systemValues[$key] ?? $default
		);
		return $stub;
	}

	protected function l10n(): IL10N {
		$stub = $this->createStub(IL10N::class);
		$stub->method('t')->willReturnCallback(
			function (string $text, $parameters = []) {
				$parameters = is_array($parameters) ? $parameters : [$parameters];
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		);
		return $stub;
	}

	protected function emailValidator(): IEmailValidator {
		$stub = $this->createStub(IEmailValidator::class);
		$stub->method('isValid')->willReturnCallback(
			static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
		);
		return $stub;
	}

	protected function configService(): ConfigService {
		return new ConfigService($this->systemConfig(), $this->appConfig(), $this->emailValidator(), $this->l10n());
	}

	protected function queueResponse(int $status, string $body): void {
		$this->httpQueue[] = [$status, $body];
	}

	protected function queueToken(string $token = 'TOKEN-VALUE-FOR-TESTS-ONLY', int $expiresIn = 3600): void {
		$this->queueResponse(200, json_encode(['access_token' => $token, 'expires_in' => $expiresIn]));
	}

	protected function clientService(): IClientService {
		$respond = function (string $method, string $url, array $options = []): IResponse {
			$this->httpRequests[] = ['method' => $method, 'url' => $url, 'options' => $options];
			[$status, $body] = $this->httpQueue === []
				? [202, '']
				: array_shift($this->httpQueue);
			$response = $this->createStub(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);
			$response->method('getHeaders')->willReturn([]);
			return $response;
		};
		$client = $this->createStub(IClient::class);
		$client->method('post')->willReturnCallback(fn (string $url, array $options = []) => $respond('post', $url, $options));
		$client->method('put')->willReturnCallback(fn (string $url, array $options = []) => $respond('put', $url, $options));
		$service = $this->createStub(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}

	protected function cacheFactory(): ICacheFactory {
		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cacheValues[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value, int $ttl = 0): bool {
			$this->cacheValues[$key] = $value;
			$this->cacheWrites[] = ['key' => $key, 'ttl' => $ttl];
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->cacheValues[$key]);
			return true;
		});
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}

	protected function dispatcher(): IEventDispatcher {
		$stub = $this->createStub(IEventDispatcher::class);
		$stub->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->dispatchedEvents[] = $event;
		});
		return $stub;
	}

	protected function psrLogger(): AbstractLogger {
		$tests = $this;
		return new class($tests) extends AbstractLogger {
			public function __construct(private MissivusTestCase $tests) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->tests->recordLog((string)$level, (string)$message);
			}
		};
	}

	public function recordLog(string $level, string $message): void {
		$this->logLines[] = ['level' => $level, 'message' => $message];
	}

	protected function innerMailer(): IMailer {
		$tests = $this;
		return new class($tests) implements IMailer {
			public function __construct(private MissivusTestCase $tests) {
			}

			public function createMessage(): IMessage {
				return new NextcloudMessage(new Email(), false);
			}

			public function createAttachment($data = null, $filename = null, $contentType = null): IAttachment {
				throw new \LogicException('not used in tests');
			}

			public function createAttachmentFromPath(string $path, $contentType = null): IAttachment {
				throw new \LogicException('not used in tests');
			}

			public function createEMailTemplate(string $emailId, array $data = []): IEMailTemplate {
				throw new \LogicException('not used in tests');
			}

			public function send(IMessage $message): array {
				$this->tests->recordInnerSend($message);
				return [];
			}

			public function validateMailAddress(string $email): bool {
				return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
			}
		};
	}

	public function recordInnerSend(IMessage $message): void {
		$this->innerSends[] = $message;
	}

	protected function makeMailer(): GraphMailer {
		return new GraphMailer(
			$this->innerMailer(),
			$this->configService(),
			new MessageConverter(),
			new HttpClient($this->clientService()),
			new TokenCache($this->cacheFactory()),
			new Logger($this->psrLogger()),
			$this->dispatcher(),
		);
	}

	protected function makeMessage(): NextcloudMessage {
		return new NextcloudMessage(new Email(), false);
	}

	/** @return list<string> messages logged at the level */
	protected function logged(string $level): array {
		return array_values(array_map(
			static fn (array $line): string => $line['message'],
			array_filter($this->logLines, static fn (array $line): bool => $line['level'] === $level)
		));
	}

	protected function assertNoSecretIn(string $text, string $context = ''): void {
		foreach (['hunter2-correct-horse-battery', 'TOKEN-VALUE', 'SECRET-VALUE', 'FRAGMENT-VALUE', 'admin:'] as $secret) {
			$this->assertStringNotContainsString($secret, $text, "secret '$secret' leaked" . ($context !== '' ? " into $context" : ''));
		}
	}
}
