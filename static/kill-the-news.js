'use strict';

(function () {
	var RSS_SOURCE_VALUE = '0';

	function init() {
		var ctx = window.context;
		if (!ctx || !ctx.extensions || !ctx.extensions.killthenews) {
			return;
		}
		var cfg = ctx.extensions.killthenews;
		var i18n = cfg.i18n || {};

		initAddFeedForm(ctx, cfg, i18n);
		initFeedDetails(ctx, cfg, i18n);
	}

	function initAddFeedForm(ctx, cfg, i18n) {
		var urlField = document.getElementById('url_rss');
		var sourceField = document.getElementById('feed_kind');
		if (!urlField || !sourceField) {
			return;
		}
		var form = urlField.closest('form') || urlField.parentNode;
		var categoryField = form ? form.querySelector('#category, [name="category"]') : null;
		var submitButton = form ? form.querySelector('.form-actions button[type="submit"], .form-actions button:not([type])') : null;
		var urlGroup = closest(urlField, '.form-group');

		addSourceOption(sourceField, i18n);
		var fields = addSourceFields(sourceField, i18n);
		var nameInput = fields.querySelector('.ktn-name');
		var createBtn = fields.querySelector('.ktn-create');
		var emailInput = fields.querySelector('.ktn-email-input');
		var feedUrlInput = fields.querySelector('.ktn-feed-url-input');
		var resultBox = fields.querySelector('.ktn-result');
		var errorBox = fields.querySelector('.ktn-error');
		var hidden = addHiddenFields(form);

		sourceField.addEventListener('change', function () {
			syncSourceState(sourceField, urlField, urlGroup, submitButton, hidden);
		});
		syncSourceState(sourceField, urlField, urlGroup, submitButton, hidden);

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
			postForm(cfg.createUrl, { _csrf: ctx.csrf, title: title, subscribe: '0' })
				.then(function (data) {
					if (data && data.emailAddress && data.feedUrl) {
						fillFreshRssForm(urlField, categoryField, i18n.category_name, data.feedUrl);
						fillKtnFields(hidden, emailInput, feedUrlInput, i18n, data);
						showResult(resultBox, i18n, data);
						syncSourceState(sourceField, urlField, urlGroup, submitButton, hidden);
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
	}

	function initFeedDetails(ctx, cfg, i18n) {
		var feedUpdate = document.getElementById('feed_update');
		var urlField = document.getElementById('url');
		if (!feedUpdate || !urlField) {
			return;
		}
		if (cfg.currentFeed && cfg.currentFeed.emailAddress) {
			insertReadonlyEmail(urlField, i18n, cfg.currentFeed);
			return;
		}
		postForm(cfg.listUrl, { _csrf: ctx.csrf })
			.then(function (data) {
				if (!data || !data.feeds) {
					return;
				}
				var currentUrl = normalizeUrl(urlField.value);
				var match = data.feeds.find(function (feed) {
					return normalizeUrl(feed.feedUrl) === currentUrl;
				});
				if (match && match.emailAddress) {
					insertReadonlyEmail(urlField, i18n, match);
				}
			})
			.catch(function () { /* feed metadata is best-effort */ });
	}

	function addSourceOption(sourceField, i18n) {
		if (sourceField.querySelector('option[data-ktn-source="1"]')) {
			return;
		}
		var option = document.createElement('option');
		option.value = RSS_SOURCE_VALUE;
		option.textContent = i18n.source_type || 'KillTheNews';
		option.dataset.show = 'ktn_source';
		option.dataset.ktnSource = '1';
		sourceField.appendChild(option);
	}

	function addSourceFields(sourceField, i18n) {
		var existing = document.getElementById('ktn_source');
		if (existing) {
			return existing;
		}
		var fieldset = document.createElement('fieldset');
		fieldset.id = 'ktn_source';
		fieldset.className = 'ktn-source';
		fieldset.style.display = 'none';
		fieldset.innerHTML =
			'<p class="help">' + esc(i18n.panel_help) + '</p>' +
			'<div class="form-group">' +
			'<label class="group-name" for="ktn-newsletter-name">' + esc(i18n.name_label) + '</label>' +
			'<div class="group-controls">' +
			'<div class="stick">' +
			'<input type="text" id="ktn-newsletter-name" class="ktn-name long" placeholder="' + esc(i18n.name_placeholder) + '" />' +
			'<button type="button" class="btn btn-important ktn-create">' + esc(i18n.create_button) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'<div class="form-group ktn-created ktn-hidden">' +
			'<label class="group-name" for="ktn-email-address">' + esc(i18n.email_label) + '</label>' +
			'<div class="group-controls">' +
			'<div class="stick">' +
			'<input type="email" id="ktn-email-address" class="ktn-email-input long" readonly="readonly" />' +
			'<button type="button" class="btn ktn-copy">' + esc(i18n.copy_button) + '</button>' +
			'</div>' +
			'<input type="url" class="ktn-feed-url-input long" readonly="readonly" aria-label="' + esc(i18n.feed_url_label) + '" />' +
			'<p class="help">' + esc(i18n.created_help) + '</p>' +
			'</div>' +
			'</div>' +
			'<div class="ktn-result ktn-hidden"></div>' +
			'<div class="ktn-error ktn-hidden"></div>';
		var advanced = closest(sourceField, 'details, fieldset');
		if (advanced) {
			advanced.appendChild(fieldset);
		} else {
			sourceField.parentNode.appendChild(fieldset);
		}
		return fieldset;
	}

	function addHiddenFields(form) {
		return {
			source: addHidden(form, 'ktn_source'),
			id: addHidden(form, 'ktn_feed_id'),
			email: addHidden(form, 'ktn_email_address'),
			adminUrl: addHidden(form, 'ktn_admin_url'),
		};
	}

	function addHidden(form, name) {
		var input = form.querySelector('input[name="' + name + '"]');
		if (input) {
			return input;
		}
		input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		form.appendChild(input);
		return input;
	}

	function syncSourceState(sourceField, urlField, urlGroup, submitButton, hidden) {
		var isKtn = isKtnSelected(sourceField);
		var hasFeed = hidden.email.value !== '' && urlField.value !== '';
		var ktnSource = document.getElementById('ktn_source');
		hidden.source.value = isKtn ? '1' : '';
		urlField.readOnly = isKtn;
		if (isKtn && !hasFeed) {
			urlField.value = '';
		}
		if (ktnSource) {
			ktnSource.style.display = isKtn ? 'block' : 'none';
		}
		if (urlGroup) {
			urlGroup.classList.toggle('ktn-url-generated', isKtn);
		}
		if (submitButton) {
			submitButton.disabled = isKtn && !hasFeed;
		}
	}

	function isKtnSelected(sourceField) {
		var option = sourceField.options[sourceField.selectedIndex];
		return !!option && option.dataset.ktnSource === '1';
	}

	function fillKtnFields(hidden, emailInput, feedUrlInput, i18n, data) {
		hidden.id.value = data.id || '';
		hidden.email.value = data.emailAddress || '';
		hidden.adminUrl.value = data.adminUrl || '';
		emailInput.value = data.emailAddress || '';
		feedUrlInput.value = data.feedUrl || '';
		var created = closest(emailInput, '.ktn-created');
		if (created) {
			show(created);
		}
		var copyBtn = created ? created.querySelector('.ktn-copy') : null;
		if (copyBtn) {
			setCopyHandler(copyBtn, data.emailAddress, i18n);
		}
	}

	function fillFreshRssForm(urlField, categoryField, categoryName, feedUrl) {
		if (feedUrl && /^https?:\/\//i.test(feedUrl)) {
			urlField.value = feedUrl;
			urlField.dispatchEvent(new Event('input', { bubbles: true }));
			urlField.dispatchEvent(new Event('change', { bubbles: true }));
		}
		if (!categoryField || !categoryName) {
			return;
		}
		var normalizedCategoryName = normalize(categoryName);
		for (var i = 0; i < categoryField.options.length; i += 1) {
			if (normalize(categoryField.options[i].textContent) === normalizedCategoryName) {
				categoryField.value = categoryField.options[i].value;
				categoryField.dispatchEvent(new Event('change', { bubbles: true }));
				return;
			}
		}
	}

	function showResult(box, i18n, data) {
		box.innerHTML =
			'<p>' + esc(i18n.created_intro) + '</p>' +
			(data.adminUrl && /^https?:\/\//i.test(data.adminUrl) ? '<a class="btn" target="_blank" rel="noreferrer" href="' + esc(data.adminUrl) + '">' + esc(i18n.manage_feed) + '</a>' : '');
		show(box);
	}

	function insertReadonlyEmail(urlField, i18n, feed) {
		if (document.getElementById('ktn-feed-email')) {
			return;
		}
		var group = document.createElement('div');
		group.className = 'form-group ktn-feed-email';
		group.innerHTML =
			'<label class="group-name" for="ktn-feed-email">' + esc(i18n.email_label) + '</label>' +
			'<div class="group-controls">' +
			'<div class="stick w100">' +
			'<input type="email" id="ktn-feed-email" class="w100" readonly="readonly" value="' + esc(feed.emailAddress) + '" />' +
			'<button type="button" class="btn ktn-copy">' + esc(i18n.copy_button) + '</button>' +
			(feed.adminUrl && /^https?:\/\//i.test(feed.adminUrl) ? '<a class="btn" target="_blank" rel="noreferrer" href="' + esc(feed.adminUrl) + '">' + esc(i18n.manage_feed) + '</a>' : '') +
			'</div>' +
			'</div>';
		var urlGroup = closest(urlField, '.form-group');
		if (urlGroup && urlGroup.parentNode) {
			urlGroup.parentNode.insertBefore(group, urlGroup.nextSibling);
		}
		var copyBtn = group.querySelector('.ktn-copy');
		setCopyHandler(copyBtn, feed.emailAddress, i18n);
	}

	function setCopyHandler(copyBtn, text, i18n) {
		copyBtn.addEventListener('click', function () {
			copyText(text).then(function () {
				copyBtn.textContent = i18n.copied;
			}).catch(function () {
				copyBtn.textContent = i18n.error_generic;
			});
		});
	}

	function showError(box, msg) {
		box.textContent = msg;
		show(box);
	}

	function hide(box) {
		box.classList.add('ktn-hidden');
	}

	function show(box) {
		box.classList.remove('ktn-hidden');
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

	function closest(element, selector) {
		return element && element.closest ? element.closest(selector) : null;
	}

	function normalizeUrl(url) {
		return String(url == null ? '' : url).replace(/\/+$/, '');
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function normalize(s) {
		return String(s == null ? '' : s).trim().toLocaleLowerCase();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
