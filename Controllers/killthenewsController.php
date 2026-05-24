<?php
declare(strict_types=1);

final class FreshExtension_killthenews_Controller extends Minz_ActionController {

	private const CATEGORY_NAME = 'Newsletters';

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
			http_response_code(403);
			$this->view->ktnResponse = ['error' => _t('ext.kill_the_news.error_csrf')];
			return;
		}
		if ($ext === null || !$ext->isConfigured()) {
			http_response_code(400);
			$this->view->ktnResponse = ['error' => _t('ext.kill_the_news.not_configured')];
			return;
		}
		$title = trim(Minz_Request::paramString('title'));
		if ($title === '') {
			http_response_code(400);
			$this->view->ktnResponse = ['error' => _t('ext.kill_the_news.error_title_required')];
			return;
		}

		try {
			$feed = $ext->buildClient()->createFeed($title);
		} catch (KillTheNewsException $e) {
			http_response_code(502);
			$this->view->ktnResponse = ['error' => $e->getMessage()];
			return;
		}

		try {
			FreshRSS_feed_Controller::addFeed($feed['rssUrl'], $title, 0, self::CATEGORY_NAME);
		} catch (FreshRSS_AlreadySubscribed_Exception $e) {
			// Feed already present in FreshRSS: still return the address, it is valid.
		} catch (\Throwable $e) {
			http_response_code(502);
			$this->view->ktnResponse = [
				'error' => $e->getMessage(),
				'emailAddress' => $feed['emailAddress'],
				'rssUrl' => $feed['rssUrl'],
			];
			return;
		}

		$this->view->ktnResponse = [
			'emailAddress' => $feed['emailAddress'],
			'rssUrl' => $feed['rssUrl'],
			'title' => $feed['title'],
		];
	}

	/**
	 * Read-only list of the user's newsletter addresses.
	 *
	 * Served over GET and gated by FreshRSS_Auth::hasAccess() (firstAction).
	 * Known limitation: the response exposes the user's own newsletter email
	 * addresses, so a cross-origin read would leak them ONLY if FreshRSS is
	 * deployed with permissive CORS headers (the same-origin policy blocks
	 * reading the JSON body otherwise). Default FreshRSS sends no such headers.
	 * Harden with a CSRF / X-Requested-With check if a permissive CORS setup is used.
	 */
	public function listAction(): void {
		$ext = $this->extension();
		if ($ext === null || !$ext->isConfigured()) {
			http_response_code(400);
			$this->view->ktnResponse = ['error' => _t('ext.kill_the_news.not_configured')];
			return;
		}
		try {
			$feeds = $ext->buildClient()->listFeeds();
		} catch (KillTheNewsException $e) {
			http_response_code(502);
			$this->view->ktnResponse = ['error' => $e->getMessage()];
			return;
		}
		$this->view->ktnResponse = ['feeds' => array_map(static fn (array $f): array => [
			'title' => $f['title'],
			'emailAddress' => $f['emailAddress'],
			'rssUrl' => $f['rssUrl'],
		], $feeds)];
	}
}
