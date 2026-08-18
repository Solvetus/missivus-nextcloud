<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Solvetus <hello@solvetus.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @var array $_ parameters from AdminSettings::getForm()
 * @var \OCP\IL10N $l
 */

/** @var array<string, string> $values */
$values = $_['values'];
/** @var array<string, bool> $overridden */
$overridden = $_['overridden'];

$field = static function (string $key, string $label, string $type = 'text', string $hint = '') use ($values, $overridden, $l): void {
	$isOverridden = $overridden[$key];
	?>
	<div class="missivus-field">
		<label for="missivus-<?php p($key) ?>"><?php p($label) ?></label>
		<input type="<?php p($type) ?>"
			id="missivus-<?php p($key) ?>"
			name="<?php p($key) ?>"
			value="<?php p($type === 'password' ? '' : $values[$key]) ?>"
			autocomplete="off"
			<?php if ($type === 'password') { ?>autocapitalize="off" spellcheck="false"<?php } ?>
			<?php if ($isOverridden) { ?>disabled placeholder="<?php p($l->t('Set in config.php')) ?>"<?php } ?> />
		<?php if ($isOverridden) { ?>
			<em><?php p($l->t('This value comes from the missivus array in config.php and cannot be edited here.')) ?></em>
		<?php } elseif ($hint !== '') { ?>
			<em><?php p($hint) ?></em>
		<?php } ?>
	</div>
	<?php
};

$toggle = static function (string $key, string $label, string $hint = '') use ($values, $overridden, $l): void {
	$isOverridden = $overridden[$key];
	?>
	<div class="missivus-field missivus-toggle">
		<input type="checkbox" class="checkbox" id="missivus-<?php p($key) ?>" name="<?php p($key) ?>"
			<?php if ($values[$key] === '1') { ?>checked<?php } ?>
			<?php if ($isOverridden) { ?>disabled<?php } ?> />
		<label for="missivus-<?php p($key) ?>"><?php p($label) ?></label>
		<?php if ($isOverridden) { ?>
			<em><?php p($l->t('Set in config.php')) ?></em>
		<?php } elseif ($hint !== '') { ?>
			<em><?php p($hint) ?></em>
		<?php } ?>
	</div>
	<?php
};
?>
<div id="missivus-admin" class="section">
	<h2>Missivus</h2>
	<p class="settings-hint">
		<?php p($l->t('Send all of Nextcloud\'s outgoing email through Microsoft Graph using application permissions and one shared mailbox — no SMTP, no user login.')) ?>
	</p>

	<?php if ($_['lastFailure'] !== null) { ?>
		<div class="missivus-notice missivus-notice-error">
			<strong><?php p($l->t('Last delivery failure (%s):', [date('Y-m-d H:i', $_['lastFailure']['at'])])) ?></strong>
			<span><?php p($_['lastFailure']['message']) ?></span>
		</div>
	<?php } ?>

	<form id="missivus-settings-form">
		<?php $toggle('enabled', $l->t('Send Nextcloud email through Microsoft Graph'), $l->t('While off, Nextcloud\'s own mailer keeps working untouched.')); ?>
		<?php $field('tenant_id', $l->t('Directory (tenant) ID')); ?>
		<?php $field('client_id', $l->t('Application (client) ID')); ?>

		<div class="missivus-field">
			<label for="missivus-auth_method"><?php p($l->t('Authentication method')) ?></label>
			<select id="missivus-auth_method" name="auth_method" <?php if ($overridden['auth_method']) { ?>disabled<?php } ?>>
				<option value="secret" <?php if ($values['auth_method'] === 'secret') { ?>selected<?php } ?>><?php p($l->t('Client secret')) ?></option>
				<option value="certificate" <?php if ($values['auth_method'] === 'certificate') { ?>selected<?php } ?>><?php p($l->t('Certificate')) ?></option>
			</select>
			<?php if ($overridden['auth_method']) { ?><em><?php p($l->t('Set in config.php')) ?></em><?php } ?>
		</div>

		<?php $field(
			'client_secret',
			$l->t('Client secret'),
			'password',
			$_['hasClientSecret'] ? $l->t('Saved — enter a new value to replace it. The stored value is never shown.') : ''
		); ?>
		<?php $field('certificate_path', $l->t('Certificate path'), 'text', $l->t('Absolute path to a PEM file readable by the web server. Only used with the certificate method.')); ?>
		<?php $field(
			'certificate_passphrase',
			$l->t('Certificate passphrase'),
			'password',
			$_['hasCertificatePassphrase'] ? $l->t('Saved — enter a new value to replace it. The stored value is never shown.') : ''
		); ?>
		<?php $field('sender_mailbox', $l->t('Sender mailbox'), 'text', $l->t('The shared mailbox every message is sent from. Point mail_from_address and mail_domain at it too.')); ?>
		<?php $toggle('save_to_sent', $l->t('Keep a copy in the mailbox\'s Sent items')); ?>
		<?php $toggle('fallback_to_system', $l->t('Fall back to Nextcloud\'s own mailer when Graph fails'), $l->t('Off by default. Failures are always logged either way.')); ?>

		<div class="missivus-actions">
			<button type="submit" class="primary"><?php p($l->t('Save')) ?></button>
			<span id="missivus-save-result" role="status" aria-live="polite"></span>
		</div>
	</form>

	<h3><?php p($l->t('Send a test email')) ?></h3>
	<?php if ($_['configProblem'] !== null) { ?>
		<p class="missivus-notice"><?php p($l->t('Not ready to send yet: %s The test sends with what is stored, not what is typed above.', [$_['configProblem']])) ?></p>
	<?php } ?>
	<div class="missivus-field missivus-test">
		<input type="text" id="missivus-test-address" placeholder="<?php p($l->t('you@example.com')) ?>" autocomplete="email" />
		<button id="missivus-test-button" class="primary" <?php if ($_['configProblem'] !== null) { ?>disabled<?php } ?>>
			<?php p($l->t('Send test email')) ?>
		</button>
	</div>
	<div id="missivus-test-result" role="status" aria-live="polite"></div>

	<p class="missivus-privacy">
		<?php p($l->t('When enabled, this app connects to login.microsoftonline.com to obtain access tokens and to graph.microsoft.com to send mail. No other external service is contacted.')) ?>
	</p>
	<p class="missivus-support">
		<?php print_unescaped($l->t(
			'Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself, %1$sSolvetus%2$s offers paid installation and support.',
			['<a href="https://solvetus.com" target="_blank" rel="noreferrer noopener">', '</a>']
		)) ?>
	</p>
</div>
