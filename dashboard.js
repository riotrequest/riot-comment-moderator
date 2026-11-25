(() => {
	const root = document.querySelector('[data-rcm]');
	if (!root) return;
	const find = (selector) => root.querySelector(selector);
	const settings = find('[data-settings]');
	const provider = find('[data-provider]');
	const panels = [...root.querySelectorAll('[data-provider-panel]')];
	const modelPanels = [...root.querySelectorAll('[data-provider-model]')];
	const toast = find('[data-toast]');
	const outcome = find('[data-test-outcome]');
	const batchMode = find('[data-batch-mode]');
	const batchActions = find('[data-batch-actions]');
	let state = JSON.parse(root.dataset.state);
	let dirty = false;
	let busy = false;
	let runningNow = false;
	let revision = 0;
	let lastPoll = 0;
	let polling = false;
	let nextChunkAt = 0;

	const write = (selector, message) => {
		const field = find(selector);
		if (field.textContent !== message) field.textContent = message;
	};
	const time = (epoch) => new Date(epoch * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
	for (const toggle of root.querySelectorAll('[data-full-auto]')) {
		const actions = toggle.form.querySelector('[data-individual-actions]');
		const sync = () => { actions.hidden = toggle.checked; };
		toggle.addEventListener('change', sync);
		sync();
	}

	function syncBatchMode() {
		const mode = batchMode.value;
		batchActions.hidden = mode !== 'selected';
		if (!batchActions.hidden) batchActions.open = true;
		const descriptions = {
			full_auto: 'Approve, mark spam, or move each comment to Trash after one review, including older withheld comments in the selected range.',
			selected: 'Apply the actions you choose and save recommendations for the rest.',
			recommend: 'Save verdicts for your review. Comment statuses stay as they are.'
		};
		write('[data-mode-detail]', descriptions[mode] || descriptions.full_auto);
		const labels = {full_auto: 'Start Full Auto', selected: 'Start automatic batch', recommend: 'Get recommendations'};
		write('[data-start]', labels[mode] || 'Start batch');
	}

	batchMode.addEventListener('change', () => {
		find('[data-skip-reviewed]').checked = batchMode.value !== 'full_auto';
		syncBatchMode();
	});

	function syncProvider() {
		const keyProvider = provider.selectedOptions[0].dataset.keyProvider;
		for (const panel of panels) {
			panel.hidden = panel.dataset.providerPanel !== keyProvider;
		}
		for (const panel of modelPanels) {
			panel.hidden = panel.dataset.providerModel !== provider.value;
			const custom = panel.querySelector('[data-custom-model]');
			if (!custom) continue;
			const model = panel.querySelector('[data-model-preset]');
			const customInput = custom.querySelector('input');
			custom.hidden = model.value !== 'custom';
			customInput.required = !panel.hidden && !custom.hidden;
			const effort = panel.querySelector('[data-reasoning-effort]');
			if (!effort) continue;
			const slug = custom.hidden ? model.value : customInput.value.trim();
			const option = [...model.options].find(candidate => candidate.value === slug) || model.selectedOptions[0];
			const supported = (option.dataset.efforts || '').split(' ');
			const defaultLevel = option.dataset.defaultEffort && [...effort.options].find(level => level.value === option.dataset.defaultEffort);
			effort.options[0].textContent = defaultLevel ? 'Default (' + defaultLevel.textContent + ')' : 'Default';
			const selected = effort.value;
			for (const level of effort.options) {
				level.hidden = level.disabled = level.value !== '' && !supported.includes(level.value);
			}
			if (selected !== '' && !supported.includes(selected)) effort.value = '';
		}
	}

	function render() {
		const blocked = Boolean(state.blocker || dirty || busy);
		const reviewing = runningNow && state.running && state.state === 'queued';
		find('[data-run-state]').dataset.runState = reviewing ? 'working' : state.state;
		write('[data-run-label]', reviewing ? 'Reviewing comments' : state.label);
		write('[data-run-detail]', reviewing ? 'Checking comments. Counts update as reviews finish.' : state.detail);
		write('[data-progress-label]', state.total ? state.processed + ' of ' + state.total + ' processed' : 'Batch progress');
		write('[data-pending]', state.pending);
		const progress = find('[data-progress]');
		progress.max = Math.max(state.total, 1);
		progress.value = state.processed;
		for (const [name, value] of Object.entries(state.counts)) write('[data-count="' + name + '"]', String(value));
		find('[data-count="withheld"]').parentElement.hidden = !state.counts.withheld;
		const timing = [];
		if (state.last) timing.push('Last activity ' + time(state.last));
		if (state.running && state.cooldown > state.now) timing.push('Cooldown until ' + time(state.cooldown));
		write('[data-timing]', timing.join(' · '));

		find('[data-running]').hidden = !state.running;
		find('[data-start-panel]').hidden = state.running;
		find('[data-resumable]').hidden = !state.resumable;
		find('[data-start]').disabled = blocked;
		find('[data-resume]').disabled = blocked;
		find('[data-test-button]').disabled = dirty || busy;
		const actionText = state.actions.length ? state.actions.join(' · ') : 'Recommendations only';
		write('[data-active-actions]', actionText);
		write('[data-paused-actions]', actionText);
		write('[data-batch-blocker]', dirty ? 'Save settings before starting a batch.' : (state.blocker || 'Uses ' + state.provider + ' · ' + state.model));
		for (const key of root.querySelectorAll('[data-key]')) key.placeholder = state.keys[key.dataset.key] ? 'Configured' : 'Enter key';
	}

	function tell(message, error = false) {
		toast.textContent = message;
		toast.hidden = !message;
		toast.dataset.error = error ? '1' : '0';
	}

	async function request(action, form) {
		const body = form ? new FormData(form) : new FormData();
		body.delete('_wpnonce');
		body.delete('_wp_http_referer');
		body.set('action', 'rcm_dashboard');
		body.set('_ajax_nonce', root.dataset.nonce);
		body.set('rcm_action', action);
		if (action === 'run') body.set('rcm_batch_id', state.batch_id);
		const response = await fetch(root.dataset.ajax, {method: 'POST', credentials: 'same-origin', body});
		const payload = await response.json();
		if (!response.ok || !payload.success) throw new Error(payload.data?.message || 'The dashboard request failed. Try again.');
		return payload.data;
	}

	async function refresh() {
		if (polling || document.hidden || busy) return;
		const interval = state.running || runningNow ? 2800 : 12000;
		if (Date.now() - lastPoll < interval) return;
		lastPoll = Date.now();
		polling = true;
		const generation = revision;
		try {
			const data = await request('status');
			if (revision === generation && !busy) {
				state = data.dashboard;
				render();
			}
		} catch {
			// Keep the last confirmed counts. The next poll will retry.
		} finally {
			polling = false;
		}
	}

	async function runChunk() {
		if (document.hidden || busy || polling || runningNow || !state.running || state.working || dirty ||
			state.blocker || state.cooldown > state.now || Date.now() < nextChunkAt) return;
		runningNow = true;
		const generation = ++revision;
		render();
		try {
			const data = await request('run');
			nextChunkAt = Date.now() + (data.error ? 15000 : 850);
			if (generation === revision && !busy) {
				++revision;
				state = data.dashboard;
				const needsAttention = data.error || state.state === 'paused' || state.state === 'finished';
				tell(needsAttention ? data.message : '', data.error || state.state === 'paused');
			}
		} catch {
			nextChunkAt = Date.now() + 15000;
			if (generation === revision) tell('The connection was interrupted. Riot will retry automatically.', true);
		} finally {
			runningNow = false;
			lastPoll = 0;
			render();
		}
	}

	async function drive() {
		await refresh();
		runChunk();
	}

	settings.addEventListener('input', () => {
		dirty = true;
		syncProvider();
		render();
	});
	settings.addEventListener('change', () => {
		dirty = true;
		syncProvider();
		render();
	});
	for (const form of root.querySelectorAll('[data-rcm-form]')) {
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (busy) return;
			const action = form === settings ? 'save' : event.submitter?.value;
			if (!action) return;
			busy = true;
			++revision;
			const submitter = event.submitter;
			if (submitter) submitter.disabled = true;
			tell({save: 'Saving settings…', test: 'Testing the saved provider…'}[action] || 'Updating batch…');
			let kick = false;
			try {
				const data = await request(action, form);
				++revision;
				state = data.dashboard;
				tell(data.message, data.error);
				if (action === 'save' && !data.error) {
					dirty = false;
					for (const key of settings.querySelectorAll('[data-key]')) key.value = '';
					for (const forget of settings.querySelectorAll('input[name$="_forget]"]')) forget.checked = false;
				}
				if (data.test) {
					outcome.hidden = false;
					outcome.dataset.error = data.error ? '1' : '0';
					outcome.textContent = data.test.model + ' · ' + data.test.reason;
				}
				kick = !data.error && (action === 'start' || action === 'resume');
			} catch (error) {
				tell(error.message, true);
			} finally {
				busy = false;
				if (submitter) submitter.disabled = false;
				render();
			}
			if (kick) {
				nextChunkAt = 0;
				runChunk();
			}
		});
	}
	root.classList.add('rcm-ready');
	syncProvider();
	syncBatchMode();
	render();
	setInterval(drive, 1000);
})();
