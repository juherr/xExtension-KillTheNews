'use strict';

(function () {
	function init() {
		var ctx = window.context;
		if (!ctx || !ctx.extensions || !ctx.extensions.killthenews) {
			return;
		}
		var urlField = document.getElementById('url_rss');
		if (!urlField) {
			return; // not the add-subscription page
		}
		var cfg = ctx.extensions.killthenews;
		var i18n = cfg.i18n || {};
		var form = urlField.closest('form') || urlField.parentNode;

		var panel = document.createElement('div');
		panel.className = 'ktn-panel';
		panel.innerHTML =
			'<h2>' + esc(i18n.panel_title) + '</h2>' +
			'<p class="ktn-help">' + esc(i18n.panel_help) + '</p>' +
			'<div class="ktn-row">' +
			'<label class="ktn-label" for="ktn-newsletter-name">' + esc(i18n.name_label) + '</label>' +
			'<input type="text" id="ktn-newsletter-name" class="ktn-name" placeholder="' + esc(i18n.name_placeholder) + '" />' +
			'<button type="button" class="btn btn-important ktn-create">' + esc(i18n.create_button) + '</button>' +
			'</div>' +
			'<div class="ktn-result ktn-hidden"></div>' +
			'<div class="ktn-error ktn-hidden"></div>' +
			'<div class="ktn-existing"></div>';

		form.parentNode.insertBefore(panel, form);

		var nameInput = panel.querySelector('.ktn-name');
		var createBtn = panel.querySelector('.ktn-create');
		var resultBox = panel.querySelector('.ktn-result');
		var errorBox = panel.querySelector('.ktn-error');
		var existingBox = panel.querySelector('.ktn-existing');

		nameInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				createBtn.click();
			}
		});

		createBtn.addEventListener('click', function () {
			var title = (nameInput.value || '').trim();
			hide(errorBox);
			hide(resultBox);
			if (!title) {
				showError(errorBox, i18n.error_title_required || i18n.error_generic);
				return;
			}
			createBtn.disabled = true;
			createBtn.textContent = i18n.creating;
			postForm(cfg.createUrl, { _csrf: ctx.csrf, title: title })
				.then(function (data) {
					if (data && data.emailAddress) {
						showResult(resultBox, i18n, data);
						nameInput.value = '';
						loadExisting();
					} else {
						showError(errorBox, (data && data.error) || i18n.error_generic);
					}
				})
				.catch(function () { showError(errorBox, i18n.error_generic); })
				.finally(function () {
					createBtn.disabled = false;
					createBtn.textContent = i18n.create_button;
				});
		});

		function loadExisting() {
			postForm(cfg.listUrl, { _csrf: ctx.csrf })
				.then(function (data) {
					if (!data || !data.feeds || !data.feeds.length) {
						existingBox.innerHTML = '';
						return;
					}
					var html = '<strong>' + esc(i18n.existing_title) + '</strong><ul>';
					data.feeds.forEach(function (f) {
						html += '<li>' + esc(f.title) + ' — <code>' + esc(f.emailAddress) + '</code></li>';
					});
					html += '</ul>';
					existingBox.innerHTML = html;
				})
				.catch(function () { /* listing is best-effort */ });
		}

		loadExisting();
	}

	function showResult(box, i18n, data) {
		box.innerHTML =
			'<p>' + esc(i18n.created_intro) + '</p>' +
			'<span class="ktn-email"><code>' + esc(data.emailAddress) + '</code>' +
			'<button type="button" class="btn ktn-copy">' + esc(i18n.copy_button) + '</button></span>' +
			(data.feedUrl && /^https?:\/\//i.test(data.feedUrl) ? ' <a class="btn" target="_blank" rel="noreferrer" href="' + esc(data.feedUrl) + '">' + esc(i18n.open_feed) + '</a>' : '');
		box.classList.remove('ktn-hidden');
		var copyBtn = box.querySelector('.ktn-copy');
		copyBtn.addEventListener('click', function () {
			copyText(data.emailAddress).then(function () {
				copyBtn.textContent = i18n.copied;
			}).catch(function () {
				copyBtn.textContent = i18n.error_generic;
			});
		});
	}

	function showError(box, msg) {
		box.textContent = msg;
		box.classList.remove('ktn-hidden');
	}

	function hide(box) {
		box.classList.add('ktn-hidden');
	}

	function postForm(url, params) {
		var body = new URLSearchParams();
		Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (r) { return r.json(); });
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			document.body.appendChild(textarea);
			textarea.select();
			try {
				if (document.execCommand('copy')) {
					resolve();
				} else {
					reject(new Error('copy failed'));
				}
			} catch (e) {
				reject(e);
			} finally {
				document.body.removeChild(textarea);
			}
		});
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
