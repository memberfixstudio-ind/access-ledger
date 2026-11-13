(function () {
	'use strict';
	const root = document.getElementById('access-ledger');
	if (!root) return;
	const { __, sprintf, _n } = window.wp.i18n;
	const config = window.accessLedger;
	const $ = (id) => document.getElementById('al-' + id);
	let dashboard = null;
	let scanTimer = null;
	let scanStep = null;
	let scanBusy = false;
	let pollingPaused = false;
	let dashboardSequence = 0;
	let reportsSequence = 0;
	let reportsBusy = false;
	let reportsExpanded = false;
	let exportBusy = false;
	let orderSequence = 0;
	let currentOrder = null;
	let orderTrigger = null;
	let dialogSequence = 0;
	let dialogTrigger = null;
	let preview = null;
	let previewId = null;
	let undoAudit = null;
	let mutationBusy = false;
	const issueNames = {
		identity_mismatch: __('Customer details differ', 'access-ledger'),
		missing_permission: __('Permission missing', 'access-ledger'),
		expired: __('Access expired', 'access-ledger'),
		exhausted: __('Download limit reached', 'access-ledger'),
		removed_file: __('File removed', 'access-ledger'),
		order_blocked: __('Order does not allow downloads', 'access-ledger'),
		refunded: __('Refund recorded', 'access-ledger'),
		duplicate_permission: __('Duplicate permission', 'access-ledger'),
		orphan_product: __('Product missing', 'access-ledger'),
		guest_login: __('Guest access needs review', 'access-ledger'),
		no_downloads: __('No downloadable items', 'access-ledger')
	};

	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}
	function button(text, callback, className) {
		const node = el('button', 'al-button ' + (className || ''), text);
		node.type = 'button';
		node.addEventListener('click', callback);
		return node;
	}
	function badge(text, tone) { return el('span', 'al-badge' + (tone ? ' al-badge-' + tone : ''), text); }
	function count(value) { return Number.isFinite(Number(value)) && value !== null && value !== '' ? Number(value).toLocaleString() : '—'; }
	function date(value) {
		if (!value) return '—';
		if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return String(value);
		let input = value;
		if (typeof value === 'number') input = value * 1000;
		if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) input = value.replace(' ', 'T') + 'Z';
		const parsed = new Date(input);
		return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
	}
	function message(error) {
		if (error && (error.code === 'rest_cookie_invalid_nonce' || error.data?.status === 401)) return __('Your session has expired. Reload this page and try again.', 'access-ledger');
		return error && typeof error.message === 'string' ? error.message : __('The request could not be completed. Check your connection and try again.', 'access-ledger');
	}
	function showError(error) { $('error').textContent = message(error); $('error').hidden = false; }
	function clearError() { $('error').hidden = true; $('error').textContent = ''; }
	function notify(text) { $('notice').textContent = text; }
	function announce(text) { $('announcement').textContent = text; }
	function api(path, method = 'GET', data, parse = true) {
		return window.wp.apiFetch({ path: '/access-ledger/v1/' + path, method, data, parse, headers: { 'X-WP-Nonce': config.nonce } });
	}
	function empty(title, detail) {
		const node = el('div', 'al-empty');
		node.append(el('strong', '', title), el('p', '', detail));
		return node;
	}
	function table(headers, caption) {
		const node = el('table', 'al-table');
		node.setAttribute('role', 'table');
		node.append(el('caption', 'al-sr-only', caption));
		const head = el('thead');
		const row = el('tr');
		headers.forEach((label) => { const cell = el('th', '', label); cell.scope = 'col'; row.append(cell); });
		head.append(row);
		node.append(head, el('tbody'));
		return node;
	}
	function cell(row, label, content) {
		const node = el('td');
		node.dataset.label = label;
		if (content instanceof Node) node.append(content); else node.textContent = String(content ?? '—');
		row.append(node);
		return node;
	}
	function repairable(issue) { return issue.code === 'identity_mismatch' && issue.repairable === true; }
	function running() { return dashboard?.scan?.status === 'running'; }

	function renderScan() {
		const scan = dashboard?.scan;
		const active = running();
		const labels = {
			running: __('Scanning', 'access-ledger'), completed: __('Complete', 'access-ledger'),
			cancelled: __('Cancelled', 'access-ledger'), failed: __('Stopped with an error', 'access-ledger')
		};
		$('scan-status').textContent = scan ? (labels[scan.status] || scan.status) : __('Not started', 'access-ledger');
		$('scan-status').className = 'al-badge' + (scan?.status === 'failed' ? ' al-badge-error' : active ? ' al-badge-success' : '');
		$('days').disabled = !dashboard || active || scanBusy;
		$('scan-start').disabled = !dashboard || active || scanBusy;
		$('scan-start').textContent = scanBusy ? __('Please wait…', 'access-ledger') : __('Start scan', 'access-ledger');
		$('scan-cancel').hidden = !active;
		$('scan-cancel').disabled = scanBusy;
		$('progress').hidden = !active;
		if (!scan) {
			$('scan-summary').textContent = __('No scan yet. Choose a date range to begin.', 'access-ledger');
			$('scan-date').textContent = '';
			return;
		}
		$('scan-summary').textContent = sprintf(_n('%s order checked.', '%s orders checked.', Number(scan.processed), 'access-ledger'), count(scan.processed)) + (active ? ' ' + __('Working in small batches. You can cancel at any time.', 'access-ledger') : '');
		if (scan.error) $('scan-summary').textContent += ' ' + String(scan.error);
		$('scan-date').textContent = scan.since && scan.cutoff
			? sprintf(__('Order window: %1$s – %2$s', 'access-ledger'), date(scan.since), date(scan.cutoff))
			: sprintf(__('Last %1$s days · Started %2$s', 'access-ledger'), count(scan.days), date(scan.started_at));
	}
	function renderReports() {
		if (!dashboard) return;
		const query = $('search').value.trim().toLocaleLowerCase();
		const type = $('issue-filter').value;
		const reports = dashboard.reports.filter((report) => {
			const issues = report.issues || [];
			const haystack = [report.order_id, report.order_number, report.customer_email, ...(report.permissions || []).map((p) => p.product_name)].join(' ').toLocaleLowerCase();
			return (!query || haystack.includes(query)) && (type === 'all' || issues.some((i) => i.code === type)) && ($('filter').value !== 'repairable' || issues.some(repairable));
		});
		$('report-count').textContent = sprintf(__('%1$s matching / %2$s loaded orders', 'access-ledger'), count(reports.length), count(dashboard.reports.length));
		$('load-older').hidden = !dashboard.pagination.has_more;
		$('load-older').disabled = reportsBusy || scanBusy;
		$('load-older').textContent = reportsBusy ? __('Loading…', 'access-ledger') : __('Load older findings', 'access-ledger');
		$('older-status').textContent = dashboard.pagination.has_more ? __('Older findings are available. Search and filters cover only loaded orders.', 'access-ledger') : __('No older findings in this view. Refresh to check for newer saved findings.', 'access-ledger');
		const target = $('reports-content');
		target.setAttribute('aria-busy', String(reportsBusy));
		if (!reports.length) {
			target.replaceChildren(empty(
				dashboard.reports.length ? __('No matching orders', 'access-ledger') : __('No saved findings', 'access-ledger'),
				dashboard.reports.length ? __('Try another search or filter, or load older findings if available.', 'access-ledger') : dashboard.scan ? __('No issue reports are saved for this scan so far. Orders without findings are not listed. Use a live order check for current details.', 'access-ledger') : __('Run a scan to collect orders that need review. You can also check one order now.', 'access-ledger')
			));
			return;
		}
		const labels = [__('Order', 'access-ledger'), __('Customer', 'access-ledger'), __('Findings', 'access-ledger'), __('Checked', 'access-ledger'), __('Action', 'access-ledger')];
		const node = table(labels, __('Loaded orders with findings; saved snapshots from one scan.', 'access-ledger'));
		reports.forEach((report) => {
			const row = el('tr');
			cell(row, labels[0], el('span', 'al-order-number', '#' + report.order_number)).append(el('span', 'al-cell-sub', report.status));
			cell(row, labels[1], report.customer_email || __('No billing email', 'access-ledger'));
			const issues = report.issues || [];
			cell(row, labels[2], badge(sprintf(_n('%s finding', '%s findings', issues.length, 'access-ledger'), count(issues.length)), 'warning'));
			cell(row, labels[3], date(report.checked_at));
			const action = button(__('Explain', 'access-ledger'), (event) => loadOrder(report.order_id, event.currentTarget));
			action.setAttribute('aria-label', sprintf(__('Explain order %s', 'access-ledger'), report.order_number));
			cell(row, labels[4], action);
			node.tBodies[0].append(row);
		});
		target.replaceChildren(node);
	}
	function renderHistory() {
		const target = $('history-content');
		target.setAttribute('aria-busy', 'false');
		if (!dashboard.audits.length) {
			target.replaceChildren(empty(__('No email corrections yet', 'access-ledger'), __('Confirmed corrections appear here with the order, operator and an option to review undo.', 'access-ledger')));
			return;
		}
		const labels = [__('Order', 'access-ledger'), __('Correction', 'access-ledger'), __('Recorded', 'access-ledger'), __('Status', 'access-ledger'), __('Action', 'access-ledger')];
		const node = table(labels, __('Recent email corrections', 'access-ledger'));
		dashboard.audits.forEach((audit) => {
			const row = el('tr');
			cell(row, labels[0], '#' + audit.order_id);
			cell(row, labels[1], sprintf(__('Correction #%s', 'access-ledger'), audit.id)).append(el('span', 'al-cell-sub', sprintf(__('Permission %1$s · User %2$s', 'access-ledger'), audit.permission_id, audit.actor_id)));
			cell(row, labels[2], date(audit.created_at));
			cell(row, labels[3], badge(audit.undone_at ? __('Undone', 'access-ledger') : __('Applied', 'access-ledger'), audit.undone_at ? '' : 'success'));
			if (audit.undone_at) cell(row, labels[4], date(audit.undone_at));
			else {
				const action = button(__('Review undo', 'access-ledger'), (event) => openUndo(audit, event.currentTarget));
				action.setAttribute('aria-label', sprintf(__('Review undo for correction %s', 'access-ledger'), audit.id));
				cell(row, labels[4], action);
			}
			node.tBodies[0].append(row);
		});
		target.replaceChildren(node);
	}
	async function refreshDashboard(resetReports = false) {
		const sequence = ++dashboardSequence;
		const result = await api('dashboard');
		if (sequence !== dashboardSequence) return;
		if (!result || !Array.isArray(result.reports) || !Array.isArray(result.audits) || !result.totals || !result.pagination || result.pagination.scan_id !== (result.scan?.id ?? null)) throw new Error(__('The dashboard response was incomplete. Refresh to try again.', 'access-ledger'));
		const previousStatus = dashboard?.scan?.status;
		const scanChanged = dashboard?.pagination.scan_id !== result.pagination.scan_id;
		const replaceReports = resetReports || scanChanged || !reportsExpanded;
		if (!replaceReports) {
			result.reports = dashboard.reports;
			result.pagination = dashboard.pagination;
		} else {
			reportsSequence++;
			reportsBusy = false;
			reportsExpanded = false;
		}
		dashboard = result;
		['orders', 'issues', 'repairable'].forEach((key) => { $('total-' + key).textContent = count(result.totals[key]); });
		['export', 'export-csv'].forEach((id) => { $(id).disabled = exportBusy; });
		renderScan();
		if (resetReports || scanChanged || !$('reports-content').contains(document.activeElement)) renderReports();
		if (!$('history-content').contains(document.activeElement)) renderHistory();
		if (previousStatus === 'running' && !running()) announce(__('The scan has stopped. The saved findings are updated.', 'access-ledger'));
		scheduleScan();
	}
	async function loadOlder() {
		if (!dashboard?.pagination.has_more || reportsBusy || scanBusy) return;
		const { scan_id, next_before_id } = dashboard.pagination;
		const sequence = ++reportsSequence;
		reportsBusy = true;
		reportsExpanded = true;
		clearError();
		renderReports();
		try {
			const page = await api('reports?' + new URLSearchParams({ scan_id, before_id: next_before_id }));
			if (sequence !== reportsSequence || dashboard.pagination.scan_id !== scan_id) return;
			if (page?.scan_id !== scan_id || !Array.isArray(page.reports) || page.reports.length > 50 || typeof page.has_more !== 'boolean' || (page.has_more && (!Number.isSafeInteger(page.next_before_id) || page.next_before_id < 1 || page.next_before_id >= next_before_id))) throw new Error(__('The findings response was incomplete. Refresh to try again.', 'access-ledger'));
			const loaded = new Set(dashboard.reports.map((report) => report.order_id));
			dashboard.reports.push(...page.reports.filter((report) => !loaded.has(report.order_id)));
			dashboard.pagination = { scan_id, has_more: page.has_more, next_before_id: page.next_before_id };
			announce(sprintf(_n('%s older order loaded.', '%s older orders loaded.', page.reports.length, 'access-ledger'), count(page.reports.length)));
		} catch (error) {
			if (sequence !== reportsSequence) return;
			if (error.code === 'access_ledger_scan_changed') {
				dashboard.reports = [];
				dashboard.pagination.has_more = false;
				dashboard.pagination.next_before_id = null;
				renderReports();
				try {
					await refreshDashboard(true);
					notify(__('The latest scan changed. Its first page is loaded; findings from the previous scan were discarded.', 'access-ledger'));
				} catch (refreshError) { showError(refreshError); }
			} else showError(error);
		} finally {
			if (sequence === reportsSequence) {
				reportsBusy = false;
				const hadFocus = document.activeElement === $('load-older');
				renderReports();
				if (hadFocus && $('load-older').hidden) $('report-count').focus();
			}
		}
	}
	function scheduleScan() {
		clearTimeout(scanTimer);
		if (!running() || pollingPaused || scanBusy || document.hidden || scanStep) return;
		scanTimer = setTimeout(async () => {
			scanStep = api('scan/step', 'POST');
			try { await scanStep; await refreshDashboard(); }
			catch (error) { pollingPaused = true; showError(error); }
			finally { scanStep = null; scheduleScan(); }
		}, 2000);
	}
	async function changeScan(path, data) {
		if (scanBusy) return;
		scanBusy = true;
		clearTimeout(scanTimer);
		clearError();
		renderScan();
		renderReports();
		try {
			if (scanStep) await scanStep.catch(() => {});
			await api(path, 'POST', data);
			pollingPaused = false;
			await refreshDashboard();
			announce(path === 'scan/cancel' ? __('Scan cancelled. Findings already saved remain available.', 'access-ledger') : __('Scan started. Customer access is unchanged.', 'access-ledger'));
		} catch (error) { showError(error); }
		finally { scanBusy = false; renderScan(); renderReports(); scheduleScan(); }
	}
	function datum(list, label, value, wide) {
		const group = el('div', wide ? 'al-data-wide' : '');
		group.append(el('dt', '', label), el('dd', '', value));
		list.append(group);
	}
	function renderOrder(report) {
		$('explanation-title').textContent = sprintf(__('Order #%s', 'access-ledger'), report.order_number);
		const content = $('explanation-content');
		const summary = el('div', 'al-order-summary');
		summary.append(badge(report.status), el('span', '', report.customer_email || __('No billing email', 'access-ledger')), el('span', 'al-meta', sprintf(__('Checked %s', 'access-ledger'), date(report.checked_at))));
		try {
			const url = new URL(report.order_url, window.location.href);
			if (url.origin === window.location.origin && /^https?:$/.test(url.protocol)) {
				const link = el('a', '', __('Open in WooCommerce', 'access-ledger'));
				link.href = url.href;
				summary.append(link);
			}
		} catch (_) { /* An invalid order link does not prevent reading the report. */ }
		content.replaceChildren(summary);
		content.append(el('p', 'al-context', __('This checks stored order and permission records. It does not test file delivery, email delivery or whether a customer can download a file in their browser.', 'access-ledger')));
		const issues = report.issues || [];
		if (!issues.length) content.append(empty(__('No issues found in this check', 'access-ledger'), __('The inspected records have no reported discrepancies. This is not a file-delivery test.', 'access-ledger')));
		else {
			const cards = el('div', 'al-issues');
			issues.forEach((issue) => {
				const card = el('details', 'al-issue');
				card.open = repairable(issue) || issue.code === 'missing_permission';
				const heading = el('summary');
				heading.append(badge(repairable(issue) ? __('Email fix', 'access-ledger') : __('Review', 'access-ledger'), repairable(issue) ? 'success' : 'warning'), document.createTextNode(String(issue.title || issueNames[issue.code] || issue.code)));
				const body = el('div', 'al-issue-body');
				body.append(el('p', '', issue.detail));
				if (issue.code === 'missing_permission') body.append(el('p', 'al-muted', __('A missing permission may have been intentionally revoked. This tool cannot determine that intent and will not recreate access.', 'access-ledger')));
				if (repairable(issue)) body.append(button(__('Preview email correction', 'access-ledger'), (event) => openPreview(issue.permission_id, event.currentTarget), 'al-button-primary'));
				card.append(heading, body);
				cards.append(card);
			});
			content.append(cards);
		}
		content.append(el('h3', '', __('Existing download permissions', 'access-ledger')));
		const permissions = el('div', 'al-permissions');
		(report.permissions || []).forEach((permission) => {
			const card = el('article', 'al-permission');
			card.append(el('h3', '', permission.file_name), el('p', 'al-meta', permission.product_name));
			const list = el('dl', 'al-data');
			datum(list, __('Permission email', 'access-ledger'), permission.email || __('Not set', 'access-ledger'), true);
			datum(list, __('Downloads remaining', 'access-ledger'), permission.remaining === '' ? __('Unlimited', 'access-ledger') : count(permission.remaining));
			datum(list, __('Downloads recorded', 'access-ledger'), count(permission.count));
			datum(list, __('Access expires', 'access-ledger'), permission.expires ? date(permission.expires) : __('No expiry', 'access-ledger'));
			datum(list, __('Permission ID', 'access-ledger'), permission.id);
			card.append(list);
			permissions.append(card);
		});
		content.append(permissions.childElementCount ? permissions : el('p', 'al-muted', __('No permission records were returned for this order.', 'access-ledger')));
	}
	async function loadOrder(id, trigger, focus = true) {
		const sequence = ++orderSequence;
		if (trigger) orderTrigger = trigger;
		$('lookup').disabled = true;
		$('lookup').textContent = __('Checking…', 'access-ledger');
		$('explanation').hidden = false;
		$('explanation').setAttribute('aria-busy', 'true');
		$('explanation-title').textContent = sprintf(__('Checking order #%s', 'access-ledger'), id);
		$('explanation-content').replaceChildren(el('p', 'al-muted', __('Reading current order and permission records…', 'access-ledger')));
		clearError();
		try {
			const report = await api('orders/' + encodeURIComponent(id));
			if (sequence !== orderSequence) return;
			currentOrder = report;
			renderOrder(report);
			if (focus) $('explanation-title').focus();
			announce(__('Live order check ready.', 'access-ledger'));
		} catch (error) {
			if (sequence !== orderSequence) return;
			currentOrder = null;
			$('explanation-title').textContent = __('Order could not be checked', 'access-ledger');
			$('explanation-content').replaceChildren(el('p', 'al-muted', message(error)));
			showError(error);
		} finally {
			if (sequence === orderSequence) {
				$('lookup').disabled = false;
				$('lookup').textContent = __('Explain access', 'access-ledger');
				$('explanation').setAttribute('aria-busy', 'false');
			}
		}
	}

	function resetDialog(trigger) {
		dialogSequence++;
		preview = null;
		undoAudit = null;
		dialogTrigger = trigger || dialogTrigger;
		$('dialog-error').hidden = true;
		$('dialog-error').textContent = '';
		$('dialog-content').replaceChildren();
		$('repair-form').hidden = true;
		$('undo-confirm').hidden = true;
		$('preview-again').hidden = true;
		$('confirm-email').value = '';
		$('confirm-email').removeAttribute('aria-invalid');
		$('confirm-error').hidden = true;
		if (!$('repair-dialog').open) $('repair-dialog').showModal();
		return dialogSequence;
	}
	async function openPreview(id, trigger) {
		const sequence = resetDialog(trigger);
		previewId = id;
		$('dialog-title').textContent = __('Review email correction', 'access-ledger');
		$('dialog-description').textContent = __('Checking whether this existing permission can be corrected safely…', 'access-ledger');
		try {
			const result = await api('permissions/' + encodeURIComponent(id) + '/preview', 'POST');
			if (sequence !== dialogSequence || !$('repair-dialog').open) return;
			if (!result?.token || typeof result.before?.email !== 'string' || typeof result.after?.email !== 'string' || !result.after.email || result.before.email === result.after.email || result.before.user_id !== result.after.user_id || !result.before.key_matches || !result.after.key_matches || Number(result.permission_id) !== Number(id)) {
				throw new Error(__('This preview does not describe an eligible email-only correction. Check the order again.', 'access-ledger'));
			}
			preview = result;
			$('dialog-description').textContent = sprintf(__('Order #%1$s · Existing permission %2$s', 'access-ledger'), result.order_id, result.permission_id);
			const change = el('dl', 'al-email-change');
			datum(change, __('Current permission email', 'access-ledger'), result.before.email || __('Not set', 'access-ledger'));
			datum(change, __('New permission email', 'access-ledger'), result.after.email);
			$('dialog-content').append(change, el('p', 'al-context', result.explanation), el('p', 'al-meta', sprintf(__('Preview expires %s. The order and permission will be checked again when you confirm.', 'access-ledger'), date(result.expires_at))));
			$('repair-form').hidden = false;
			$('confirm-email').focus();
		} catch (error) {
			if (sequence !== dialogSequence || !$('repair-dialog').open) return;
			$('dialog-description').textContent = __('No correction has been submitted.', 'access-ledger');
			$('dialog-error').textContent = message(error);
			$('dialog-error').hidden = false;
			$('preview-again').hidden = false;
		}
	}
	function setMutationBusy(busy) {
		mutationBusy = busy;
		['dialog-close', 'dialog-cancel', 'repair-confirm', 'undo-confirm', 'confirm-email', 'preview-again'].forEach((id) => { $(id).disabled = busy; });
		$('repair-confirm').textContent = busy ? __('Applying…', 'access-ledger') : __('Confirm email correction', 'access-ledger');
		$('undo-confirm').textContent = busy ? __('Applying…', 'access-ledger') : __('Undo email correction', 'access-ledger');
	}
	async function afterMutation(orderId, text) {
		setMutationBusy(false);
		$('repair-dialog').close();
		notify(text + ' ' + __('Saved scan results remain unchanged until you run a new scan.', 'access-ledger'));
		$('history-heading').focus();
		try { await refreshDashboard(); } catch (error) { showError(error); }
		if (currentOrder && Number(currentOrder.order_id) === Number(orderId)) await loadOrder(orderId, null, false);
	}
	function openUndo(audit, trigger) {
		resetDialog(trigger);
		undoAudit = audit;
		$('dialog-title').textContent = __('Undo email correction?', 'access-ledger');
		$('dialog-description').textContent = sprintf(__('Correction #%1$s · Order #%2$s · Permission %3$s', 'access-ledger'), audit.id, audit.order_id, audit.permission_id);
		$('dialog-content').append(el('p', 'al-context', __('This restores the previous permission email only. The server will reject the undo if the correction no longer matches the current state. Counts, remaining downloads, expiry and logs are preserved.', 'access-ledger')), el('p', 'al-meta', __('Only the operator who made this correction can undo it.', 'access-ledger')));
		$('undo-confirm').hidden = false;
	}
	$('repair-form').addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!preview || mutationBusy) return;
		if ($('confirm-email').value !== preview.after.email) {
			$('confirm-error').textContent = __('Enter the exact new email shown in the preview.', 'access-ledger');
			$('confirm-error').hidden = false;
			$('confirm-email').setAttribute('aria-invalid', 'true');
			$('confirm-email').focus();
			return;
		}
		const submitted = preview;
		setMutationBusy(true);
		$('dialog-error').hidden = true;
		try {
			const result = await api('permissions/' + encodeURIComponent(submitted.permission_id) + '/repair', 'POST', { token: submitted.token });
			await afterMutation(submitted.order_id, result.message || __('Permission email corrected.', 'access-ledger'));
		} catch (error) {
			preview = null;
			$('repair-form').hidden = true;
			$('dialog-error').textContent = message(error) + ' ' + __('Check the live order or get a fresh preview before trying again.', 'access-ledger');
			$('dialog-error').hidden = false;
			$('preview-again').hidden = false;
			setMutationBusy(false);
			$('preview-again').focus();
		}
	});
	$('undo-confirm').addEventListener('click', async () => {
		if (!undoAudit || mutationBusy) return;
		const submitted = undoAudit;
		setMutationBusy(true);
		try {
			const result = await api('audits/' + encodeURIComponent(submitted.id) + '/undo', 'POST');
			await afterMutation(submitted.order_id, result.message || __('Email correction undone.', 'access-ledger'));
		} catch (error) {
			$('dialog-error').textContent = message(error);
			$('dialog-error').hidden = false;
			setMutationBusy(false);
			$('undo-confirm').hidden = true;
			$('dialog-close').focus();
		}
	});
	$('preview-again').addEventListener('click', () => openPreview(previewId));
	['dialog-close', 'dialog-cancel'].forEach((id) => $(id).addEventListener('click', () => { if (!mutationBusy) $('repair-dialog').close(); }));
	$('repair-dialog').addEventListener('cancel', (event) => { if (mutationBusy) event.preventDefault(); });
	$('repair-dialog').addEventListener('close', () => {
		dialogSequence++;
		preview = null;
		undoAudit = null;
		$('confirm-email').value = '';
		if (dialogTrigger?.isConnected) dialogTrigger.focus(); else $('history-heading').focus();
	});
	$('confirm-email').addEventListener('input', () => { $('confirm-email').removeAttribute('aria-invalid'); $('confirm-error').hidden = true; });
	$('scan-form').addEventListener('submit', (event) => { event.preventDefault(); if (!running()) changeScan('scan', { days: Number($('days').value) }); });
	$('scan-cancel').addEventListener('click', () => changeScan('scan/cancel'));
	$('lookup-form').addEventListener('submit', (event) => {
		event.preventDefault();
		const value = $('order-id').value.trim();
		if (!/^[1-9][0-9]*$/.test(value) || !Number.isSafeInteger(Number(value))) {
			$('order-error').textContent = __('Enter a positive numeric order ID.', 'access-ledger');
			$('order-error').hidden = false;
			$('order-id').setAttribute('aria-invalid', 'true');
			$('order-id').focus();
			return;
		}
		$('order-error').hidden = true;
		$('order-id').removeAttribute('aria-invalid');
		loadOrder(value, $('order-id'));
	});
	$('explanation-close').addEventListener('click', () => {
		orderSequence++;
		currentOrder = null;
		$('explanation').hidden = true;
		$('lookup').disabled = false;
		$('lookup').textContent = __('Explain access', 'access-ledger');
		(orderTrigger?.isConnected ? orderTrigger : $('order-id')).focus();
	});
	Object.entries(issueNames).forEach(([value, label]) => { const option = el('option', '', label); option.value = value; $('issue-filter').append(option); });
	$('search').addEventListener('input', renderReports);
	$('load-older').addEventListener('click', loadOlder);
	['filter', 'issue-filter'].forEach((id) => $(id).addEventListener('change', () => { renderReports(); announce($('report-count').textContent); }));
	$('refresh').addEventListener('click', async () => {
		$('refresh').disabled = true;
		clearError();
		pollingPaused = false;
		try { await refreshDashboard(true); announce(__('Dashboard refreshed to the latest 50 saved findings. Search and filters cover loaded orders only.', 'access-ledger')); }
		catch (error) { showError(error); }
		finally { $('refresh').disabled = false; }
	});
	['json', 'csv'].forEach((format) => $(format === 'json' ? 'export' : 'export-csv').addEventListener('click', async () => {
		if (exportBusy) return;
		exportBusy = true;
		['export', 'export-csv'].forEach((id) => { $(id).disabled = true; });
		clearError();
		try {
			let blob, total;
			if (format === 'csv') {
				const response = await api('export?format=csv', 'GET', undefined, false);
				if (!response.ok || !response.headers.get('Content-Type')?.startsWith('text/csv')) throw new Error(__('The export response was incomplete. Please try again.', 'access-ledger'));
				total = Number(response.headers.get('X-Access-Ledger-Report-Count'));
				blob = await response.blob();
			} else {
				const result = await api('export');
				if (!result || !Array.isArray(result.reports)) throw new Error(__('The export response was incomplete. Please try again.', 'access-ledger'));
				total = result.reports.length;
				blob = new Blob([JSON.stringify(result, null, 2)], { type: 'application/json;charset=utf-8' });
			}
			const url = URL.createObjectURL(blob);
			const link = el('a');
			link.href = url;
			link.download = 'product-access-ledger-' + new Date().toISOString().slice(0, 10) + '.' + format;
			root.append(link);
			link.click();
			link.remove();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
			notify(sprintf(__('%1$s export prepared with %2$s saved reports (latest scan, maximum 1,000; filters do not apply).', 'access-ledger'), format.toUpperCase(), count(total)));
		} catch (error) { showError(error); }
		finally { exportBusy = false; ['export', 'export-csv'].forEach((id) => { $(id).disabled = false; }); }
	}));
	document.addEventListener('visibilitychange', () => {
		if (document.hidden) clearTimeout(scanTimer);
		else refreshDashboard().catch(showError);
	});
	window.addEventListener('pagehide', () => clearTimeout(scanTimer));
	refreshDashboard().catch((error) => {
		showError(error);
		$('scan-status').textContent = __('Unavailable', 'access-ledger');
		$('scan-summary').textContent = __('Refresh to try loading the dashboard again.', 'access-ledger');
		$('reports-content').setAttribute('aria-busy', 'false');
		$('history-content').setAttribute('aria-busy', 'false');
		$('reports-content').replaceChildren(empty(__('Saved checks unavailable', 'access-ledger'), __('Use Refresh to retry. No customer access has been changed.', 'access-ledger')));
		$('history-content').replaceChildren(empty(__('History unavailable', 'access-ledger'), __('Use Refresh to retry.', 'access-ledger')));
	});
}());
