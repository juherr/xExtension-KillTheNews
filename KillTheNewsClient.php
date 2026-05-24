<?php

declare(strict_types=1);

final class KillTheNewsException extends RuntimeException {
}

final class KillTheNewsClient {
	private const FEEDS_PATH = '/api/v1/feeds';

	/** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
	private $transport;

	/**
	 * @param callable(string,string,array<string,string>,?string):array{status:int,body:string} $transport
	 *   Receives ($method, $url, $headers, $body) and returns ['status' => int, 'body' => string].
	 */
	public function __construct(
		private readonly string $instanceUrl,
		private readonly string $apiToken,
		callable $transport,
	) {
		$this->transport = $transport;
	}

	public static function withCurl(string $instanceUrl, string $apiToken, bool $verifyTls = true): self {
		/**
		 * @param array<string,string> $headers
		 * @return array{status:int,body:string}
		 */
		$transport = static function (string $method, string $url, array $headers, ?string $body) use ($verifyTls): array {
			if ($url === '' || $method === '') {
				throw new KillTheNewsException('Invalid HTTP request');
			}
			$ch = curl_init();
			if ($ch === false) {
				throw new KillTheNewsException('Unable to initialize HTTP client');
			}
			$headerLines = [];
			foreach ($headers as $name => $value) {
				if (!is_string($name) || !is_string($value) || $name === '') {
					throw new KillTheNewsException('Invalid HTTP request headers');
				}
				$headerLines[] = $name . ': ' . $value;
			}
			if ($headerLines === []) {
				throw new KillTheNewsException('Invalid HTTP request headers');
			}
			curl_setopt_array($ch, [
				CURLOPT_URL => $url,
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $headerLines,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 20,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => $verifyTls,
				CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
				CURLOPT_USERAGENT => 'FreshRSS-KillTheNews',
			]);
			if ($body !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}
			$raw = curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);
			if ($raw === false) {
				throw new KillTheNewsException('Connection error: ' . $error);
			}
			return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
		};
		return new self($instanceUrl, $apiToken, $transport);
	}

	public static function normalizeBaseUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		// Detect any explicit URI scheme so unsupported schemes are rejected
		// instead of being treated as a bare hostname and prefixed with https://.
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && !preg_match('#^https?://#i', $url)) {
			throw new KillTheNewsException('Invalid kill-the-news instance URL');
		}
		// Only http(s) URLs are accepted as-is; values without a scheme are
		// considered hostnames and normalized to https:// by default.
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}
		$url = rtrim($url, '/');
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new KillTheNewsException('Invalid kill-the-news instance URL');
		}
		$scheme = parse_url($url, PHP_URL_SCHEME);
		$host = parse_url($url, PHP_URL_HOST);
		$scheme = is_string($scheme) ? strtolower($scheme) : '';
		$host = is_string($host) ? $host : '';
		if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
			throw new KillTheNewsException('Invalid kill-the-news instance URL');
		}
		return $url;
	}

	public static function buildApiUrl(string $baseUrl, string $path): string {
		return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
	}

	/**
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string,feedUrl:string}
	 */
	public static function parseFeed(string $body): array {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new KillTheNewsException('Invalid JSON response from kill-the-news');
		}
		/** @var array<string,mixed> $data */
		return self::mapFeed($data);
	}

	/**
	 * @return list<array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string,feedUrl:string}>
	 */
	public static function parseFeedList(string $body): array {
		$data = json_decode($body, true);
		if (!is_array($data) || !isset($data['feeds']) || !is_array($data['feeds'])) {
			throw new KillTheNewsException('Invalid JSON response from kill-the-news');
		}
		$feeds = [];
		foreach ($data['feeds'] as $raw) {
			// Skip malformed entries defensively; the newsletter list is best-effort display.
			try {
				if (!is_array($raw)) {
					self::logMalformedFeedEntry('entry is not an object');
					continue;
				}
				/** @var array<string,mixed> $raw */
				$feeds[] = self::mapFeed($raw);
			} catch (KillTheNewsException $e) {
				self::logMalformedFeedEntry($e->getMessage());
				continue;
			}
		}
		return $feeds;
	}

	public static function errorMessage(int $status, string $body): string {
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
			return $data['error'];
		}
		return 'kill-the-news returned HTTP ' . $status;
	}

	/**
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string,feedUrl:string}
	 */
	public function createFeed(string $title): array {
		$title = trim($title);
		if ($title === '') {
			throw new KillTheNewsException('Newsletter title is required');
		}
		$url = self::buildApiUrl($this->instanceUrl, self::FEEDS_PATH);
		$headers = $this->authHeaders();
		$headers['Content-Type'] = 'application/json';
		$body = json_encode(['title' => $title]);
		if ($body === false) {
			throw new KillTheNewsException('Unable to encode request body');
		}
		$response = ($this->transport)('POST', $url, $headers, $body);
		if ($response['status'] !== 201) {
			throw new KillTheNewsException(self::errorMessage($response['status'], $response['body']));
		}
		return self::parseFeed($response['body']);
	}

	/**
	 * @return list<array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string,feedUrl:string}>
	 */
	public function listFeeds(): array {
		$url = self::buildApiUrl($this->instanceUrl, self::FEEDS_PATH);
		$response = ($this->transport)('GET', $url, $this->authHeaders(), null);
		if ($response['status'] !== 200) {
			throw new KillTheNewsException(self::errorMessage($response['status'], $response['body']));
		}
		return self::parseFeedList($response['body']);
	}

	/** @return array<string,string> */
	private function authHeaders(): array {
		return [
			'Authorization' => 'Bearer ' . $this->apiToken,
			'Accept' => 'application/json',
		];
	}

	private static function logMalformedFeedEntry(string $reason): void {
		error_log('[KillTheNews] Skipping malformed feed entry from kill-the-news: ' . $reason);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string,feedUrl:string}
	 */
	private static function mapFeed(array $raw): array {
		$id = isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : '';
		$email = isset($raw['emailAddress']) && is_string($raw['emailAddress']) ? $raw['emailAddress'] : '';
		$rss = isset($raw['rssUrl']) && is_string($raw['rssUrl']) ? $raw['rssUrl'] : '';
		$atom = isset($raw['atomUrl']) && is_string($raw['atomUrl']) ? $raw['atomUrl'] : '';
		if ($id === '' || $email === '' || ($rss === '' && $atom === '')) {
			throw new KillTheNewsException('Unexpected feed payload from kill-the-news');
		}
		return [
			'id' => $id,
			'title' => isset($raw['title']) && is_string($raw['title']) ? $raw['title'] : '',
			'emailAddress' => $email,
			'rssUrl' => $rss,
			'atomUrl' => $atom,
			'feedUrl' => $atom !== '' ? $atom : $rss,
		];
	}
}
