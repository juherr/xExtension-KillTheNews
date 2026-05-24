<?php
declare(strict_types=1);

require_once __DIR__ . '/KillTheNewsClient.php';

final class KillTheNewsExtension extends Minz_Extension {
	public const ROUTE = 'killthenews';

	public string $instanceUrl = '';
	public string $apiToken = '';
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

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars[self::ROUTE] = [
			'createUrl' => Minz_Url::display(['c' => self::ROUTE, 'a' => 'create'], 'php'),
			'listUrl' => Minz_Url::display(['c' => self::ROUTE, 'a' => 'list'], 'php'),
			'i18n' => [
				'panel_title' => _t('ext.kill_the_news.panel_title'),
				'panel_help' => _t('ext.kill_the_news.panel_help'),
				'name_label' => _t('ext.kill_the_news.name_label'),
				'name_placeholder' => _t('ext.kill_the_news.name_placeholder'),
				'create_button' => _t('ext.kill_the_news.create_button'),
				'creating' => _t('ext.kill_the_news.creating'),
				'created_intro' => _t('ext.kill_the_news.created_intro'),
				'copy_button' => _t('ext.kill_the_news.copy_button'),
				'copied' => _t('ext.kill_the_news.copied'),
				'open_feed' => _t('ext.kill_the_news.open_feed'),
				'existing_title' => _t('ext.kill_the_news.existing_title'),
				'error_generic' => _t('ext.kill_the_news.error_generic'),
				'error_title_required' => _t('ext.kill_the_news.error_title_required'),
			],
		];
		return $vars;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::init();
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$url = KillTheNewsClient::normalizeBaseUrl(Minz_Request::paramString('ktn_instance_url'));
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

		$this->instanceUrl = $this->getInstanceUrl();
		$this->apiToken = $this->getApiToken();
		$this->verifyTls = $this->getVerifyTls();
	}
}
