/**
 * Missivus admin settings — vanilla JS, no build step.
 *
 * SPDX-FileCopyrightText: 2026 Solvetus
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(function () {
	'use strict';

	function requestToken() {
		return document.head.dataset.requesttoken
			|| (window.OC && window.OC.requestToken)
			|| '';
	}

	function ocsUrl(path) {
		var root = (window.OC && OC.getRootPath && OC.getRootPath()) || '';
		return root + '/ocs/v2.php/apps/missivus' + path;
	}

	function post(path, body) {
		return fetch(ocsUrl(path), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'OCS-APIRequest': 'true',
				'requesttoken': requestToken()
			},
			body: JSON.stringify(body),
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json().then(function (json) {
				var data = (json.ocs && json.ocs.data) || {};
				return { ok: response.ok, data: data };
			});
		});
	}

	// Results are written with textContent only; an error body from Microsoft
	// must never be interpreted as markup.
	function showResult(element, message, success) {
		element.textContent = message;
		element.classList.toggle('missivus-result-ok', success);
		element.classList.toggle('missivus-result-error', !success);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('missivus-settings-form');
		var saveResult = document.getElementById('missivus-save-result');
		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				var values = {};
				form.querySelectorAll('input[name], select[name]').forEach(function (input) {
					if (input.disabled) {
						return; // config.php-managed: never posted
					}
					if (input.type === 'checkbox') {
						values[input.name] = input.checked ? '1' : '0';
					} else {
						values[input.name] = input.value;
					}
				});
				showResult(saveResult, t('missivus', 'Saving…'), true);
				post('/api/v1/settings', { values: values }).then(function (result) {
					if (result.ok && result.data.success) {
						showResult(saveResult, t('missivus', 'Saved.'), true);
						form.querySelectorAll('input[type="password"]').forEach(function (input) {
							input.value = '';
						});
					} else {
						showResult(saveResult, result.data.message || t('missivus', 'Saving failed.'), false);
					}
				}).catch(function () {
					showResult(saveResult, t('missivus', 'Saving failed.'), false);
				});
			});
		}

		var testButton = document.getElementById('missivus-test-button');
		var testAddress = document.getElementById('missivus-test-address');
		var testResult = document.getElementById('missivus-test-result');
		if (testButton) {
			testButton.addEventListener('click', function () {
				testButton.disabled = true;
				showResult(testResult, t('missivus', 'Sending…'), true);
				post('/api/v1/test-email', { email: testAddress.value }).then(function (result) {
					showResult(
						testResult,
						result.data.message || t('missivus', 'Sending failed.'),
						Boolean(result.data.success)
					);
				}).catch(function () {
					showResult(testResult, t('missivus', 'Sending failed.'), false);
				}).finally(function () {
					testButton.disabled = false;
				});
			});
		}
	});
})();
