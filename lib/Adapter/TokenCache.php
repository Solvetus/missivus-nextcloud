<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Adapter;

use OCA\Missivus\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;
use Solvetus\Missivus\Contract\TokenCacheInterface;

/**
 * Adapts Nextcloud's distributed cache to the transport's token-cache
 * contract. Without a configured distributed cache Nextcloud hands back a
 * per-request cache — correct, just a token refetch per request.
 */
class TokenCache implements TokenCacheInterface {
	private ?ICache $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
	) {
	}

	private function cache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(Application::APP_ID);
	}

	public function get($key) {
		$value = $this->cache()->get((string)$key);
		return is_string($value) && $value !== '' ? $value : null;
	}

	public function set($key, $value, $ttlSeconds): void {
		$ttl = (int)$ttlSeconds;
		if ($ttl <= 0) {
			// A TTL of zero means "forever" to the cache; a bearer token must
			// never be cached without an expiry.
			return;
		}
		$this->cache()->set((string)$key, (string)$value, $ttl);
	}

	public function delete($key): void {
		$this->cache()->remove((string)$key);
	}
}
