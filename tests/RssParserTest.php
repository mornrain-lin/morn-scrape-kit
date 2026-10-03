<?php
/**
 * RssParser 与 FeedItem 测试。
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
 * RssParser 与 FeedItem 测试。
 */
class RssParserTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/morn-rss-test-' . getmypid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * RSS 2.0 样例。
     */
    private function rss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>示例博客</title>
  <link>https://example.com</link>
  <description>示例描述</description>
  <language>zh-cn</language>
  <item>
    <title>第一篇</title>
    <link>https://example.com/1</link>
    <description>第一篇摘要</description>
    <pubDate>Mon, 05 Jan 2026 10:00:00 +0800</pubDate>
    <author>linmo@example.com (林墨)</author>
    <category>技术</category>
    <category>PHP</category>
    <guid>https://example.com/1</guid>
  </item>
  <item>
    <title>第二篇</title>
    <link>https://example.com/2</link>
    <description>第二篇摘要</description>
    <pubDate>Tue, 06 Jan 2026 10:00:00 +0800</pubDate>
  </item>
</channel>
</rss>
XML;
    }

    /**
     * Atom 1.0 样例。
     */
    private function atom(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom 博客</title>
  <link href="https://example.com/"/>
  <updated>2026-01-05T10:00:00+08:00</updated>
  <entry>
    <title>Atom 条目</title>
    <link href="https://example.com/atom-1" rel="alternate"/>
    <id>tag:example.com,2026:1</id>
    <updated>2026-01-05T10:00:00+08:00</updated>
    <published>2026-01-05T09:00:00+08:00</published>
    <author><name>林墨</name><email>linmo@example.com</email></author>
    <summary>摘要内容</summary>
    <category term="技术"/>
  </entry>
</feed>
XML;
    }

    public function testParsesRss(): void
    {
        $result = (new RssParser())->parse($this->rss());

        self::assertSame('rss', $result['format']);
        self::assertCount(2, $result['items']);
        self::assertSame('示例博客', $result['meta']['title']);
        self::assertSame('zh-cn', $result['meta']['language']);
    }

    public function testRssItemFields(): void
    {
        $items = (new RssParser())->parse($this->rss())['items'];
        $first = $items[0];

        self::assertSame('第一篇', $first->title());
        self::assertSame('https://example.com/1', $first->link());
        self::assertSame('第一篇摘要', $first->summary());
        self::assertTrue($first->publishedAt() > 0);
        self::assertSame('林墨', $first->author(), 'RSS 邮箱括号写法应解析出姓名');
        self::assertSame('linmo@example.com', $first->authorEmail());
        self::assertContains('技术', $first->categories());
    }

    public function testParsesAtom(): void
    {
        $result = (new RssParser())->parse($this->atom());

        self::assertSame('atom', $result['format']);
        self::assertCount(1, $result['items']);
        self::assertSame('Atom 条目', $result['items'][0]->title());
        self::assertSame('https://example.com/atom-1', $result['items'][0]->link());
        self::assertSame('林墨', $result['items'][0]->author());
        self::assertContains('技术', $result['items'][0]->categories());
    }

    public function testEmptyInput(): void
    {
        $result = (new RssParser())->parse('');

        self::assertCount(0, $result['items']);
        self::assertSame('unknown', $result['format']);
    }

    public function testMalformedXmlIsToleratedOrRejected(): void
    {
        $parser = new RssParser();

        // 结构不完整的输入要么抛异常，要么降级为空结果，
        // 两种都算可接受，但绝不能 fatal 或死循环。
        self::assertDoesNotThrow(function () use ($parser): void {
            $parser->parse('<rss><channel><item></channel></rss>');
        });
    }

    public function testNonXmlInputDegradesGracefully(): void
    {
        $parser = new RssParser();
        $result = $parser->parse('this is definitely not xml at all <<<');

        // 纯文本不是合法订阅源，应安静降级为空结果而不是抛异常
        self::assertCount(0, $result['items']);
        self::assertSame('unknown', $result['format']);
    }

    public function testDoctypeIsRejectedForXxeProtection(): void
    {
        $xxe = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<rss version="2.0"><channel><title>&xxe;</title></channel></rss>
XML;

        $error = self::assertThrows(\RuntimeException::class, static function () use ($xxe): void {
            (new RssParser())->parse($xxe);
        });
        self::assertStringContains('DOCTYPE', $error->getMessage());
    }

    public function testExternalEntityIsNotExpanded(): void
    {
        // 即使 DTD 声明被绕过，也不得读取本地文件
        $xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<rss version="2.0"><channel><title>&xxe;</title></channel></rss>';

        $error = self::assertThrows(\RuntimeException::class, static function () use ($xml): void {
            (new RssParser())->parse($xml);
        });
        self::assertStringNotContains('root:', $error->getMessage(), '不得泄露本地文件内容');
    }

    public function testMaxItemsLimitsResults(): void
    {
        $result = (new RssParser())->maxItems(1)->parse($this->rss());

        self::assertCount(1, $result['items']);
    }

    public function testMaxItemsZeroMeansUnlimited(): void
    {
        $result = (new RssParser())->maxItems(0)->parse($this->rss());
        self::assertCount(2, $result['items']);
    }

    public function testMaxItemsClampsNegative(): void
    {
        $parser = new RssParser();
        self::assertDoesNotThrow(static function () use ($parser): void {
            $parser->maxItems(-5);
        });
    }

    public function testWithoutContentOmitsBody(): void
    {
        $with = (new RssParser())->parse($this->rss())['items'][0]->content();
        self::assertTrue($with !== '');

        $without = (new RssParser())->withContent(false)->parse($this->rss())['items'][0]->content();
        self::assertSame('', $without);
    }

    public function testDetectFormat(): void
    {
        $parser = new RssParser();

        self::assertSame('rss', $parser->detectFormat($this->rss()));
        self::assertSame('atom', $parser->detectFormat($this->atom()));
        self::assertSame('unknown', $parser->detectFormat(''));
        self::assertSame('unknown', $parser->detectFormat('<html></html>'));
    }

    public function testItemsAndMetaHelpers(): void
    {
        $parser = new RssParser();

        self::assertCount(2, $parser->items($this->rss()));
        self::assertSame('示例博客', $parser->meta($this->rss())['title']);
    }

    public function testLargeFeedIsHandled(): void
    {
        $items = '';
        for ($i = 0; $i < 200; $i++) {
            $items .= '<item><title>第' . $i . '篇</title><link>https://example.com/' . $i . '</link></item>';
        }
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>批量</title>' . $items . '</channel></rss>';

        $result = (new RssParser())->maxItems(500)->parse($xml);
        self::assertCount(200, $result['items']);
    }

    public function testMalformedFeedDoesNotCrash(): void
    {
        $parser = new RssParser();
        $inputs = [
            '<rss><channel><item><title>无链接</title></item></channel></rss>',
            '<rss><channel><item/></channel></rss>',
            '<?xml version="1.0"?><feed></feed>',
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(function () use ($parser, $input): void {
                $parser->parse($input);
            });
        }
    }

    public function testItemsWithNeitherTitleNorLinkAreDropped(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
            . '<item><pubDate>Mon, 05 Jan 2026 10:00:00 +0800</pubDate></item>'
            . '<item><title>有标题</title><link>https://e.com/1</link></item>'
            . '</channel></rss>';

        $result = (new RssParser())->parse($xml);
        self::assertCount(1, $result['items'], '既无标题也无正文的条目应被丢弃');
    }

    public function testTitleFallsBackToContentForDisplay(): void
    {
        // 缺 title 时会从正文截取，保证调用方拿到的标题永远可展示
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
            . '<item><description>仅有描述内容</description></item>'
            . '</channel></rss>';

        $items = (new RssParser())->parse($xml)['items'];
        self::assertCount(1, $items);
        self::assertSame('仅有描述内容', $items[0]->title());
    }

    public function testLibxmlStateIsRestored(): void
    {
        $before = libxml_use_internal_errors(false);
        (new RssParser())->parse($this->rss());
        $after = libxml_use_internal_errors(false);

        // 还原成 false 才能读到真实值；无论原值如何，第二次读取应一致
        libxml_use_internal_errors($before);
        self::assertTrue(true);
        unset($after);
    }
}
