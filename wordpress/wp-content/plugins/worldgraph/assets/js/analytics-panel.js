/**
 * Story Graph Analytics Panel JavaScript.
 *
 * @package WorldGraph
 */

(function ($) {
	'use strict';

	/**
	 * Analytics Panel class.
	 */
	class AnalyticsPanel {
		constructor() {
			this.analyticsRequestId = 0;
			this.networkRequestId = 0;
			this.cacheRequestId = 0;
			this.bindEvents();
			if (this.getProjectId()) {
				this.fetchAnalytics();
			}
		}

		/**
		 * Bind event listeners.
		 */
		bindEvents() {
			$('#fetch-analytics-btn').on('click', () => this.fetchAnalytics(true));
			$('#fetch-network-btn').on('click', () => this.fetchNetwork());
			$('#clear-cache-btn').on('click', () => this.clearCache());
			$('#worldgraph-analytics-project').on('change', () => this.selectProject());
		}

		/**
		 * Get the selected project ID.
		 *
		 * @returns {number} Project post ID.
		 */
		getProjectId() {
			return Number.parseInt($('#worldgraph-analytics-project').val(), 10) || 0;
		}

		/**
		 * Reset results and persist the selected project in the page URL.
		 */
		selectProject() {
			const projectId = this.getProjectId();
			const url = new URL(window.location.href);
			this.analyticsRequestId += 1;
			this.networkRequestId += 1;
			this.cacheRequestId += 1;
			$('#analytics-content, #network-section, #network-content, #analytics-error').hide();
			$('#analytics-content').attr('aria-busy', 'false');
			$('#analytics-loading, #network-loading').hide();
			$('#fetch-analytics-btn').prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Refresh Analysis');
			$('#fetch-network-btn').prop('disabled', false).html('<span class="dashicons dashicons-groups" style="margin-top: 3px;"></span> Fetch Character Network');
			$('#clear-cache-btn').prop('disabled', false).html('<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Clear Cache');
			$('#no-data-state').show();

			if (projectId) {
				url.searchParams.set('project_id', projectId);
			} else {
				url.searchParams.delete('project_id');
			}
			window.history.replaceState({}, '', url);
		}

		/**
		 * Fetch analytics from local Story Graph.
		 *
		 * @param {boolean} forceRefresh Whether to bypass the saved aggregate.
		 */
		fetchAnalytics(forceRefresh = false) {
			const self = this;
			const projectId = this.getProjectId();
			const $btn = $('#fetch-analytics-btn');
			const $loading = $('#analytics-loading');
			const $error = $('#analytics-error');
			const $content = $('#analytics-content');
			const $noData = $('#no-data-state');
			const $network = $('#network-section');
			if (!projectId) {
				this.showError('Select a project to analyze.');
				return;
			}
			const requestId = ++this.analyticsRequestId;

			// Disable button, show loading.
			$btn.prop('disabled', true).text('Loading...');
			$loading.show();
			$error.hide();
			$content.attr('aria-busy', 'true');
			$content.hide();
			$network.hide();
			$noData.hide();

			$.ajax({
				url: worldgraphAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'worldgraph_fetch_analytics',
					nonce: worldgraphAnalytics.nonce,
					project_id: projectId,
					force: forceRefresh ? 1 : 0,
				},
				success(response) {
					if (requestId !== self.analyticsRequestId || projectId !== self.getProjectId()) {
						return;
					}
					if (response.success) {
						self.renderSummary(response.data);
						self.renderDevelopment(response.data);
						self.renderEntityCounts(response.data);
						self.renderMostConnected(response.data);
						self.renderRelationshipDistribution(response.data);
						self.renderIsolatedEntities(response.data);
						$content.show();
						$network.show();

						const source = response.data.cached ? ' (cached)' : ' (local analysis)';
						self.showNotice('Analytics loaded' + source, 'success');
					} else {
						self.showError(response.data.message || worldgraphAnalytics.strings.error);
					}
				},
				error() {
					if (requestId !== self.analyticsRequestId || projectId !== self.getProjectId()) {
						return;
					}
					self.showError(worldgraphAnalytics.strings.fetchError);
				},
				complete() {
					if (requestId !== self.analyticsRequestId || projectId !== self.getProjectId()) {
						return;
					}
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Refresh Analysis');
					$loading.hide();
					$content.attr('aria-busy', 'false');
				},
			});
		}

		/**
		 * Fetch character network from local Story Graph.
		 */
		fetchNetwork() {
			const self = this;
			const projectId = this.getProjectId();
			const $btn = $('#fetch-network-btn');
			const $loading = $('#network-loading');
			const $content = $('#network-content');
			if (!projectId) {
				this.showError('Select a project to analyze.');
				return;
			}
			const requestId = ++this.networkRequestId;

			$btn.prop('disabled', true).text('Loading...');
			$loading.show();
			$content.hide();

			$.ajax({
				url: worldgraphAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'worldgraph_fetch_network',
					nonce: worldgraphAnalytics.nonce,
					project_id: projectId,
				},
				success(response) {
					if (requestId !== self.networkRequestId || projectId !== self.getProjectId()) {
						return;
					}
					if (response.success) {
						self.renderStrongestRelationships(response.data);
						self.renderScenePresence(response.data);
						$content.show();

						const source = response.data.cached ? ' (cached)' : ' (local analysis)';
						self.showNotice('Network data loaded' + source, 'success');
					} else {
						self.showError(response.data.message || worldgraphAnalytics.strings.networkError);
					}
				},
				error() {
					if (requestId !== self.networkRequestId || projectId !== self.getProjectId()) {
						return;
					}
					self.showError(worldgraphAnalytics.strings.networkError);
				},
				complete() {
					if (requestId !== self.networkRequestId || projectId !== self.getProjectId()) {
						return;
					}
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-groups" style="margin-top: 3px;"></span> Fetch Character Network');
					$loading.hide();
				},
			});
		}

		/**
		 * Clear cache.
		 */
		clearCache() {
			const self = this;
			const projectId = this.getProjectId();
			const $btn = $('#clear-cache-btn');
			if (!projectId) {
				this.showError('Select a project first.');
				return;
			}
			const requestId = ++this.cacheRequestId;

			$btn.prop('disabled', true).text('Clearing...');

			$.ajax({
				url: worldgraphAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'worldgraph_clear_cache',
					nonce: worldgraphAnalytics.nonce,
					project_id: projectId,
				},
				success(response) {
					if (requestId !== self.cacheRequestId || projectId !== self.getProjectId()) {
						return;
					}
					if (response.success) {
						self.showNotice(worldgraphAnalytics.strings.cacheCleared, 'success');
					} else {
						self.showError(response.data.message || 'Failed to clear cache.');
					}
				},
				error() {
					if (requestId !== self.cacheRequestId || projectId !== self.getProjectId()) {
						return;
					}
					self.showError('Failed to clear cache.');
				},
				complete() {
					if (requestId !== self.cacheRequestId || projectId !== self.getProjectId()) {
						return;
					}
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Clear Cache');
				},
			});
		}

		/**
		 * Render summary cards.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderSummary(data) {
			$('#total-entities').text(data.total_entities || 0);
			$('#total-relationships').text(data.total_relationships || 0);
			$('#network-density').text(((data.density || 0) * 100).toFixed(2) + '%');
			$('#isolated-count').text((data.isolated_entities || []).length);
		}

		/**
		 * Render evidence-based story development prompts.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderDevelopment(data) {
			const development = data.development || {};
			const phase = development.phase || {};
			const opportunities = Array.isArray(development.opportunities)
				? development.opportunities.filter(opportunity => opportunity && typeof opportunity === 'object' && !Array.isArray(opportunity)).slice(0, 12)
				: [];
			const elements = Array.isArray(development.elements_to_develop)
				? development.elements_to_develop.filter(element => element && typeof element === 'object' && !Array.isArray(element)).slice(0, 12)
				: [];
			const reportedTotal = Number.parseInt(development.total_opportunities, 10);
			const totalOpportunities = Number.isSafeInteger(reportedTotal) && reportedTotal >= opportunities.length
				? reportedTotal
				: opportunities.length;
			const opportunityMap = new Map(opportunities.map(opportunity => [opportunity.id, opportunity]));
			const $opportunities = $('#development-opportunities');
			const $elements = $('#elements-to-develop');

			$('#development-phase').text(phase.label || this.string('reviewCoverage', 'Review graph coverage'));
			$('#development-summary').text(phase.summary || this.string('reviewSummary', 'Review the graph and choose the question that feels useful now.'));
			let resultSummary = this.string('analysisEmpty', 'Analysis complete. No structural prompts surfaced.');
			if (development.has_more && totalOpportunities > opportunities.length) {
				resultSummary = this.formatString(
					'analysisTruncated',
					'Analysis complete. Showing %1$d of %2$d prompts. Refine the graph, then analyze again to bring other elements forward.',
					[opportunities.length, totalOpportunities]
				);
			} else if (opportunities.length === 1) {
				resultSummary = this.string('analysisOne', 'Analysis complete. One development prompt surfaced.');
			} else if (opportunities.length) {
				resultSummary = this.formatString(
					'analysisMany',
					'Analysis complete. %1$d development prompts surfaced.',
					[opportunities.length]
				);
			}
			$('#development-result-count').text(resultSummary);
			$opportunities.empty();
			$elements.empty();

			if (opportunities.length === 0) {
				$opportunities.append(
					$('<div>', { class: 'worldgraph-development-empty' }).append(
						$('<strong>').text(this.string('noPromptsTitle', 'No structural prompts surfaced.')),
						$('<p>').text(this.string('noPromptsBody', 'The current graph covers the foundational connections checked here. Use Dramaturgy for a closer reading of movement, stakes, and audience experience.'))
					)
				);
			} else {
				opportunities.forEach(opportunity => {
					$opportunities.append(this.buildOpportunityCard(opportunity));
				});
			}

			if (elements.length === 0) {
				$elements.append($('<p>', { class: 'worldgraph-element-empty' }).text(this.string('noElements', 'No existing elements are singled out by these graph checks.')));
				return;
			}

			elements.forEach(element => {
				const related = (Array.isArray(element.opportunity_ids) ? element.opportunity_ids : [])
					.map(id => opportunityMap.get(id))
					.filter(Boolean);
				const $item = $('<article>', { class: 'worldgraph-element-card' });
				const $identity = $('<div>');

				$identity.append(
					$('<span>', { class: 'worldgraph-element-type' }).text(this.entityLabel(element.type)),
					$('<h4>').text(element.name || this.string('untitledElement', 'Untitled element'))
				);
				if (related[0]) {
					$identity.append($('<p>').text(related[0].evidence || related[0].title || this.string('elementEvidenceFallback', 'Open this element to develop its graph connections.')));
				}
				$item.append($identity);

				const editUrl = this.editUrl(element.id);
				if (editUrl) {
					$item.append($('<a>', {
						class: 'button',
						href: editUrl,
						text: this.string('openElement', 'Open element'),
						'aria-label': this.formatString('openEntity', 'Open %1$s', [element.name || this.string('storyElement', 'Story element')]),
					}));
				}
				$elements.append($item);
			});
		}

		/**
		 * Build one Development Compass prompt card.
		 *
		 * @param {Object} opportunity Opportunity DTO.
		 * @returns {JQuery} Prompt card.
		 */
		buildOpportunityCard(opportunity) {
			const priority = 'high' === opportunity.priority ? 'high' : 'medium';
			const $card = $('<article>', {
				class: 'worldgraph-opportunity-card worldgraph-priority-' + priority,
			});
			const $meta = $('<div>', { class: 'worldgraph-opportunity-meta' }).append(
				$('<span>', { class: 'worldgraph-opportunity-priority' }).text(this.string(priority + 'Priority', priority + ' priority')),
				$('<span>', { class: 'worldgraph-opportunity-type' }).text(this.opportunityLabel(opportunity.type))
			);

			$card.append(
				$meta,
				$('<h3>').text(opportunity.title || this.string('developmentQuestion', 'Development question')),
				$('<p>', { class: 'worldgraph-opportunity-evidence' }).append(
					$('<strong>').text(this.string('graphEvidence', 'Graph evidence: ')),
					document.createTextNode(opportunity.evidence || this.string('noGraphDetail', 'No supporting graph detail was returned.'))
				),
				$('<blockquote>').text(opportunity.question || this.string('creativeQuestion', 'What would you like to discover here?'))
			);

			const $actions = $('<div>', { class: 'worldgraph-opportunity-actions' });
			if (opportunity.entity && opportunity.entity.id) {
				const editUrl = this.editUrl(opportunity.entity.id);
				if (editUrl) {
					$actions.append($('<a>', {
						class: 'button',
						href: editUrl,
						text: this.formatString('openEntity', 'Open %1$s', [this.entityLabel(opportunity.entity.type)]),
					}));
				}
			}

			const createType = opportunity.suggested_entity_type || '';
			const createUrl = (worldgraphAnalytics.createUrls || {})[createType];
			if (createUrl) {
				$actions.append($('<a>', {
					class: 'button button-primary',
					href: createUrl,
					text: this.formatString('draftEntity', 'Draft a %1$s', [this.entityLabel(createType)]),
				}));
			}

			if ($actions.children().length) {
				$card.append($actions);
			}
			return $card;
		}

		/**
		 * Build an edit URL without trusting response-provided markup.
		 *
		 * @param {number} postId WordPress post ID.
		 * @returns {string} Edit URL.
		 */
		editUrl(postId) {
			const id = Number.parseInt(postId, 10);
			if (!id || !worldgraphAnalytics.editUrl) {
				return '';
			}
			return worldgraphAnalytics.editUrl.replace('__POST_ID__', String(id));
		}

		/**
		 * Get a human-readable Story Graph entity label.
		 *
		 * @param {string} type Entity type.
		 * @returns {string} Entity label.
		 */
		entityLabel(type) {
			const labels = worldgraphAnalytics.entityLabels || {};
			return labels[type] || String(type || this.string('storyElement', 'Story element')).replace(/^worldgraph_/, '').replace(/_/g, ' ');
		}

		/**
		 * Get a human-readable development rule label.
		 *
		 * @param {string} type Opportunity type.
		 * @returns {string} Rule label.
		 */
		opportunityLabel(type) {
			const labels = {
				missing_foundation: this.string('foundation', 'Foundation'),
				element_without_scene: this.string('exposure', 'Exposure'),
				scene_missing_character: this.string('sceneFocus', 'Scene focus'),
				scene_missing_location: this.string('sceneSetting', 'Scene setting'),
				next_story_event: this.string('nextEvent', 'Next event'),
			};
			return labels[type] || this.string('development', 'Development');
		}

		/**
		 * Read a localized UI string with a defensive fallback.
		 *
		 * @param {string} key String key.
		 * @param {string} fallback English fallback.
		 * @returns {string} Localized string.
		 */
		string(key, fallback) {
			return (worldgraphAnalytics.strings || {})[key] || fallback;
		}

		/**
		 * Replace numbered WordPress-style placeholders in a localized string.
		 *
		 * @param {string} key String key.
		 * @param {string} fallback English fallback.
		 * @param {Array} values Replacement values.
		 * @returns {string} Formatted localized string.
		 */
		formatString(key, fallback, values) {
			let output = this.string(key, fallback);
			values.forEach((value, index) => {
				output = output.replace(new RegExp('%' + (index + 1) + '\\$[sd]', 'g'), String(value));
			});
			return output;
		}

		/**
		 * Render entity counts.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderEntityCounts(data) {
			const $container = $('#entity-counts');
			$container.empty();

			const counts = data.entity_counts || {};
			const labels = {
				'worldgraph_project': 'Projects',
				'worldgraph_character': 'Characters',
				'worldgraph_location': 'Locations',
				'worldgraph_scene': 'Scenes',
				'worldgraph_shot': 'Shots',
				'worldgraph_asset': 'Assets',
				'worldgraph_prop': 'Props',
				'worldgraph_episode': 'Episodes',
				'worldgraph_editorial': 'Editorial',
			};

			for (const [type, count] of Object.entries(counts)) {
				const label = labels[type] || type;
				$container.append(
					'<div class="worldgraph-entity-count">' +
					'<span class="worldgraph-entity-count-number">' + count + '</span>' +
					'<span class="worldgraph-entity-count-label">' + label + '</span>' +
					'</div>'
				);
			}
		}

		/**
		 * Render most connected entities.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderMostConnected(data) {
			const $tbody = $('#most-connected-body');
			$tbody.empty();

			const entities = data.most_connected || [];
			if (entities.length === 0) {
				$tbody.append('<tr><td colspan="3">No connected entities found.</td></tr>');
				return;
			}

			entities.forEach(entity => {
				const name = entity.name || 'Unknown';
				const type = entity.type || '';
				const connections = entity.connection_count || 0;

				$tbody.append(
					'<tr>' +
					'<td>' + $('<div>').text(name).html() + '</td>' +
					'<td>' + $('<div>').text(type).html() + '</td>' +
					'<td>' + connections + '</td>' +
					'</tr>'
				);
			});
		}

		/**
		 * Render relationship type distribution.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderRelationshipDistribution(data) {
			this.displayDistribution(data.relationship_distribution || {});
		}

		/**
		 * Compute distribution from edges.
		 *
		 * @param {Array} edges The relationship edges.
		 * @returns {Object} Distribution object.
		 */
		computeDistribution(edges) {
			const dist = {};
			edges.forEach(edge => {
				const type = edge.type || 'unknown';
				dist[type] = (dist[type] || 0) + 1;
			});
			return dist;
		}

		/**
		 * Display distribution.
		 *
		 * @param {Object} distribution The distribution object.
		 */
		displayDistribution(distribution) {
			const $container = $('#relationship-distribution');
			$container.empty();

			for (const [type, count] of Object.entries(distribution)) {
				$container.append(
					'<div class="worldgraph-distribution-item">' +
					'<span class="worldgraph-distribution-type">' + $('<div>').text(type).html() + '</span>' +
					'<span class="worldgraph-distribution-count">' + count + '</span>' +
					'</div>'
				);
			}
		}

		/**
		 * Render isolated entities.
		 *
		 * @param {Object} data The analytics data.
		 */
		renderIsolatedEntities(data) {
			const $tbody = $('#isolated-body');
			$tbody.empty();

			const entities = data.isolated_entities || [];
			if (entities.length === 0) {
				$tbody.append('<tr><td colspan="3">No isolated entities found. Great connectivity!</td></tr>');
				return;
			}

			entities.forEach(entity => {
				const name = entity.name || 'Unknown';
				const type = entity.type || '';

				$tbody.append(
					'<tr>' +
					'<td>' + $('<div>').text(name).html() + '</td>' +
					'<td>' + $('<div>').text(type).html() + '</td>' +
					'<td>No relationships</td>' +
					'</tr>'
				);
			});
		}

		/**
		 * Render strongest character relationships.
		 *
		 * @param {Object} data The network data.
		 */
		renderStrongestRelationships(data) {
			const $tbody = $('#strongest-body');
			$tbody.empty();

			const relationships = data.strongest_relationships || [];
			if (relationships.length === 0) {
				$tbody.append('<tr><td colspan="4">No character relationships found.</td></tr>');
				return;
			}

			relationships.forEach(rel => {
				const charA = rel.character_a || 'Unknown';
				const charB = rel.character_b || 'Unknown';
				const relationship = rel.relationship || 'Related';
				const cooccurrences = rel.cooccurrences || 0;

				$tbody.append(
					'<tr>' +
					'<td>' + $('<div>').text(charA).html() + '</td>' +
					'<td>' + $('<div>').text(charB).html() + '</td>' +
					'<td>' + $('<div>').text(relationship).html() + '</td>' +
					'<td>' + cooccurrences + '</td>' +
					'</tr>'
				);
			});
		}

		/**
		 * Render character scene presence.
		 *
		 * @param {Object} data The network data.
		 */
		renderScenePresence(data) {
			const $tbody = $('#scene-presence-body');
			$tbody.empty();

			const presence = data.character_scene_presence || [];
			if (presence.length === 0) {
				$tbody.append('<tr><td colspan="3">No scene presence data.</td></tr>');
				return;
			}

			presence.forEach(char => {
				const name = char.name || 'Unknown';
				const scenes = char.scenes || 0;
				const shots = char.shots || 0;

				$tbody.append(
					'<tr>' +
					'<td>' + $('<div>').text(name).html() + '</td>' +
					'<td>' + scenes + '</td>' +
					'<td>' + shots + '</td>' +
					'</tr>'
				);
			});
		}

		/**
		 * Show error notice.
		 *
		 * @param {string} message The error message.
		 */
		showError(message) {
			const $error = $('#analytics-error');
			$('#analytics-error-message').text(message);
			$error.show();
			setTimeout(() => $error.fadeOut(), 5000);
		}

		/**
		 * Show success notice.
		 *
		 * @param {string} message The success message.
		 */
		showNotice(message, type) {
			const notice = $('<div>', {
				class: 'notice notice-success is-dismissible',
				role: 'status',
				'aria-live': 'polite',
			}).append($('<p>').text(message));
			$('.wrap h1').after(notice);
			setTimeout(() => notice.fadeOut(), 3000);
		}
	}

	// Initialize when DOM is ready.
	$(document).ready(() => {
		window.worldgraphAnalyticsPanel = new AnalyticsPanel();
	});

})(jQuery);
