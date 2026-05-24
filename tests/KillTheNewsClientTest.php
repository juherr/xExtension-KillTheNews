<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KillTheNewsClientTest extends TestCase {
	public function testNormalizeBaseUrlAddsHttpsAndTrimsTrailingSlash(): void {
		self::assertSame('https://news.example.com', KillTheNewsClient::normalizeBaseUrl('news.example.com/'));
		self::assertSame('https://news.example.com', KillTheNewsClient::normalizeBaseUrl('  https://news.example.com  '));
		self::assertSame('http://localhost:8787', KillTheNewsClient::normalizeBaseUrl('http://localhost:8787/'));
		self::assertSame('', KillTheNewsClient::normalizeBaseUrl('   '));
	}

	public function testNormalizeBaseUrlRejectsInvalidUrl(): void {
		$this->expectException(KillTheNewsException::class);
		KillTheNewsClient::normalizeBaseUrl('not a url');
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidSchemeProvider')]
	public function testNormalizeBaseUrlRejectsUnsupportedSchemes(string $url): void {
		$this->expectException(KillTheNewsException::class);
		KillTheNewsClient::normalizeBaseUrl($url);
	}

	/** @return iterable<string,array{string}> */
	public static function invalidSchemeProvider(): iterable {
		yield 'ftp' => ['ftp://news.example.com'];
		yield 'javascript' => ['javascript://alert'];
		yield 'host missing' => ['https:///feeds'];
	}

	public function testBuildApiUrlJoinsBaseAndPath(): void {
		self::assertSame(
			'https://news.example.com/api/v1/feeds',
			KillTheNewsClient::buildApiUrl('https://news.example.com', '/api/v1/feeds')
		);
		self::assertSame(
			'https://news.example.com/api/v1/feeds',
			KillTheNewsClient::buildApiUrl('https://news.example.com/', '/api/v1/feeds')
		);
		self::assertSame(
			'https://news.example.com/api/v1/feeds',
			KillTheNewsClient::buildApiUrl('https://news.example.com', 'api/v1/feeds')
		);
	}

	public function testParseFeedExtractsFields(): void {
		$body = json_encode([
			'id' => 'happy-otter-1234',
			'title' => 'My News',
			'emailAddress' => 'happy.otter.1234@news.example.com',
			'rssUrl' => 'https://news.example.com/rss/happy-otter-1234',
			'atomUrl' => 'https://news.example.com/atom/happy-otter-1234',
			'allowedSenders' => [],
			'blockedSenders' => [],
			'createdAt' => 1737000000,
			'emailCount' => 0,
			'language' => 'en',
		]);
		$feed = KillTheNewsClient::parseFeed((string) $body);
		self::assertSame('happy-otter-1234', $feed['id']);
		self::assertSame('My News', $feed['title']);
		self::assertSame('happy.otter.1234@news.example.com', $feed['emailAddress']);
		self::assertSame('https://news.example.com/rss/happy-otter-1234', $feed['rssUrl']);
		self::assertSame('https://news.example.com/atom/happy-otter-1234', $feed['atomUrl']);
		self::assertSame('https://news.example.com/atom/happy-otter-1234', $feed['feedUrl']);
	}

	public function testParseFeedFallsBackToRssWhenAtomIsMissing(): void {
		$body = json_encode([
			'id' => 'happy-otter-1234',
			'title' => 'My News',
			'emailAddress' => 'happy.otter.1234@news.example.com',
			'rssUrl' => 'https://news.example.com/rss/happy-otter-1234',
		]);
		$feed = KillTheNewsClient::parseFeed((string) $body);
		self::assertSame('', $feed['atomUrl']);
		self::assertSame('https://news.example.com/rss/happy-otter-1234', $feed['feedUrl']);
	}

	public function testParseFeedThrowsOnInvalidJson(): void {
		$this->expectException(KillTheNewsException::class);
		KillTheNewsClient::parseFeed('not json');
	}

	public function testParseFeedThrowsWhenEmailMissing(): void {
		$this->expectException(KillTheNewsException::class);
		KillTheNewsClient::parseFeed((string) json_encode(['id' => 'x', 'rssUrl' => 'https://y']));
	}

	public function testParseFeedUsesAtomWhenRssUrlIsMissing(): void {
		$body = json_encode([
			'id' => 'x',
			'emailAddress' => 'x@example.com',
			'atomUrl' => 'https://example.com/atom/x',
		]);
		$feed = KillTheNewsClient::parseFeed((string) $body);
		self::assertSame('', $feed['rssUrl']);
		self::assertSame('https://example.com/atom/x', $feed['feedUrl']);
	}

	public function testParseFeedThrowsWhenBothFeedUrlsAreMissing(): void {
		$this->expectException(KillTheNewsException::class);
		KillTheNewsClient::parseFeed((string) json_encode(['id' => 'x', 'emailAddress' => 'x@example.com']));
	}

	public function testParseFeedListMapsEntries(): void {
		$body = json_encode([
			'feeds' => [
				[
					'id' => 'a-b-1',
					'title' => 'One',
					'emailAddress' => 'a.b.1@news.example.com',
					'rssUrl' => 'https://news.example.com/rss/a-b-1',
					'atomUrl' => 'https://news.example.com/atom/a-b-1',
				],
			],
		]);
		$feeds = KillTheNewsClient::parseFeedList((string) $body);
		self::assertCount(1, $feeds);
		self::assertSame('a.b.1@news.example.com', $feeds[0]['emailAddress']);
		self::assertSame('One', $feeds[0]['title']);
	}

	public function testParseFeedListReturnsEmptyArrayWhenNoFeeds(): void {
		self::assertSame([], KillTheNewsClient::parseFeedList((string) json_encode(['feeds' => []])));
	}

	public function testParseFeedListSkipsMalformedEntries(): void {
		$body = json_encode([
			'feeds' => [
				'not an array',
				['id' => 'missing-fields'],
				[
					'id' => 'valid-1',
					'title' => 'Valid',
					'emailAddress' => 'valid.1@news.example.com',
					'rssUrl' => 'https://news.example.com/rss/valid-1',
					'atomUrl' => 'https://news.example.com/atom/valid-1',
				],
			],
		]);
		$feeds = KillTheNewsClient::parseFeedList((string) $body);
		self::assertCount(1, $feeds);
		self::assertSame('valid.1@news.example.com', $feeds[0]['emailAddress']);
	}

	public function testErrorMessageUsesErrorField(): void {
		$msg = KillTheNewsClient::errorMessage(401, (string) json_encode(['error' => 'Unauthorized']));
		self::assertStringContainsString('Unauthorized', $msg);
	}

	public function testErrorMessageFallsBackToStatus(): void {
		$msg = KillTheNewsClient::errorMessage(500, 'gateway exploded');
		self::assertStringContainsString('500', $msg);
	}

	public function testCreateFeedSendsPostWithAuthAndParsesResult(): void {
		/** @var array{method:string,url:string,headers:array<string,string>,body:string|null} $captured */
		$captured = ['method' => '', 'url' => '', 'headers' => [], 'body' => null];
		$transport = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
			$captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
			return [
				'status' => 201,
				'body' => (string) json_encode([
					'id' => 'happy-otter-1234',
					'title' => 'My News',
					'emailAddress' => 'happy.otter.1234@news.example.com',
					'rssUrl' => 'https://news.example.com/rss/happy-otter-1234',
					'atomUrl' => 'https://news.example.com/atom/happy-otter-1234',
				]),
			];
		};
		$client = new KillTheNewsClient('https://news.example.com', 'secret-token', $transport);
		$feed = $client->createFeed('My News');

		self::assertSame('POST', $captured['method']);
		self::assertSame('https://news.example.com/api/v1/feeds', $captured['url']);
		self::assertSame('Bearer secret-token', $captured['headers']['Authorization']);
		self::assertSame('application/json', $captured['headers']['Content-Type']);
		self::assertIsString($captured['body']);
		self::assertSame(['title' => 'My News'], json_decode($captured['body'], true));
		self::assertSame('happy.otter.1234@news.example.com', $feed['emailAddress']);
		self::assertSame('https://news.example.com/atom/happy-otter-1234', $feed['feedUrl']);
	}

	public function testCreateFeedThrowsOnNon201(): void {
		$transport = fn (string $m, string $u, array $h, ?string $b): array
			=> ['status' => 401, 'body' => (string) json_encode(['error' => 'Unauthorized'])];
		$client = new KillTheNewsClient('https://news.example.com', 'bad', $transport);
		$this->expectException(KillTheNewsException::class);
		$this->expectExceptionMessage('Unauthorized');
		$client->createFeed('My News');
	}

	public function testCreateFeedThrowsWhenTitleIsEmpty(): void {
		$transportCalled = false;
		$transport = function (string $method, string $url, array $headers, ?string $body) use (&$transportCalled): array {
			$transportCalled = true;
			return ['status' => 500, 'body' => ''];
		};
		$client = new KillTheNewsClient('https://news.example.com', 'secret-token', $transport);
		try {
			$client->createFeed('   ');
			self::fail('Expected KillTheNewsException');
		} catch (KillTheNewsException $e) {
			self::assertSame('Newsletter title is required', $e->getMessage());
			self::assertFalse($transportCalled);
		}
	}

	public function testCreateFeedPropagatesTransportFailure(): void {
		$transport = function (string $method, string $url, array $headers, ?string $body): array {
			throw new KillTheNewsException('Connection error: boom');
		};
		$client = new KillTheNewsClient('https://news.example.com', 'secret-token', $transport);
		$this->expectException(KillTheNewsException::class);
		$this->expectExceptionMessage('Connection error: boom');
		$client->createFeed('My News');
	}

	public function testListFeedsSendsGetWithAuthAndParses(): void {
		/** @var array{method:string,url:string,headers:array<string,string>} $captured */
		$captured = ['method' => '', 'url' => '', 'headers' => []];
		$transport = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
			$captured = ['method' => $method, 'url' => $url, 'headers' => $headers];
			return [
				'status' => 200,
				'body' => (string) json_encode(['feeds' => [[
					'id' => 'a-b-1',
					'title' => 'One',
					'emailAddress' => 'a.b.1@news.example.com',
					'rssUrl' => 'https://news.example.com/rss/a-b-1',
					'atomUrl' => 'https://news.example.com/atom/a-b-1',
				]]]),
			];
		};
		$client = new KillTheNewsClient('https://news.example.com', 'secret-token', $transport);
		$feeds = $client->listFeeds();

		self::assertSame('GET', $captured['method']);
		self::assertSame('https://news.example.com/api/v1/feeds', $captured['url']);
		self::assertSame('Bearer secret-token', $captured['headers']['Authorization']);
		self::assertCount(1, $feeds);
		self::assertSame('One', $feeds[0]['title']);
	}

	public function testListFeedsThrowsOnNon200(): void {
		$transport = fn (string $m, string $u, array $h, ?string $b): array
			=> ['status' => 500, 'body' => ''];
		$client = new KillTheNewsClient('https://news.example.com', 'secret-token', $transport);
		$this->expectException(KillTheNewsException::class);
		$client->listFeeds();
	}

	public function testCreateFeedEncodesSpecialCharactersInTitle(): void {
		$captured = [];
		$transport = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
			$captured = ['body' => $body];
			return ['status' => 201, 'body' => (string) json_encode([
				'id' => 'x-1',
				'title' => 'x',
				'emailAddress' => 'x.1@news.example.com',
				'rssUrl' => 'https://news.example.com/rss/x-1',
				'atomUrl' => 'https://news.example.com/atom/x-1',
			])];
		};
		$client = new KillTheNewsClient('https://news.example.com', 't', $transport);
		$client->createFeed('Café "News" & Co');
		self::assertSame(['title' => 'Café "News" & Co'], json_decode((string) $captured['body'], true));
	}
}
