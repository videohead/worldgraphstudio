/**
 * Review-first Story Import & Export administration.
 */
(function () {
	'use strict';

	var config = window.worldgraphStoryImport || {};
	var retryDelays = [1000, 2000, 4000, 8000, 16000];

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

	function bindPreviewForm() {
		var form = document.getElementById('worldgraph-import-form');
		if (!form) {
			return;
		}

		var fileInput = form.querySelector('#worldgraph_story_file, #worldgraph_json_file');
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

			submitting = true;
			disableSubmit(form, message('previewing', 'Uploading and preparing preview…'));
			HTMLFormElement.prototype.submit.call(form);
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

	function bindDecompositionJob() {
		var root = document.querySelector('[data-worldgraph-decomposition]');
		if (!root) {
			return;
		}

		var endpoint = String(root.getAttribute('data-endpoint') || '');
		var stageNode = root.querySelector('[data-worldgraph-job-stage]');
		var sectionNode = root.querySelector('[data-worldgraph-job-section]');
		var progressNode = root.querySelector('[data-worldgraph-job-progress]');
		var analysisCompletedNode = root.querySelector('[data-worldgraph-analysis-completed]');
		var analysisTotalNode = root.querySelector('[data-worldgraph-analysis-total]');
		var synthesisCompletedNode = root.querySelector('[data-worldgraph-synthesis-completed]');
		var synthesisTotalNode = root.querySelector('[data-worldgraph-synthesis-total]');
		var errorNode = root.querySelector('[data-worldgraph-job-error]');
		var resumeButton = root.querySelector('[data-worldgraph-job-resume]');
		var cancelButton = root.querySelector('[data-worldgraph-job-cancel]');
		var restartLink = root.querySelector('[data-worldgraph-job-restart]');
		var activeController = null;
		var retryTimer = null;
		var retryCount = 0;
		var stopped = false;
		var cancelling = false;

		if (!endpoint) {
			return;
		}

		function setNodeText(node, value) {
			if (node) {
				node.textContent = String(value);
			}
		}

		function clearTimer() {
			if (retryTimer !== null) {
				window.clearTimeout(retryTimer);
				retryTimer = null;
			}
		}

		function setPaused(text) {
			stopped = true;
			clearTimer();
			setNodeText(errorNode, text);
			if (resumeButton) {
				resumeButton.hidden = false;
				resumeButton.disabled = false;
			}
			if (cancelButton && !cancelling) {
				cancelButton.hidden = false;
				cancelButton.disabled = false;
			}
		}

		function setTerminalError(text) {
			stopped = true;
			clearTimer();
			setNodeText(errorNode, text);
			if (resumeButton) {
				resumeButton.hidden = true;
				resumeButton.disabled = false;
			}
			if (cancelButton) {
				cancelButton.hidden = true;
				cancelButton.disabled = false;
			}
			if (restartLink) {
				restartLink.hidden = false;
			}
		}

		function safeRedirect(url) {
			try {
				var target = new URL(String(url || ''), window.location.href);
				if (target.origin === window.location.origin) {
					window.location.assign(target.href);
					return true;
				}
			} catch (error) {
				return false;
			}
			return false;
		}

		function update(job) {
			if (!job || typeof job !== 'object') {
				return;
			}
			var status = String(job.status || '');
			var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
			var analysis = job.analysis && typeof job.analysis === 'object' ? job.analysis : {};
			var synthesis = job.synthesis && typeof job.synthesis === 'object' ? job.synthesis : {};
			var error = job.error && typeof job.error === 'object' ? job.error : {};

			root.setAttribute('data-status', status);
			setNodeText(stageNode, job.stage_label || 'Preparing story graph');
			setNodeText(sectionNode, job.section || '');
			setNodeText(analysisCompletedNode, Number(analysis.completed || 0));
			setNodeText(analysisTotalNode, Number(analysis.total || 0));
			setNodeText(synthesisCompletedNode, Number(synthesis.completed || 0));
			setNodeText(synthesisTotalNode, Number(synthesis.total || 0));
			setNodeText(errorNode, error.message || '');
			if (progressNode) {
				progressNode.value = Math.max(0, Math.min(100, Number(progress.percent || 0)));
				progressNode.textContent = String(progressNode.value) + '%';
			}

			if (cancelButton) {
				cancelButton.hidden = !job.can_cancel;
				cancelButton.disabled = false;
			}
			if (resumeButton) {
				resumeButton.hidden = true;
				resumeButton.disabled = false;
			}
			if (restartLink) {
				restartLink.hidden = ['failed', 'cancelled'].indexOf(status) === -1;
			}

			if (status === 'complete') {
				stopped = true;
				clearTimer();
				if (!safeRedirect(job.preview_url)) {
					setPaused(message('reviewError', 'The candidate is ready, but the review page could not be opened. Reload this page to continue.'));
				}
			} else if (status === 'failed' || status === 'cancelled') {
				stopped = true;
				clearTimer();
			} else if (status === 'cancelling') {
				cancelling = true;
				if (cancelButton) {
					cancelButton.hidden = true;
				}
			}
		}

		function request(method) {
			var requestController = window.AbortController ? new AbortController() : null;
			activeController = requestController;
			return window.fetch(endpoint, {
				method: method,
				credentials: 'same-origin',
				headers: {
					'Accept': 'application/json',
					'Content-Type': 'application/json',
					'X-WP-Nonce': String(config.restNonce || '')
				},
				body: method === 'POST' ? '{}' : undefined,
				signal: requestController ? requestController.signal : undefined
			}).then(function (response) {
				return response.json().catch(function () {
					return {};
				}).then(function (payload) {
					if (!response.ok) {
						var requestError = new Error(String(payload.message || 'The job request failed.'));
						requestError.status = response.status;
						throw requestError;
					}
					var job = payload && typeof payload === 'object' && payload.job && typeof payload.job === 'object' ? payload.job : null;
					var validStatuses = ['ready', 'running', 'cancelling', 'complete', 'failed', 'cancelled'];
					if (!job || typeof job.status !== 'string' || validStatuses.indexOf(job.status) === -1) {
						var protocolError = new Error(message('protocolError', 'The server returned an invalid preparation-job response.'));
						protocolError.name = 'ProtocolError';
						throw protocolError;
					}
					return job;
				});
			}).finally(function () {
				if (activeController === requestController) {
					activeController = null;
				}
			});
		}

		function scheduleRetry(error, operation) {
			if (cancelling && error && error.name === 'AbortError') {
				return;
			}
			var status = Number(error && error.status || 0);
			if ([401, 403, 404, 410].indexOf(status) !== -1) {
				setTerminalError(status === 404 || status === 410 ? message('jobExpired', 'This preparation job expired. Upload the source again.') : String(error.message || 'You are not authorized to continue this job.'));
				return;
			}
			if (retryCount >= retryDelays.length) {
				setPaused(message('networkError', 'Preparation paused after repeated connection errors. You can safely resume from the last checkpoint.'));
				return;
			}

			var delay = retryDelays[retryCount];
			retryCount += 1;
			var seconds = Math.ceil(delay / 1000);
			setNodeText(errorNode, message('retrying', 'The connection was interrupted. Retrying this checkpoint in %d seconds…').replace(/%(?:1\$)?d/, String(seconds)));
			clearTimer();
			retryTimer = window.setTimeout(operation, delay);
		}

		function pollCancellation() {
			if (!cancelling) {
				return;
			}
			// Re-issue DELETE so a cancellation that initially lost the active
			// checkpoint lock is committed as soon as that lock is released.
			request('DELETE').then(function (job) {
				retryCount = 0;
				update(job);
				if (job && job.status === 'cancelling') {
					retryTimer = window.setTimeout(pollCancellation, 1000);
				}
			}).catch(function (error) {
				scheduleRetry(error, pollCancellation);
			});
		}

		function step() {
			if (stopped || cancelling) {
				return;
			}
			if (resumeButton) {
				resumeButton.hidden = true;
			}
			request('POST').then(function (job) {
				retryCount = 0;
				update(job);
				if (job && job.can_step && !stopped && !cancelling) {
					retryTimer = window.setTimeout(step, 100);
				}
			}).catch(function (error) {
				scheduleRetry(error, step);
			});
		}

		function resume() {
			stopped = false;
			cancelling = false;
			retryCount = 0;
			setNodeText(errorNode, '');
			step();
		}

		function sendCancellation() {
			request('DELETE').then(function (job) {
				retryCount = 0;
				update(job);
				if (job && job.status === 'cancelling') {
					retryTimer = window.setTimeout(pollCancellation, 1000);
				}
			}).catch(function (error) {
				scheduleRetry(error, sendCancellation);
			});
		}

		if (resumeButton) {
			resumeButton.addEventListener('click', resume);
		}
		if (cancelButton) {
			cancelButton.addEventListener('click', function () {
				if (!window.confirm(message('cancelConfirm', 'Cancel story preparation?'))) {
					return;
				}
				cancelling = true;
				stopped = true;
				clearTimer();
				if (activeController) {
					activeController.abort();
				}
				cancelButton.disabled = true;
				setNodeText(stageNode, message('cancelling', 'Cancelling after the active checkpoint…'));
				sendCancellation();
			});
		}

		function loadStatus() {
			request('GET').then(function (job) {
				retryCount = 0;
				update(job);
				if (job && job.can_step && !stopped && !cancelling) {
					step();
				} else if (job && job.status === 'cancelling') {
					pollCancellation();
				}
			}).catch(function (error) {
				scheduleRetry(error, loadStatus);
			});
		}

		loadStatus();
	}

	function bind() {
		bindPreviewForm();
		bindConfirmForm();
		bindDecompositionJob();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
}());
