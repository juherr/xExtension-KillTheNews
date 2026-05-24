<?php
declare(strict_types=1);

final class KillTheNewsException extends RuntimeException {
}

final class KillTheNewsClient {
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
		$transport = static function (string $method, string $url, array $headers, ?string $body) use ($verifyTls): array {
			$ch = curl_init();
			if ($ch === false) {
				throw new KillTheNewsException('Unable to initialize HTTP client');
			}
			$headerLines = [];
			foreach ($headers as $name => $value) {
				$headerLines[] = $name . ': ' . $value;
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
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}
		return rtrim($url, '/');
	}

	public static function buildApiUrl(string $baseUrl, string $path): string {
		return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
	}

	/**
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string}
	 */
	public static function parseFeed(string $body): array {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new KillTheNewsException('Invalid JSON response from kill-the-news');
		}
		return self::mapFeed($data);
	}

	/**
	 * @return list<array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string}>
	 */
	public static function parseFeedList(string $body): array {
		$data = json_decode($body, true);
		if (!is_array($data) || !isset($data['feeds']) || !is_array($data['feeds'])) {
			throw new KillTheNewsException('Invalid JSON response from kill-the-news');
		}
		$feeds = [];
		foreach ($data['feeds'] as $raw) {
			// Skip malformed entries defensively; the newsletter list is best-effort display.
			if (is_array($raw)) {
				$feeds[] = self::mapFeed($raw);
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
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string}
	 */
	public function createFeed(string $title): array {
		$url = self::buildApiUrl($this->instanceUrl, '/api/v1/feeds');
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
	 * @return list<array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string}>
	 */
	public function listFeeds(): array {
		$url = self::buildApiUrl($this->instanceUrl, '/api/v1/feeds');
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

	/**
	 * @param array<string,mixed> $raw
	 * @return array{id:string,title:string,emailAddress:string,rssUrl:string,atomUrl:string}
	 */
	private static function mapFeed(array $raw): array {
		$id = isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : '';
		$email = isset($raw['emailAddress']) && is_string($raw['emailAddress']) ? $raw['emailAddress'] : '';
		$rss = isset($raw['rssUrl']) && is_string($raw['rssUrl']) ? $raw['rssUrl'] : '';
		if ($id === '' || $email === '' || $rss === '') {
			throw new KillTheNewsException('Unexpected feed payload from kill-the-news');
		}
		return [
			'id' => $id,
			'title' => isset($raw['title']) && is_string($raw['title']) ? $raw['title'] : '',
			'emailAddress' => $email,
			'rssUrl' => $rss,
			'atomUrl' => isset($raw['atomUrl']) && is_string($raw['atomUrl']) ? $raw['atomUrl'] : '',
		];
	}
}
