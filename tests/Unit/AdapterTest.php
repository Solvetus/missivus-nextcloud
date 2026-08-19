<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Tests\Unit;

use OCA\Missivus\Adapter\HttpClient;
use OCA\Missivus\Adapter\Logger;
use OCA\Missivus\Adapter\TokenCache;
use OCA\Missivus\Tests\Support\MissivusTestCase;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

class AdapterTest extends MissivusTestCase {
	public function testAnErrorStatusIsANormalResponseNotAnException(): void {
		$this->queueResponse(503, 'busy');
		$http = new HttpClient($this->clientService());
		$response = $http->post('https://graph.microsoft.com/v1.0/x', '{}');
		$this->assertSame(503, $response->getStatus());
		$this->assertSame('busy', $response->getBody());
		$this->assertFalse($response->isSuccess());
		$this->assertFalse($this->httpRequests[0]['options']['http_errors']);
	}

	public function testTheRequestGoesOutVerbatim(): void {
		$this->queueResponse(200, 'ok');
		$http = new HttpClient($this->clientService());
		$http->put('https://example.com/chunk', 'BYTES', ['Content-Range' => 'bytes 0-4/5'], 90);
		$request = $this->httpRequests[0];
		$this->assertSame('put', $request['method']);
		$this->assertSame('BYTES', $request['options']['body']);
		$this->assertSame('bytes 0-4/5', $request['options']['headers']['Content-Range']);
		$this->assertSame(90, $request['options']['timeout']);
	}

	public function testATransportLevelFailureBecomesARuntimeExceptionPerContract(): void {
		$client = $this->createStub(IClient::class);
		$client->method('post')->willReturnCallback(static function (): void {
			throw new \Exception('could not resolve host');
		});
		$service = $this->createStub(IClientService::class);
		$service->method('newClient')->willReturn($client);
		$http = new HttpClient($service);
		$this->expectException(\RuntimeException::class);
		$http->post('https://unreachable.example', '{}');
	}

	public function testTheTokenCacheRefusesAForeverTtl(): void {
		$cache = new TokenCache($this->cacheFactory());
		$cache->set('missivus.token.abc', 'token-value', 0);
		$this->assertNull($cache->get('missivus.token.abc'), 'a token must never be cached without an expiry');
		$cache->set('missivus.token.abc', 'token-value', 3300);
		$this->assertSame('token-value', $cache->get('missivus.token.abc'));
		$this->assertSame(3300, $this->cacheWrites[0]['ttl']);
		$cache->delete('missivus.token.abc');
		$this->assertNull($cache->get('missivus.token.abc'));
	}

	public function testTheLoggerTagsEveryLineWithTheApp(): void {
		$logger = new Logger($this->psrLogger());
		$logger->error('e');
		$logger->warning('w');
		$logger->info('i');
		$this->assertSame(['e'], $this->logged('error'));
		$this->assertSame(['w'], $this->logged('warning'));
		$this->assertSame(['i'], $this->logged('info'));
	}
}
