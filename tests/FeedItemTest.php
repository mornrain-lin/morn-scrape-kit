<?php
/**
 * FeedItem 测试。
 *
 * @package MornRain\ScrapeKit\Tests
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit\Tests;

use MornRain\ScrapeKit\FeedItem;
use MornRain\ScrapeKit\HtmlCleaner;
use MornRain\ScrapeKit\LinkExtractor;
use MornRain\ScrapeKit\MetaExtractor;
use MornRain\ScrapeKit\RssParser;

/**
 * FeedItem 测试。
 */
class FeedItemTest extends TestCase
{
    public function testTitleFallsBackToContent(): void
    {
        $item = new FeedItem();
        $item->setContent('<p>'.str_repeat('正文内容', 50).'</p>');

        self::assertTrue($item->title() !== '', '缺标题时应从正文截取');
        self::assertTrue(mb_strlen($item->title()) <= 80);
    }

    public function testGuidFallsBackToLink(): void
    {
        $item = new FeedItem();
        $item->setLink('https://example.com/1');

        self::assertSame('https://example.com/1', $item->guid());
    }

    public function testAuthorFallsBackToEmail(): void
    {
        $item = new FeedItem();
        $item->setAuthorEmail('a@b.com');

        self::assertSame('a@b.com', $item->author());
    }

    public function testTimestampParsing(): void
    {
        $item = new FeedItem();
        $item->setPublishedAt('Mon, 05 Jan 2026 10:00:00 +0800');
        self::assertTrue($item->publishedAt() > 0);

        $item2 = new FeedItem();
        $item2->setPublishedAt(1767000000);
        self::assertSame(1767000000, $item2->publishedAt());

        $item3 = new FeedItem();
        $item3->setPublishedAt('完全无法解析的日期');
        self::assertSame(0, $item3->publishedAt());
    }

    public function testInvalidTimestampsBecomeZero(): void
    {
        $item = new FeedItem();
        $item->setPublishedAt(null);
        self::assertSame(0, $item->publishedAt());
    }

    public function testCategoriesAreDeduplicated(): void
    {
        $item = new FeedItem();
        $item->addCategory('a')->addCategory('a')->addCategory('b');

        self::assertCount(2, $item->categories());
    }

    public function testEmptyCategoryIgnored(): void
    {
        $item = new FeedItem();
        $item->addCategory('   ')->addCategory('');

        self::assertCount(0, $item->categories());
    }

    public function testEnclosures(): void
    {
        $item = new FeedItem();
        $item->addEnclosure('https://e.com/a.mp3', 'audio/mpeg', 1024);
        $item->addEnclosure('');

        self::assertCount(1, $item->enclosures());
        self::assertSame(1024, $item->enclosureMeta()['https://e.com/a.mp3']['length']);
    }

    public function testContentTypeValidation(): void
    {
        $item = new FeedItem();
        $item->setContentType('html');
        self::assertSame('html', $item->contentType());

        $item->setContentType('bogus');
        self::assertSame('html', $item->contentType(), '非法类型应保持原值');
    }

    public function testPlainSummaryTruncates(): void
    {
        $item = new FeedItem();
        $item->setSummary(str_repeat('摘要', 200));

        self::assertTrue(mb_strlen($item->plainSummary(50)) <= 51);
    }

    public function testToArrayShape(): void
    {
        $array = (new FeedItem())->toArray();

        foreach (['title', 'content', 'link', 'guid', 'published_iso', 'categories', 'enclosures'] as $key) {
            self::assertArrayHasKey($key, $array);
        }
    }

    public function testConstructorIgnoresUnknownKeys(): void
    {
        // 动态 setter 不能被任意键名触发
        $item = new FeedItem(['title' => 'T', 'unknown_key' => 'x', 'class' => 'y']);

        self::assertSame('T', $item->title());
    }

    public function testDescribeIsReadable(): void
    {
        $item = new FeedItem(['title' => '标题', 'link' => 'https://e.com/1']);
        $desc = $item->describe();

        self::assertStringContains('标题', $desc);
        self::assertStringContains('https://e.com/1', $desc);
    }

    public function testCommentCountCoercion(): void
    {
        $item = new FeedItem();
        $item->setCommentCount('42');
        self::assertSame(42, $item->commentCount());

        $item->setCommentCount('不是数字');
        self::assertSame(0, $item->commentCount());
    }
}
