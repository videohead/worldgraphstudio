(function () {
	'use strict';

	var config = window.worldgraphConnectionEditor || {};
	var strings = config.i18n || {};

	function findScfField(name) {
		var fieldKey = 'field_worldgraph_conn_' + name;
		return document.getElementById(name) ||
			document.getElementById('acf-' + fieldKey) ||
			document.querySelector('[name="acf[' + fieldKey + ']"]') ||
			document.querySelector('[data-name="' + name + '"] input, [data-name="' + name + '"] select, [data-name="' + name + '"] textarea');
	}

	function installEndpointDefaults() {
		var provider = findScfField('provider_type');
		var endpoint = findScfField('endpoint_url');
		var mcpEndpoint = findScfField('mcp_endpoint_url');
		var endpoints = config.endpointUrls || {};
		var mcpEndpoints = config.mcpEndpointUrls || {};

		if (!provider || !endpoint || !mcpEndpoint) {
			return;
		}

		provider.addEventListener('change', function () {
			if (!endpoint.value.trim() && endpoints[provider.value]) {
				endpoint.value = endpoints[provider.value];
				endpoint.dispatchEvent(new Event('change', { bubbles: true }));
			}
			if (!mcpEndpoint.value.trim() && mcpEndpoints[provider.value]) {
				mcpEndpoint.value = mcpEndpoints[provider.value];
				mcpEndpoint.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});
	}

	function format(message, values) {
		var output = String(message || '');
		(values || []).forEach(function (value, index) {
			var position = index + 1;
			output = output.replace(new RegExp('%' + position + '\\$[ds]', 'g'), String(value));
		});
		if (values && values.length === 1) {
			output = output.replace(/%[ds]/g, String(values[0]));
		}
		return output;
	}

	function clear(node) {
		while (node && node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function element(tagName, className, text) {
		var node = document.createElement(tagName);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	function humanize(value) {
		return String(value || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, function (letter) {
			return letter.toUpperCase();
		});
	}

	function formatBytes(bytes) {
		var amount = Number(bytes || 0);
		var units = strings.byteUnits || ['B', 'KB', 'MB', 'GB', 'TB'];
		var index = 0;
		if (!amount) {
			return '';
		}
		while (amount >= 1024 && index < units.length - 1) {
			amount /= 1024;
			index += 1;
		}
		return (amount >= 10 ? Math.round(amount) : amount.toFixed(1)) + ' ' + units[index];
	}

	function responseMessage(data, fallback) {
		return data && data.message ? String(data.message) : String(fallback || '');
	}

	function request(action, extra) {
		var payload = {
			action: action,
			nonce: config.nonce || '',
			connection_id: config.connectionId || 0
		};
		Object.keys(extra || {}).forEach(function (key) {
			payload[key] = extra[key];
		});

		return fetch(config.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams(payload).toString()
		}).then(function (response) {
			return response.json().catch(function () {
				throw new Error(strings.networkError || 'Invalid provider setup response.');
			});
		}).then(function (response) {
			if (!response || !response.success) {
				throw new Error(response && response.data && response.data.message ? response.data.message : (strings.networkError || 'Provider setup request failed.'));
			}
			return response.data || {};
		});
	}

	function installLlmModelSelector() {
		var provider = findScfField('provider_type');
		var modelInput = findScfField('model');
		var providers = config.llmProviders || [];
		if (!provider || !modelInput) {
			return;
		}

		var selector = element('select', 'regular-text');
		var loadButton = element('button', 'button', strings.loadModels || 'Load models');
		var status = element('span', 'description');
		loadButton.type = 'button';
		loadButton.style.marginLeft = '8px';
		status.style.marginLeft = '8px';
		modelInput.insertAdjacentElement('afterend', status);
		modelInput.insertAdjacentElement('afterend', loadButton);
		modelInput.insertAdjacentElement('afterend', selector);

		function populate(models) {
			var current = modelInput.value;
			clear(selector);
			selector.appendChild(new Option('Select a model', ''));
			if (current && models.indexOf(current) === -1) {
				selector.appendChild(new Option(current, current));
			}
			models.forEach(function (model) {
				selector.appendChild(new Option(model, model));
			});
			selector.value = current;
		}

		function updateVisibility() {
			var supported = providers.indexOf(provider.value) !== -1;
			modelInput.style.display = supported ? 'none' : '';
			selector.style.display = supported ? '' : 'none';
			loadButton.style.display = supported ? '' : 'none';
			status.style.display = supported ? '' : 'none';
		}

		selector.addEventListener('change', function () {
			modelInput.value = selector.value;
			modelInput.dispatchEvent(new Event('change', { bubbles: true }));
		});
		loadButton.addEventListener('click', function () {
			loadButton.disabled = true;
			status.textContent = strings.loadingModels || 'Loading models...';
			request('worldgraph_discover_connection_models').then(function (result) {
				var models = Array.isArray(result.models) ? result.models : [];
				populate(models);
				status.textContent = models.length ? (result.message || strings.modelsLoaded) : strings.modelsUnavailable;
			}).catch(function (error) {
				status.textContent = error.message || strings.networkError;
			}).then(function () {
				loadButton.disabled = false;
			});
		});
		provider.addEventListener('change', updateVisibility);
		populate([]);
		updateVisibility();
	}

	function installWorkflowConfigurator() {
		var status = document.getElementById('worldgraph-connection-configurator-status');
		var summary = document.getElementById('worldgraph-connection-configurator-summary');
		var sessionLog = document.getElementById('worldgraph-connection-configurator-log');
		var results = document.getElementById('worldgraph-connection-configurator-results');
		var lastChecked = document.getElementById('worldgraph-connection-last-checked');
		var refreshButton = document.getElementById('worldgraph-connection-sync-catalog');
		var addAllButton = document.getElementById('worldgraph-connection-guided-setup');

		if (!status || !summary || !sessionLog || !results || !refreshButton || !addAllButton) {
			return;
		}

		var busy = false;
		var activity = [];
		var currentSnapshot = config.initialCatalog || { synced_at: '', message: '', entries: [] };

		function setStatus(message, tone) {
			status.textContent = message || '';
			status.className = message ? 'notice inline notice-' + (tone || 'info') : '';
			status.style.margin = message ? '10px 0' : '';
			status.style.padding = message ? '8px 12px' : '';
		}

		function renderActivity() {
			clear(sessionLog);
			var list = element('ol');
			list.style.margin = '0 0 0 1.4em';
			activity.forEach(function (item) {
				var row = element('li');
				var time = element('time', '', item.time + ' — ');
				var message = element('span', '', item.message);
				row.appendChild(time);
				row.appendChild(message);
				list.appendChild(row);
			});
			sessionLog.appendChild(list);
		}

		function pushActivity(message) {
			activity.unshift({ time: new Date().toLocaleTimeString(), message: String(message || '') });
			activity = activity.slice(0, 8);
			renderActivity();
		}

		function setBusy(flag, message) {
			busy = flag;
			if (message) {
				setStatus(message, 'info');
			}
			refreshButton.disabled = flag;
			addAllButton.disabled = flag;
			results.setAttribute('aria-busy', flag ? 'true' : 'false');
			Array.prototype.forEach.call(results.querySelectorAll('button'), function (button) {
				button.disabled = flag;
			});
		}

		function orderEntries(entries) {
			var weight = { ready: 0, needs_models: 2, needs_nodes: 3, unverified: 4, unmappable: 5, withdrawn: 6 };
			return entries.slice().sort(function (left, right) {
				var leftWeight = left.template_id ? 1 : (weight[left.status] === undefined ? 9 : weight[left.status]);
				var rightWeight = right.template_id ? 1 : (weight[right.status] === undefined ? 9 : weight[right.status]);
				if (leftWeight !== rightWeight) {
					return leftWeight - rightWeight;
				}
				return String(left.name || left.id).localeCompare(String(right.name || right.id));
			});
		}

		function updateSummary(entries) {
			var ready = entries.filter(function (entry) { return entry.status === 'ready'; }).length;
			var added = entries.filter(function (entry) { return Number(entry.template_id || 0) > 0; }).length;
			var attention = entries.filter(function (entry) { return entry.status !== 'ready'; }).length;
			summary.textContent = format(strings.summary, [entries.length, ready, added, attention]);

			if (lastChecked) {
				lastChecked.textContent = currentSnapshot.synced_at ?
					format(strings.lastChecked, [currentSnapshot.synced_at]) :
					(strings.notCheckedYet || 'Not checked yet');
			}
		}

		function statusHelp(entry) {
			var help = {
				needs_nodes: strings.needsNodesHelp,
				unverified: strings.unverifiedHelp,
				unmappable: strings.unmappableHelp,
				withdrawn: strings.withdrawnHelp
			};
			return help[entry.status] || '';
		}

		function editTemplateLink(entry) {
			var link = element('a', 'button button-small', strings.editTemplate || 'Edit Generation Template');
			link.href = (config.editPostUrl || '') + encodeURIComponent(entry.template_id);
			return link;
		}

		function addWorkflow(entry) {
			if (busy) {
				return;
			}
			setBusy(true, strings.addingWorkflow);
			request('worldgraph_materialize_connection_catalog_entry', { entry_id: entry.id }).then(function (data) {
				currentSnapshot = data.snapshot || currentSnapshot;
				render(currentSnapshot);
				var message = responseMessage(data, strings.workflowAdded);
				setStatus(message, 'success');
				pushActivity(message);
			}).catch(function (error) {
				var message = error.message || strings.workflowAddFailed;
				setStatus(message, 'error');
				pushActivity(message);
			}).then(function () {
				setBusy(false);
			});
		}

		function installModels(entry) {
			if (busy) {
				return;
			}
			setBusy(true, strings.installingModels);
			request('worldgraph_download_connection_catalog_entry', { entry_id: entry.id }).then(function (data) {
				var message = responseMessage(data, strings.installRequestSent);
				setStatus(message, 'success');
				pushActivity(message);
			}).catch(function (error) {
				var message = error.message || strings.installRequestFailed;
				setStatus(message, 'error');
				pushActivity(message);
			}).then(function () {
				setBusy(false);
			});
		}

		function workflowCard(entry) {
			var state = entry.status || 'unknown';
			var colors = { ready: '#00a32a', needs_models: '#dba617', needs_nodes: '#d63638', unverified: '#996800', unmappable: '#646970', withdrawn: '#646970' };
			var card = element('article', 'worldgraph-connection-workflow');
			card.style.cssText = 'margin:0 0 10px;padding:12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid ' + (colors[state] || '#c3c4c7') + ';';

			var header = element('div');
			header.style.cssText = 'display:flex;gap:12px;align-items:flex-start;';
			if (entry.thumbnail) {
				var image = element('img');
				image.src = entry.thumbnail;
				image.alt = '';
				image.loading = 'lazy';
				image.style.cssText = 'width:72px;height:72px;object-fit:cover;border-radius:3px;flex:0 0 auto;background:#f0f0f1;';
				image.addEventListener('error', function () { image.remove(); });
				header.appendChild(image);
			}

			var body = element('div');
			body.style.flex = '1 1 auto';
			body.appendChild(element('strong', '', entry.name || entry.id));

			var badges = element('div');
			badges.style.margin = '4px 0';
			var readiness = element('span', 'worldgraph-status-badge', (strings.statusLabels && strings.statusLabels[state]) || strings.unknownStatus || state);
			readiness.style.cssText = 'display:inline-block;margin:0 6px 3px 0;padding:2px 7px;border-radius:10px;background:#f0f0f1;font-size:12px;font-weight:600;';
			badges.appendChild(readiness);
			if (entry.template_id) {
				var added = element('span', 'worldgraph-status-badge', strings.addedToStudio || 'Added to Studio');
				added.style.cssText = 'display:inline-block;margin:0 6px 3px 0;padding:2px 7px;border-radius:10px;background:#edfaef;color:#006b2d;font-size:12px;font-weight:600;';
				badges.appendChild(added);
			}
			body.appendChild(badges);

			var facts = [];
			if (entry.modality) { facts.push(humanize(entry.modality)); }
			if (entry.model_type) { facts.push(entry.model_type); }
			if (entry.size) { facts.push((strings.download || 'Download') + ': ' + formatBytes(entry.size)); }
			if (entry.api_only) { facts.push(strings.providerBilling); }
			if (entry.missing_models && entry.missing_models.length) { facts.push(entry.missing_models.length + ' ' + strings.modelFilesMissing); }
			if (entry.missing_nodes && entry.missing_nodes.length) { facts.push(strings.missingNodes + ': ' + entry.missing_nodes.join(', ')); }
			if (facts.length) {
				body.appendChild(element('div', 'description', facts.join(' · ')));
			}
			if (entry.description) {
				var description = String(entry.description);
				body.appendChild(element('p', 'description', description.length > 240 ? description.slice(0, 239) + '…' : description));
			}
			var help = statusHelp(entry);
			if (help) {
				body.appendChild(element('p', 'description', help));
			}
			header.appendChild(body);
			card.appendChild(header);

			var actions = element('div');
			actions.style.marginTop = '8px';
			if (entry.template_id) {
				actions.appendChild(editTemplateLink(entry));
			}
			if (!entry.template_id && state === 'ready' && entry.modality) {
				var addButton = element('button', 'button button-primary button-small', strings.addToStudio || 'Add to Studio');
				addButton.type = 'button';
				addButton.addEventListener('click', function () { addWorkflow(entry); });
				actions.appendChild(addButton);
			}
			if (state === 'needs_models') {
				var installButton = element('button', 'button button-small', strings.installModels || 'Install model files');
				installButton.type = 'button';
				installButton.style.marginLeft = entry.template_id ? '6px' : '0';
				installButton.addEventListener('click', function () { installModels(entry); });
				actions.appendChild(installButton);
			}
			if (actions.childNodes.length) {
				card.appendChild(actions);
			}

			return card;
		}

		function render(snapshot) {
			currentSnapshot = snapshot || { synced_at: '', message: '', entries: [] };
			var entries = Array.isArray(currentSnapshot.entries) ? currentSnapshot.entries : [];
			clear(results);
			updateSummary(entries);
			if (!entries.length) {
				results.appendChild(element('p', 'description', strings.noTemplates));
				return;
			}
			orderEntries(entries).forEach(function (entry) {
				results.appendChild(workflowCard(entry));
			});
		}

		function refreshWorkflows() {
			if (busy) {
				return Promise.resolve(null);
			}
			setBusy(true, strings.refreshingWorkflows);
			return request('worldgraph_sync_connection_catalog').then(function (data) {
				currentSnapshot = data.snapshot || currentSnapshot;
				render(currentSnapshot);
				var message = responseMessage(data, strings.workflowsRefreshed);
				setStatus(message, 'success');
				pushActivity(message);
				return data;
			}).catch(function (error) {
				var message = error.message || strings.workflowRefreshFailed;
				setStatus(message, 'error');
				pushActivity(message);
				return null;
			}).then(function (data) {
				setBusy(false);
				return data;
			});
		}

		function addAllReadyWorkflows() {
			if (busy) {
				return;
			}
			setBusy(true, strings.addingReadyWorkflows);
			request('worldgraph_sync_connection_catalog').then(function (data) {
				currentSnapshot = data.snapshot || currentSnapshot;
				render(currentSnapshot);
				setBusy(true);
				var queue = (currentSnapshot.entries || []).filter(function (entry) {
					return entry.status === 'ready' && entry.modality && !entry.template_id;
				});
				if (!queue.length) {
					setStatus(strings.noReadyWorkflows, 'info');
					pushActivity(strings.noReadyWorkflows);
					return null;
				}

				var added = 0;
				var failed = 0;
				return queue.reduce(function (promise, entry, index) {
					return promise.then(function () {
						setStatus(format(strings.addAllProgress, [index + 1, queue.length, entry.name || entry.id]), 'info');
						return request('worldgraph_materialize_connection_catalog_entry', { entry_id: entry.id }).then(function (result) {
							added += 1;
							currentSnapshot = result.snapshot || currentSnapshot;
							render(currentSnapshot);
							setBusy(true);
						}).catch(function (error) {
							failed += 1;
							pushActivity((entry.name || entry.id) + ': ' + (error.message || strings.workflowAddFailed));
						});
					});
				}, Promise.resolve()).then(function () {
					var message = format(strings.addAllFinished, [added, failed]);
					setStatus(message, failed ? 'warning' : 'success');
					pushActivity(message);
				});
			}).catch(function (error) {
				var message = error.message || strings.addAllIncomplete;
				setStatus(message, 'error');
				pushActivity(message);
			}).then(function () {
				setBusy(false);
			});
		}

		refreshButton.addEventListener('click', refreshWorkflows);
		addAllButton.addEventListener('click', addAllReadyWorkflows);
		render(currentSnapshot);
		setStatus(currentSnapshot.message || strings.interfaceReady, 'info');
		pushActivity(strings.interfaceReady);
	}

	installEndpointDefaults();
	installLlmModelSelector();
	installWorkflowConfigurator();
}());
