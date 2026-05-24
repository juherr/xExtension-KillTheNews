<?php

declare(strict_types=1);

require_once __DIR__ . '/KillTheNewsClient.php';

final class KillTheNewsExtension extends Minz_Extension {
	public const ROUTE = 'killthenews';

	public string $instanceUrl = '';
	public bool $hasApiToken = false;
	public bool $verifyTls = true;
	/** @var array{ok:bool,message:string}|null */
	public ?array $testResult = null;

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();
		$this->registerController(self::ROUTE);
		$this->registerViews();

		if ($this->isConfigured()) {
			Minz_View::appendStyle($this->getFileUrl('kill-the-news.css'));
			// defer=true, async=false so window.context (set by main.js) is ready.
			Minz_View::appendScript($this->getFileUrl('kill-the-news.js'), false, true, false);
			$this->registerHook(Minz_HookType::JsVars, [$this, 'jsVars']);
			$this->registerHook(Minz_HookType::FeedBeforeInsert, [$this, 'feedBeforeInsert']);
		}
	}

	public function isConfigured(): bool {
		return $this->getInstanceUrl() !== '' && $this->getApiToken() !== '';
	}

	public function getInstanceUrl(): string {
		return $this->getUserConfigurationString('instance_url') ?? '';
	}

	public function getApiToken(): string {
		return $this->getUserConfigurationString('api_token') ?? '';
	}

	public function getVerifyTls(): bool {
		return $this->getUserConfigurationBool('verify_tls') ?? true;
	}

	public function buildClient(): KillTheNewsClient {
		return KillTheNewsClient::withCurl($this->getInstanceUrl(), $this->getApiToken(), $this->getVerifyTls());
	}

	public function feedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		if (Minz_Request::paramString('ktn_source') !== '1') {
			return $feed;
		}
		$id = trim(Minz_Request::paramString('ktn_feed_id'));
		$email = trim(Minz_Request::paramString('ktn_email_address'));
		$adminUrl = trim(Minz_Request::paramString('ktn_admin_url'));
		if ($id === '' || $email === '') {
			return $feed;
		}
		$feed->_attribute('kill_the_news', array_filter([
			'id' => $id,
			'emailAddress' => $email,
			'adminUrl' => $adminUrl !== '' ? $adminUrl : null,
		]));
		return $feed;
	}

	/** @return array{id:string,emailAddress:string,adminUrl?:string}|null */
	private function currentFeedMetadata(): ?array {
		$id = Minz_Request::paramInt('id');
		if ($id <= 0) {
			return null;
		}
		try {
			$feed = FreshRSS_Factory::createFeedDao()->searchById($id);
		} catch (\Throwable) {
			return null;
		}
		if ($feed === null) {
			return null;
		}
		$metadata = $feed->attributeArray('kill_the_news');
		if ($metadata === null) {
			return null;
		}
		$feedId = $metadata['id'] ?? null;
		$email = $metadata['emailAddress'] ?? null;
		$adminUrl = $metadata['adminUrl'] ?? null;
		if (!is_string($feedId) || !is_string($email) || $feedId === '' || $email === '') {
			return null;
		}
		$result = [
			'id' => $feedId,
			'emailAddress' => $email,
		];
		if (is_string($adminUrl) && $adminUrl !== '') {
			$result['adminUrl'] = $adminUrl;
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars[self::ROUTE] = [
			'createUrl' => Minz_Url::display(['c' => self::ROUTE, 'a' => 'create'], 'php'),
			'listUrl' => Minz_Url::display(['c' => self::ROUTE, 'a' => 'list'], 'php'),
			'currentFeed' => $this->currentFeedMetadata(),
			'i18n' => [
				'panel_help' => _t('ext.kill_the_news.panel_help'),
				'source_type' => _t('ext.kill_the_news.source_type'),
				'name_label' => _t('ext.kill_the_news.name_label'),
				'name_placeholder' => _t('ext.kill_the_news.name_placeholder'),
				'email_label' => _t('ext.kill_the_news.email_label'),
				'feed_url_label' => _t('ext.kill_the_news.feed_url_label'),
				'create_button' => _t('ext.kill_the_news.create_button'),
				'creating' => _t('ext.kill_the_news.creating'),
				'created_intro' => _t('ext.kill_the_news.created_intro'),
				'created_help' => _t('ext.kill_the_news.created_help'),
				'copy_button' => _t('ext.kill_the_news.copy_button'),
				'copied' => _t('ext.kill_the_news.copied'),
				'manage_feed' => _t('ext.kill_the_news.manage_feed'),
				'category_name' => _t('ext.kill_the_news.category_name'),
				'error_generic' => _t('ext.kill_the_news.error_generic'),
				'error_title_required' => _t('ext.kill_the_news.error_title_required'),
			],
		];
		return $vars;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();

		if (Minz_Request::isPost()) {
			try {
				$url = KillTheNewsClient::normalizeBaseUrl(Minz_Request::paramString('ktn_instance_url'));
			} catch (KillTheNewsException $e) {
				$url = null;
				$this->testResult = ['ok' => false, 'message' => _t('ext.kill_the_news.error_invalid_instance_url')];
			}

			if ($url !== null) {
				$token = Minz_Request::paramString('ktn_api_token');
				$verify = Minz_Request::paramBoolean('ktn_verify_tls');

				$this->setUserConfigurationValue('instance_url', $url);
				// Empty token field means "keep the current token" (the field is never pre-filled with the secret).
				if ($token !== '') {
					$this->setUserConfigurationValue('api_token', $token);
				}
				$this->setUserConfigurationValue('verify_tls', $verify);
				FreshRSS_UserDAO::touch();

				$effectiveToken = $token !== '' ? $token : $this->getApiToken();
				if (Minz_Request::paramString('ktn_test') !== '' && $url !== '' && $effectiveToken !== '') {
					try {
						KillTheNewsClient::withCurl($url, $effectiveToken, $verify)->listFeeds();
						$this->testResult = ['ok' => true, 'message' => _t('ext.kill_the_news.test_ok')];
					} catch (\Throwable $e) {
						$this->testResult = ['ok' => false, 'message' => _t('ext.kill_the_news.test_failed', $e->getMessage())];
					}
				}
			}
		}

		$this->instanceUrl = $this->getInstanceUrl();
		$this->hasApiToken = $this->getApiToken() !== '';
		$this->verifyTls = $this->getVerifyTls();
	}
}
