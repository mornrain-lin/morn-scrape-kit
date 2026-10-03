<?php
/**
 * HtmlCleaner 测试。
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
 * HtmlCleaner 测试。
 */
class HtmlCleanerTest extends TestCase
{
    public function testRemovesDangerousBlocks(): void
    {
        $html = '<div>keep</div><script>bad()</script><style>x{}</style><iframe src="//e"></iframe>';
        $out  = (new HtmlCleaner())->clean($html);

        self::assertStringContains('keep', $out);
        self::assertStringNotContains('script', $out);
        self::assertStringNotContains('iframe', $out);
    }

    public function testRemovesEventHandlers(): void
    {
        $out = (new HtmlCleaner())->clean('<div onclick="x()" onmouseover=\'y\'>t</div>');

        self::assertStringNotContains('onclick', $out);
        self::assertStringNotContains('onmouseover', $out);
    }

    public function testRemovesSrcdoc(): void
    {
        $out = (new HtmlCleaner())->clean('<div srcdoc="<script>x</script>">t</div>');
        self::assertStringNotContains('srcdoc', $out);
    }

    public function testDangerousUrlsAreStripped(): void
    {
        $out = (new HtmlCleaner())->clean('<a href="javascript:alert(1)">x</a>');

        self::assertStringNotContains('javascript:', $out);
    }

    public function testControlCharacterUrlBypassIsBlocked(): void
    {
        $out = (new HtmlCleaner())->clean('<a href="java&#9;script:alert(1)">x</a>');

        self::assertStringNotContains('script:', $out);
    }

    public function testSafeUrlsAreKept(): void
    {
        $cleaner = new HtmlCleaner();
        $out     = $cleaner->clean('<a href="https://example.com/a?x=1&amp;y=2">x</a>');

        self::assertStringContains('href=', $out);
        self::assertStringContains('example.com', $out);
    }

    public function testAttributeValuesAreEscaped(): void
    {
        $out = (new HtmlCleaner())->clean('<a href="https://e.com/" title=\'He said "hi"\'>x</a>');

        self::assertStringNotContains('"hi"', $out);
        self::assertStringContains('&quot;', $out);
    }

    public function testTargetBlankGetsNoopener(): void
    {
        $out = (new HtmlCleaner())->clean('<a href="https://e.com/" target="_blank">x</a>');

        self::assertStringContains('target="_blank"', $out);
        self::assertStringContains('noopener', $out);
        // 不得重复追加两次 rel
        self::assertSame(1, substr_count($out, 'rel='), 'rel 属性只能出现一次');
    }

    public function testStripLinksKeepsText(): void
    {
        $out = (new HtmlCleaner())->stripLinks()->clean('<a href="https://e.com/">文本</a>');

        self::assertStringContains('文本', $out);
        self::assertStringNotContains('<a', $out);
        self::assertStringNotContains('</a>', $out, '闭合标签也必须移除');
    }

    public function testStripImages(): void
    {
        $out = (new HtmlCleaner())->stripImages()->clean('<p>文字<img src="https://e.com/a.jpg" alt="x"></p>');

        // 整段丢弃 img，保留其余内容
        self::assertStringNotContains('<img', $out);
        self::assertStringNotContains('a.jpg', $out);
        self::assertStringContains('文字', $out);
    }

    public function testStyleIsStrippedByDefault(): void
    {
        $out = (new HtmlCleaner())->clean('<p style="color:red">x</p>');
        self::assertStringNotContains('style', $out);
    }

    public function testKeepStyleSanitisesDeclarations(): void
    {
        $out = (new HtmlCleaner())->keepStyle()->clean('<p style="color:red;behavior:url(x)">y</p>');

        self::assertStringContains('color', $out);
        self::assertStringNotContains('behavior', $out);
    }

    public function testClassAndIdAreFiltered(): void
    {
        $out = (new HtmlCleaner())->clean('<div class="ok bad<>value" id="fine">x</div>');

        // class 内的非法字符被剔除，且不得残留裸尖括号（否则可突破属性上下文）
        self::assertStringContains('class="ok badvalue"', $out);
        self::assertStringNotContains('<>', $out);
        self::assertStringContains('id="fine"', $out);
    }

    public function testToText(): void
    {
        $cleaner = new HtmlCleaner();
        $text    = $cleaner->toText('<p>第一段</p><p>第二段</p>');

        self::assertStringContains('第一段', $text);
        self::assertStringContains('第二段', $text);
        self::assertStringNotContains('<p>', $text);
    }

    public function testSummarizeTruncates(): void
    {
        $summary = (new HtmlCleaner())->summarize('<p>' . str_repeat('字', 500) . '</p>', 50);

        self::assertTrue(mb_strlen($summary) <= 51, '摘要长度应受限');
        self::assertStringContains('…', $summary);
    }

    public function testOutlineExtractsHeadings(): void
    {
        $outline = (new HtmlCleaner())->outline('<h1>标题一</h1><h3>标题三</h3><h2>标题二</h2>');

        self::assertCount(3, $outline);
        self::assertSame(1, $outline[0]['level']);
        self::assertSame('标题一', $outline[0]['text']);
    }

    public function testMaxLengthTruncates(): void
    {
        $out = (new HtmlCleaner())->maxLength(20)->clean('<p>' . str_repeat('字', 100) . '</p>');
        self::assertTrue(mb_strlen($out) <= 20);
    }

    public function testEmptyInput(): void
    {
        $cleaner = new HtmlCleaner();

        self::assertSame('', $cleaner->clean(''));
        self::assertSame('', $cleaner->toText(''));
        self::assertCount(0, $cleaner->outline(''));
    }

    public function testMultibytePreserved(): void
    {
        $out = (new HtmlCleaner())->clean('<p>中文 🎉 Ελληνικά</p>');

        self::assertStringContains('中文', $out);
        self::assertStringContains('🎉', $out);
    }

    public function testMalformedHtmlDoesNotCrash(): void
    {
        $cleaner = new HtmlCleaner();
        $inputs  = [
            '<div><span>未闭合',
            '<<<>>>',
            str_repeat('<div class="x">y</div>', 2000),
            '<p title="very long ' . str_repeat('x', 10000) . '">t</p>',
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(function () use ($cleaner, $input): void {
                $cleaner->clean($input);
            });
        }
    }

    public function testAllowTagsOverride(): void
    {
        $out = (new HtmlCleaner())->allowTags(['b'])->clean('<b>粗</b><i>斜</i>');

        self::assertStringContains('<b>粗</b>', $out);
        self::assertStringNotContains('<i>', $out);
    }
}
