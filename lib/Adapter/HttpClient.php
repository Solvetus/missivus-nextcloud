<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Adapter;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;

/**
 * Adapts Nextcloud's IClientService to the transport's two-method contract:
 * an HTTP error status is a normal HttpResponse, and only a request that could
 * not be made at all throws. Going through IClientService keeps Nextcloud's
 * proxy and CA-bundle configuration in effect.
 */
class HttpClient implements HttpClientInterface {
	public function __construct(
		private IClientService $clientService,
	) {
	}

	#[\Override]
	public function post($url, $body, array $headers = [], $timeout = 30): HttpResponse {
		return $this->request('post', (string)$url, (string)$body, $headers, (int)$timeout);
	}

	#[\Override]
	public function put($url, $body, array $headers = [], $timeout = 60): HttpResponse {
		return $this->request('put', (string)$url, (string)$body, $headers, (int)$timeout);
	}

	private function request(string $method, string $url, string $body, array $headers, int $timeout): HttpResponse {
		$client = $this->clientService->newClient();
		try {
			/** @var IResponse $response */
			$response = $client->$method($url, [
				'body' => $body,
				'headers' => $headers,
				'timeout' => $timeout,
				// Graph error statuses carry the diagnostic body; they must come
				// back as responses, not exceptions.
				'http_errors' => false,
			]);
		} catch (\Throwable $e) {
			// Belt and braces for stacks where http_errors is not honoured:
			// an HTTP-status exception still carries the response we want.
			if ($e instanceof \GuzzleHttp\Exception\BadResponseException) {
				$response = $e->getResponse();
				return new HttpResponse($response->getStatusCode(), (string)$response->getBody(), []);
			}
			throw new \RuntimeException('Missivus: HTTP request failed: ' . $e->getMessage(), 0, $e);
		}
		return new HttpResponse(
			$response->getStatusCode(),
			(string)$response->getBody(),
			$this->flattenHeaders($response),
		);
	}

	/** @return array<string, string> */
	private function flattenHeaders(IResponse $response): array {
		$flat = [];
		foreach ($response->getHeaders() as $name => $values) {
			$flat[(string)$name] = implode(', ', (array)$values);
		}
		return $flat;
	}
}
