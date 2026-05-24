<?php

declare(strict_types=1);

final class FreshExtension_killthenews_Controller extends Minz_ActionController {
	private function fail(int $status, string $message): void {
		http_response_code($status);
		$this->view->ktnResponse = ['error' => $message];
	}

	private function logFailure(string $context, \Throwable $e): void {
		error_log('[KillTheNews] ' . $context . ': ' . $e->getMessage());
	}

	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		$this->view->_layout(null);
		header('Content-Type: application/json; charset=UTF-8');
	}

	private function extension(): ?KillTheNewsExtension {
		$ext = Minz_ExtensionManager::findExtension('KillTheNews');
		return $ext instanceof KillTheNewsExtension ? $ext : null;
	}

	public function createAction(): void {
		$ext = $this->extension();
		if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
			$this->fail(403, _t('ext.kill_the_news.error_csrf'));
			return;
		}
		if ($ext === null || !$ext->isConfigured()) {
			$this->fail(400, _t('ext.kill_the_news.not_configured'));
			return;
		}
		$title = trim(Minz_Request::paramString('title'));
		if ($title === '') {
			$this->fail(400, _t('ext.kill_the_news.error_title_required'));
			return;
		}

		try {
			$feed = $ext->buildClient()->createFeed($title);
		} catch (KillTheNewsException $e) {
			$this->logFailure('Feed creation failed', $e);
			$this->fail(502, _t('ext.kill_the_news.error_upstream'));
			return;
		}

		try {
			FreshRSS_feed_Controller::addFeed($feed['feedUrl'], $title, 0, _t('ext.kill_the_news.category_name'));
		} catch (FreshRSS_AlreadySubscribed_Exception $e) {
			// Feed already present in FreshRSS: still return the address, it is valid.
		} catch (\Throwable $e) {
			$this->logFailure('FreshRSS subscription failed', $e);
			http_response_code(502);
			$this->view->ktnResponse = [
				'error' => _t('ext.kill_the_news.error_subscription_failed'),
				'emailAddress' => $feed['emailAddress'],
				'feedUrl' => $feed['feedUrl'],
			];
			return;
		}

		$this->view->ktnResponse = [
			'emailAddress' => $feed['emailAddress'],
			'feedUrl' => $feed['feedUrl'],
			'title' => $feed['title'],
		];
	}

	/**
	 * Read-only list of the user's newsletter addresses.
	 *
	 * Served over POST with CSRF because the response exposes the user's own
	 * newsletter email addresses.
	 */
	public function listAction(): void {
		$ext = $this->extension();
		if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
			$this->fail(403, _t('ext.kill_the_news.error_csrf'));
			return;
		}
		if ($ext === null || !$ext->isConfigured()) {
			$this->fail(400, _t('ext.kill_the_news.not_configured'));
			return;
		}
		try {
			$feeds = $ext->buildClient()->listFeeds();
		} catch (KillTheNewsException $e) {
			$this->logFailure('Feed listing failed', $e);
			$this->fail(502, _t('ext.kill_the_news.error_upstream'));
			return;
		}
		$this->view->ktnResponse = ['feeds' => array_map(static fn (array $f): array => [
			'title' => $f['title'],
			'emailAddress' => $f['emailAddress'],
			'feedUrl' => $f['feedUrl'],
		], $feeds)];
	}
}
