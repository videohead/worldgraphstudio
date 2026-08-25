/* global worldgraphDramaturgyTool */
(function () {
	'use strict';

	const source = document.getElementById('worldgraph-dramaturgy-source');
	const lens = document.getElementById('worldgraph-dramaturgy-lens');
	const question = document.getElementById('worldgraph-dramaturgy-question');
	const output = document.getElementById('worldgraph-dramaturgy-output');
	const run = document.getElementById('worldgraph-run-dramaturgy');
	const save = document.getElementById('worldgraph-save-dramaturgy');
	const status = document.getElementById('worldgraph-dramaturgy-status');

	if (!source || !lens || !question || !output || !run || !save || !status) {
		return;
	}

	function request(action, fields) {
		const body = new URLSearchParams({ action, nonce: worldgraphDramaturgyTool.nonce, ...fields });
		return fetch(worldgraphDramaturgyTool.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body,
		}).then((response) => response.json());
	}

	run.addEventListener('click', function () {
		const submittedQuestion = question.value.trim();
		run.disabled = true;
		save.disabled = true;
		status.textContent = worldgraphDramaturgyTool.strings.running;
		request('worldgraph_run_dramaturgy', { source_id: source.value, lens: lens.value, question: submittedQuestion })
			.then((response) => {
				if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : worldgraphDramaturgyTool.strings.error);
				output.value = response.data.analysis;
				save.disabled = false;
				status.textContent = response.data.focused ? worldgraphDramaturgyTool.strings.readyFocused : worldgraphDramaturgyTool.strings.ready;
			})
			.catch((error) => { status.textContent = error.message; })
			.finally(() => { run.disabled = false; });
	});

	save.addEventListener('click', function () {
		save.disabled = true;
		status.textContent = worldgraphDramaturgyTool.strings.saving;
		request('worldgraph_save_dramaturgy', { source_id: source.value, analysis: output.value })
			.then((response) => {
				if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : worldgraphDramaturgyTool.strings.error);
				status.textContent = worldgraphDramaturgyTool.strings.saved;
			})
			.catch((error) => { status.textContent = error.message; save.disabled = false; });
	});
})();
