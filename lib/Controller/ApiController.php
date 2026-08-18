<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace OCA\Missivus\Controller;

use OCA\Missivus\Mail\GraphMailer;
use OCA\Missivus\Mail\MailerFactory;
use OCA\Missivus\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Mail\IEmailValidator;
use OCP\Mail\IMailer;
use Solvetus\Missivus\Exception\GraphException;

/**
 * Admin-only OCS API. Neither route opts out of the AppFramework defaults:
 * no NoAdminRequired and no NoCSRFRequired attribute means both checks apply.
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private IMailer $mailer,
		private MailerFactory $mailerFactory,
		private IEmailValidator $emailValidator,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Save the settings. Values for keys managed in config.php are discarded
	 * by the service; secrets with an empty value keep what is stored.
	 *
	 * @param array<string, string> $values
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/settings')]
	public function saveSettings(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (!array_key_exists((string)$key, ConfigService::KEYS)) {
				return new DataResponse([
					'success' => false,
					'message' => $this->l->t('Unknown setting.'),
				], Http::STATUS_BAD_REQUEST);
			}
		}
		try {
			foreach ($values as $key => $value) {
				$this->config->set((string)$key, (string)$value);
			}
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([
				'success' => false,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'success' => true,
			'configProblem' => $this->config->getConfigurationProblem(),
		]);
	}

	/**
	 * Send a test email through Graph. The fallback is deliberately ignored —
	 * a test that quietly succeeded over SMTP proves nothing — and the
	 * redacted Microsoft error body is returned inline on failure, because it
	 * is the single most useful thing for diagnosing a misconfigured tenant.
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/test-email')]
	public function testEmail(string $email): DataResponse {
		$email = trim($email);
		if ($email === '' || !$this->emailValidator->isValid($email)) {
			return new DataResponse([
				'success' => false,
				'message' => $this->l->t('Please enter a valid recipient address.'),
			], Http::STATUS_BAD_REQUEST);
		}

		$mailer = $this->mailer instanceof GraphMailer
			? $this->mailer
			: $this->mailerFactory->decorate($this->mailer);

		$message = $mailer->createMessage();
		$message->setTo([$email]);
		$message->setSubject($this->l->t('Missivus test email'));
		$message->setPlainBody($this->l->t('This message was sent from your Nextcloud through Microsoft Graph by the Missivus app. Delivery works.'));

		try {
			$mailer->sendWithoutFallback($message);
		} catch (GraphException $e) {
			return new DataResponse([
				'success' => false,
				'message' => $e->getMessage(),
			]);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'message' => $mailer->redact($e->getMessage()),
			]);
		}
		$this->config->clearFailure();
		return new DataResponse([
			'success' => true,
			'message' => $this->l->t('Test email sent to %s through Microsoft Graph.', [$email]),
		]);
	}
}
