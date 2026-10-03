<?php
/**
 * MetaExtractor 测试。
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
 * MetaExtractor 测试。
 */
class MetaExtractorTest extends TestCase
{
    /**
     * 构造一份完整页面。
     */
    private function html(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>  示例页面标题  </title>
<meta name="description" content="页面描述">
<meta name="keywords" content="php,wordpress，安全">
<meta name="author" content="林墨">
<meta name="robots" content="index,follow">
<meta name="viewport" content="width=device-width">
<meta name="generator" content="WordPress 6.4">
<meta property="og:title" content="OG 标题">
<meta property="og:image" content="https://example.com/og.png">
<meta name="twitter:card" content="summary_large_image">
<meta property="article:published_time" content="2026-01-01T10:00:00+08:00">
<link rel="canonical" href="https://example.com/post">
<link rel="alternate" hreflang="zh-CN" href="https://example.com/zh/post">
<link rel="alternate" hreflang="en" href="https://example.com/en/post">
<link rel="alternate" type="application/rss+xml" href="https://example.com/feed">
<link rel="icon" href="/favicon.ico">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"LD 标题"}</script>
<script type="application/ld+json">{invalid json}</script>
</head>
<body>
<img src="https://example.com/a.jpg">
<img src="data:image/png;base64,iVBORw0KGgo=">
<img src="https://example.com/b.jpg">
</body>
</html>
HTML;
    }

    public function testBasicSeoFields(): void
    {
        $data = (new MetaExtractor('example.com'))->extract($this->html());

        self::assertSame('示例页面标题', $data['title']);
        self::assertSame('页面描述', $data['description']);
        self::assertSame('林墨', $data['author']);
        self::assertSame('index,follow', $data['robots']);
        self::assertSame('width=device-width', $data['viewport']);
        self::assertSame('utf-8', $data['charset']);
        self::assertSame('zh-cn', $data['lang']);
        self::assertSame('WordPress 6.4', $data['generator']);
    }

    public function testKeywordsAreSplit(): void
    {
        $data = (new MetaExtractor())->extract($this->html());

        self::assertCount(3, $data['keywords']);
        self::assertContains('php', $data['keywords']);
        self::assertContains('安全', $data['keywords']);
    }

    public function testCanonicalAndHreflang(): void
    {
        $data = (new MetaExtractor())->extract($this->html());

        self::assertSame('https://example.com/post', $data['canonical']);
        self::assertArrayHasKey('zh-cn', $data['hreflang']);
        self::assertArrayHasKey('en', $data['hreflang']);
        self::assertCount(1, $data['alternate'], '无 hreflang 的 alternate 应单独归类');
    }

    public function testFavicon(): void
    {
        $data = (new MetaExtractor())->extract($this->html());
        self::assertSame('/favicon.ico', $data['favicon']);
    }

    public function testOpenGraphAndTwitter(): void
    {
        $data = (new MetaExtractor())->extract($this->html());

        self::assertArrayHasKey('og:title', $data['og']);
        self::assertArrayHasKey('og:image', $data['og']);
        self::assertArrayHasKey('twitter:card', $data['twitter']);
        self::assertArrayHasKey('article:published_time', $data['article']);
    }

    public function testJsonLdExtraction(): void
    {
        $data = (new MetaExtractor())->extract($this->html());

        // 合法 JSON-LD 解析，非法的那段被跳过
        self::assertSame(1, $data['json_ld_count']);
        self::assertSame('LD 标题', $data['json_ld'][0]['headline']);
    }

    public function testImagesExcludeDataUri(): void
    {
        $data = (new MetaExtractor())->extract($this->html());

        self::assertContains('https://example.com/og.png', $data['images']);
        self::assertNotContains('data:image/png;base64,iVBORw0KGgo=', $data['images'], 'data: 图片应被排除');
        self::assertSame(3, count($data['images']));
    }

    public function testEmptyInputReturnsSkeleton(): void
    {
        $data = (new MetaExtractor())->extract('');

        self::assertSame('', $data['title']);
        self::assertCount(0, $data['keywords']);
        self::assertCount(0, $data['json_ld']);
        foreach (['title', 'description', 'og', 'twitter', 'hreflang'] as $key) {
            self::assertArrayHasKey($key, $data, "缺输入时仍应返回 {$key} 键");
        }
    }

    public function testSingleFieldHelpers(): void
    {
        $extractor = new MetaExtractor();

        self::assertSame('示例页面标题', $extractor->title($this->html()));
        self::assertSame('页面描述', $extractor->description($this->html()));
        self::assertSame('https://example.com/post', $extractor->canonical($this->html()));
        self::assertCount(1, $extractor->jsonLd($this->html()));
        self::assertArrayHasKey('zh-cn', $extractor->hreflang($this->html()));
    }

    public function testEntityDecodingInTitle(): void
    {
        $extractor = new MetaExtractor();
        $data      = $extractor->extract('<title>A &amp; B &lt;C&gt; 中文</title>');

        self::assertSame('A & B <C> 中文', $data['title']);
    }

    public function testMalformedHtmlDoesNotCrash(): void
    {
        $extractor = new MetaExtractor();
        $inputs    = [
            '<title>未闭合',
            '<meta name="description"',
            '<<<>>>',
            str_repeat('<meta name="a" content="b">', 3000),
            '<title>' . str_repeat('长', 5000) . '</title>',
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(function () use ($extractor, $input): void {
                $extractor->extract($input);
            });
        }
    }

    public function testAnalyzeReportsIssues(): void
    {
        $report = (new MetaExtractor())->analyze('<html><head></head><body></body></html>');

        self::assertTrue($report['issue_count'] > 0, '空页面应报多个问题');
        self::assertTrue($report['score'] < 100);
        self::assertArrayHasKey('data', $report);
    }

    public function testAnalyzeFlagsLongTitle(): void
    {
        $report = (new MetaExtractor())->analyze('<title>' . str_repeat('字', 100) . '</title>');
        $joined = implode("\n", array_column($report['issues'], 'message'));

        self::assertStringContains('title 长', $joined);
    }

    public function testAnalyzeOnGoodPageScoresHigh(): void
    {
        $report = (new MetaExtractor())->analyze($this->html());
        self::assertTrue($report['score'] > 60, '信息完整的页面应得较高分，实际 ' . $report['score']);
    }
}
