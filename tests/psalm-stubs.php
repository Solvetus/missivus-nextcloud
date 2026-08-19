<?php

/**
 * Psalm-only declarations for classes that exist at runtime inside the
 * Nextcloud server but are not part of the nextcloud/ocp dev package.
 *
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GuzzleHttp\Exception;

class BadResponseException extends \RuntimeException {
	public function getResponse(): \GuzzleHttp\Psr7\Response {
		throw new \LogicException('psalm stub');
	}
}

namespace GuzzleHttp\Psr7;

class Response {
	public function getStatusCode(): int {
		throw new \LogicException('psalm stub');
	}

	public function getBody(): Stream {
		throw new \LogicException('psalm stub');
	}
}

class Stream {
	public function __toString(): string {
		throw new \LogicException('psalm stub');
	}
}
