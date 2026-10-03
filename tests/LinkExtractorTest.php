<?php
/**
 * LinkExtractor 测试。
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
 * LinkExtractor 测试。
 */
class LinkExtractorTest extends TestCase
{
    public function testClassifiesLinkTypes(): void
    {
        $html = <<<'HTML'
<a href="https://example.com/page">站内</a>
<a href="https://other.com/page">站外</a>
<a href="/relative">相对</a>
<a href="#anchor">锚点</a>
<a href="//cdn.example.com/x">协议相对</a>
<a href="mailto:a@b.com">邮件</a>
<a href="tel:+123">电话</a>
<a href="javascript:alert(1)">危险</a>
HTML;

        $types = [];
        foreach ((new LinkExtractor('example.com'))->extract($html) as $link) {
            $types[$link['anchor']] = $link['type'];
        }

        self::assertSame(LinkExtractor::TYPE_INTERNAL, $types['站内']);
        self::assertSame(LinkExtractor::TYPE_EXTERNAL, $types['站外']);
        self::assertSame(LinkExtractor::TYPE_ANCHOR, $types['锚点']);
        self::assertSame(LinkExtractor::TYPE_PROTOCOL_RELATIVE, $types['协议相对']);
        self::assertSame(LinkExtractor::TYPE_MAILTO, $types['邮件']);
        self::assertSame(LinkExtractor::TYPE_TEL, $types['电话']);
        self::assertSame(LinkExtractor::TYPE_DANGEROUS, $types['危险']);
    }

    public function testDetectsDangerousSchemes(): void
    {
        $html = '<a href="javascript:alert(1)">x</a><a href="vbscript:x">y</a><a href="data:text/html,x">z</a>';

        $result = (new LinkExtractor())->analyze($html);
        self::assertSame(3, $result['dangerous_count']);
        self::assertSame('danger', $result['assessment']['level']);
    }

    public function testRelAttributes(): void
    {
        $html = '<a href="https://other.com/" rel="nofollow noopener ugc sponsored" target="_blank">x</a>';
        $link = (new LinkExtractor('example.com'))->extract($html)[0];

        self::assertTrue($link['nofollow']);
        self::assertTrue($link['noopener']);
        self::assertTrue($link['ugc']);
        self::assertTrue($link['sponsored']);
    }

    public function testNoreferrerImpliesNoopenerForBlankTarget(): void
    {
        $html = '<a href="https://other.com/" rel="noreferrer" target="_blank">x</a>';
        $link = (new LinkExtractor('example.com'))->extract($html)[0];

        self::assertTrue($link['noopener'], 'noreferrer + _blank 等价于 noopener');
    }

    public function testDeduplicatesIdenticalLinks(): void
    {
        $html = '<a href="/a">x</a><a href="/a">x</a>';
        self::assertCount(1, (new LinkExtractor())->extract($html));
    }

    public function testEmptyInput(): void
    {
        self::assertCount(0, (new LinkExtractor())->extract(''));
        self::assertCount(0, (new LinkExtractor())->extract('no links here'));
    }

    public function testStripNonContentRemovesNavigation(): void
    {
        $html = <<<'HTML'
<nav><a href="/nav1">导航</a></nav>
<footer><a href="/foot">页脚</a></footer>
<div class="sidebar"><a href="/side">侧栏</a></div>
<article><a href="/real">正文</a></article>
HTML;

        $links = (new LinkExtractor('example.com'))->extractContent($html);
        $urls  = array_column($links, 'url');
        $joined = implode(' ', $urls);

        self::assertStringContains('/real', $joined, '正文链接应保留');
        self::assertStringNotContains('nav1', $joined);
        self::assertStringNotContains('/foot', $joined);
        self::assertStringNotContains('/side', $joined);
    }

    public function testSingleQuotedAndUnquotedHref(): void
    {
        $html = "<a href='/single'>x</a><a href=/unquoted>y</a>";
        $links = (new LinkExtractor())->extract($html);

        self::assertCount(2, $links);
    }

    public function testEntityEncodedHref(): void
    {
        $html  = '<a href="/search?a=1&amp;b=2">x</a>';
        $links = (new LinkExtractor())->extract($html);

        self::assertStringContains('&b=2', $links[0]['url'], '实体应被解码');
    }

    public function testAnalyzeShape(): void
    {
        $result = (new LinkExtractor('example.com'))->analyze($this->sampleHtml());

        foreach (['total', 'by_type', 'unique_urls', 'external_ratio', 'assessment', 'links'] as $key) {
            self::assertArrayHasKey($key, $result);
        }
        self::assertTrue($result['total'] > 0);
    }

    public function testNoSiteHostStillClassifiesAbsoluteUrls(): void
    {
        $links = (new LinkExtractor())->extract('<a href="https://a.com/x">x</a>');
        self::assertSame(LinkExtractor::TYPE_EXTERNAL, $links[0]['type']);
    }

    public function testMalformedHtmlDoesNotCrash(): void
    {
        $extractor = new LinkExtractor();
        $inputs    = [
            '<a href="unclosed',
            '<a>无 href</a>',
            '<a href="">空</a>',
            str_repeat('<a href="/x">y</a>', 2000),
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(function () use ($extractor, $input): void {
                $extractor->analyze($input);
            });
        }
    }

    public function testMultibyteAnchors(): void
    {
        $links = (new LinkExtractor())->extract('<a href="/a">中文链接 🎉</a>');
        self::assertSame('中文链接 🎉', $links[0]['anchor']);
    }

    /**
     * 样例页面。
     */
    private function sampleHtml(): string
    {
        return <<<'HTML'
<a href="https://example.com/a">站内</a>
<a href="https://other.com/b">站外</a>
<a href="https://other.com/c">站外2</a>
<a href="/page/2/">分页</a>
HTML;
    }
}
