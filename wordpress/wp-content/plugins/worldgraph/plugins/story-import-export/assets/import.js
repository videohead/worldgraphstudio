/**
 * Review-first Story Import & Export administration.
 */
(function () {
	'use strict';

	var config = window.worldgraphStoryImport || {};
	var canonicalSections = [
		'project',
		'world',
		'characters',
		'locations',
		'props',
		'scenes',
		'shots',
		'sequence'
	];

	function message(key, fallback) {
		return typeof config[key] === 'string' ? config[key] : fallback;
	}

	function extension(filename) {
		var match = String(filename || '').toLowerCase().match(/\.([a-z0-9]+)$/);
		return match ? match[1] : '';
	}

	function supported(filename) {
		var allowed = Array.isArray(config.allowedTypes) ? config.allowedTypes : [
			'json', 'txt', 'text', 'md', 'markdown', 'fountain', 'rtf', 'pdf', 'epub', 'docx', 'odt'
		];
		return allowed.indexOf(extension(filename)) !== -1;
	}

	function setStatus(form, text) {
		var status = form.querySelector('[data-worldgraph-import-status]');
		if (status) {
			status.textContent = text || '';
		}
	}

	function disableSubmit(form, text) {
		var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
		buttons.forEach(function (button) {
			button.disabled = true;
			if (button.tagName === 'INPUT') {
				button.value = text;
			} else {
				button.textContent = text;
			}
		});
		setStatus(form, text);
	}

	function readCanonicalJson(file) {
		return new Promise(function (resolve) {
			if (extension(file.name) !== 'json' || !window.FileReader) {
				resolve(false);
				return;
			}

			var reader = new FileReader();
			reader.onload = function () {
				try {
					var documentValue = JSON.parse(String(reader.result || ''));
					resolve(
						documentValue !== null &&
						typeof documentValue === 'object' &&
						canonicalSections.every(function (section) {
							return Object.prototype.hasOwnProperty.call(documentValue, section);
						})
					);
				} catch (error) {
					resolve(false);
				}
			};
			reader.onerror = function () {
				resolve(false);
			};
			reader.readAsText(file);
		});
	}

	function bindPreviewForm() {
		var form = document.getElementById('worldgraph-import-form');
		if (!form) {
			return;
		}

		var fileInput = form.querySelector('#worldgraph_story_file, #worldgraph_json_file');
		var connection = form.querySelector('#worldgraph_connection_id');
		var submitting = false;

		form.addEventListener('submit', function (event) {
			if (submitting) {
				return;
			}

			event.preventDefault();
			var file = fileInput && fileInput.files ? fileInput.files[0] : null;
			if (!file) {
				window.alert(message('chooseFile', 'Choose a supported story file.'));
				return;
			}
			if (!supported(file.name)) {
				window.alert(message('unsupportedFile', 'Choose a supported story file type.'));
				return;
			}
			if (Number(config.maxUploadBytes || 20971520) < file.size) {
				window.alert(message('fileTooLarge', 'Story source files may not exceed 20 MB.'));
				return;
			}

			readCanonicalJson(file).then(function (isCanonical) {
				if (!isCanonical && (!connection || !connection.value)) {
					window.alert(message('chooseConnection', 'Select an LLM Connection for this story source.'));
					if (connection) {
						connection.focus();
					}
					return;
				}

				submitting = true;
				disableSubmit(form, message('previewing', 'Uploading and preparing preview…'));
				HTMLFormElement.prototype.submit.call(form);
			});
		});
	}

	function bindConfirmForm() {
		var form = document.getElementById('worldgraph-confirm-import-form');
		if (!form) {
			return;
		}

		form.addEventListener('submit', function (event) {
			var checkbox = form.querySelector('[data-worldgraph-confirm]');
			if (!checkbox || !checkbox.checked) {
				event.preventDefault();
				window.alert(message('confirmRequired', 'Check the confirmation box before importing.'));
				return;
			}
			if (!window.confirm(message('confirmImport', 'Import the reviewed candidate now?'))) {
				event.preventDefault();
				return;
			}

			disableSubmit(form, message('importing', 'Importing reviewed candidate…'));
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			bindPreviewForm();
			bindConfirmForm();
		});
	} else {
		bindPreviewForm();
		bindConfirmForm();
	}
}());
