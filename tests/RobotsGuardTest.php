<?php
/**
 * * RobotsGuard 测试。
 *
 * @package MornRain\ScrapeKit\Tests
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit\Tests;

use InvalidArgumentException;
use MornRain\ScrapeKit\RateLimiter;
use MornRain\ScrapeKit\RobotsGuard;

/**
 * RobotsGuard 测试。
 */
class RobotsGuardTest extends TestCase
{
    /**
     * 构造一份典型 robots.txt。
     */
    private function robots(): string
    {
        return <<<'TXT'
# 注释行
User-agent: *
Disallow: /private/
Allow: /private/public/
Crawl-delay: 2

User-agent: BadBot
Disallow: /

User-agent: GoodBot
User-agent: OtherBot
Disallow: /tmp/
Allow: /tmp/ok/

Sitemap: https://example.com/sitemap.xml
TXT;
    }

    public function testEmptyRobotsAllowsEverything(): void
    {
        $guard = new RobotsGuard();
        self::assertTrue($guard->isAllowed('/anything'));
        self::assertNull($guard->crawlDelay('*'));
        self::assertCount(0, $guard->sitemaps());
    }

    public function testEmptyStringRobotsAllowsEverything(): void
    {
        $guard = new RobotsGuard('   ');
        self::assertTrue($guard->isAllowed('/x'));
    }

    public function testDisallowIsEnforced(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /private/\n");

        self::assertFalse($guard->isAllowed('/private/secret'));
        self::assertTrue($guard->isAllowed('/public/page'));
    }

    public function testEmptyDisallowMeansAllowAll(): void
    {
        // "Disallow:" 空值是「允许全部」的常见写法，不能当成禁止
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow:\n");

        self::assertTrue($guard->isAllowed('/anything'));
    }

    public function testLongestMatchWins(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /a/\nAllow: /a/b/\n");

        // Allow 更长，应放行
        self::assertTrue($guard->isAllowed('/a/b/c'));
        self::assertFalse($guard->isAllowed('/a/other'));
    }

    public function testEqualLengthPrefersAllow(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /page\nAllow: /page\n");

        self::assertTrue($guard->isAllowed('/page'), '长度相同时 Allow 优先');
    }

    public function testUserAgentGroupSelection(): void
    {
        $guard = RobotsGuard::fromString($this->robots());

        // BadBot 被全站禁止
        self::assertFalse($guard->isAllowed('/public', 'BadBot'));
        // GoodBot 命中自己的分组
        self::assertTrue($guard->isAllowed('/public', 'GoodBot'));
        // 未列出的 UA 回退到 * 分组
        self::assertFalse($guard->isAllowed('/private/x', 'UnknownBot'));
    }

    public function testLongestUserAgentMatchWins(): void
    {
        $guard = RobotsGuard::fromString(
            "User-agent: *\nDisallow: /a\n\nUser-agent: SpecialBot\nDisallow: /b\n"
        );

        // SpecialBot 命中更精确的分组，因此只受该组规则约束：
        // /b 被禁止，而 /a 不在 SpecialBot 组内 → 放行
        self::assertFalse($guard->isAllowed('/b', 'SpecialBot'));
        self::assertTrue($guard->isAllowed('/a', 'SpecialBot'));

        // 其他 UA 仍走 * 分组
        self::assertFalse($guard->isAllowed('/a', 'OtherBot'));
        self::assertTrue($guard->isAllowed('/b', 'OtherBot'));
    }

    public function testWildcardMatching(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /*.pdf$\n");

        self::assertFalse($guard->isAllowed('/docs/a.pdf'), '行尾锚定应匹配');
        self::assertTrue($guard->isAllowed('/docs/a.pdf.html'), '锚定后不应匹配');
    }

    public function testWildcardInMiddle(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /*/private\n");

        self::assertFalse($guard->isAllowed('/a/b/private'));
        self::assertTrue($guard->isAllowed('/a/b/public'));
    }

    public function testGlobMatchingIsLinearNotReDoS(): void
    {
        $guard = RobotsGuard::fromString(
            "User-agent: *\nDisallow: /*a*a*a*a*a*a*a*a*a*a*b\n"
        );

        // 病态输入必须在极短时间内返回，否则说明存在灾难性回溯
        $path = '/' . str_repeat('a', 200);
        $start = microtime(true);
        $guard->isAllowed($path);
        $elapsed = (microtime(true) - $start) * 1000;

        self::assertTrue($elapsed < 200, "通配匹配耗时 {$elapsed} ms，疑似 ReDoS");
    }

    public function testPathWithQueryIsMatched(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /search?q=\n");

        self::assertFalse($guard->isAllowed('/search?q=test'));
        self::assertTrue($guard->isAllowed('/search'));
    }

    public function testAbsoluteUrlIsReducedToPath(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /private\n");

        self::assertFalse($guard->isAllowed('https://example.com/private/x'));
        self::assertFalse($guard->isAllowed('//example.com/private/x'));
    }

    public function testRelativeUrlWithBase(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /private\n");

        self::assertFalse($guard->isAllowed('private/x', '*', 'https://example.com'));
    }

    public function testProtocolRelativeUrl(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /private\n");
        self::assertFalse($guard->isAllowed('//example.com/private/x'));
    }

    public function testCrawlDelay(): void
    {
        $guard = RobotsGuard::fromString($this->robots());

        self::assertEqualsWithDelta(2.0, (float) $guard->crawlDelay('*'), 0.001);
        self::assertEqualsWithDelta(2.0, (float) $guard->crawlDelay('UnknownBot'), 0.001, '未列出 UA 回退到 *');
        self::assertNull($guard->crawlDelay('BadBot'), 'BadBot 组未声明 Crawl-delay');
    }

    public function testSitemapsAreCollected(): void
    {
        $guard = RobotsGuard::fromString($this->robots());
        $maps  = $guard->sitemaps();

        self::assertContains('https://example.com/sitemap.xml', $maps);
    }

    public function testUserAgentsListing(): void
    {
        $guard  = RobotsGuard::fromString($this->robots());
        $agents = $guard->userAgents();

        self::assertContains('*', $agents);
        self::assertContains('goodbot', $agents);
    }

    public function testDisallowRulesListing(): void
    {
        $guard  = RobotsGuard::fromString($this->robots());
        $rules  = $guard->disallowRules();

        self::assertContains('/private/', $rules);
    }

    public function testCheckReturnsDiagnostics(): void
    {
        $guard = RobotsGuard::fromString($this->robots());
        $check = $guard->check('/private/x', 'UnknownBot');

        self::assertArrayHasKey('allowed', $check);
        self::assertArrayHasKey('rule', $check);
        self::assertArrayHasKey('matched_group', $check);
        self::assertArrayHasKey('path', $check);
        self::assertFalse($check['allowed']);
    }

    public function testUnmatchedUserAgentIsAllowed(): void
    {
        $guard = RobotsGuard::fromString("User-agent: OnlyBot\nDisallow: /\n");
        $check = $guard->check('/x', 'DifferentBot');

        self::assertTrue($check['allowed']);
        self::assertSame(-1, $check['matched_group']);
    }

    public function testCommentsAreStripped(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /a # 注释\n");

        self::assertFalse($guard->isAllowed('/a'));
    }

    public function testWindowsLineEndings(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\r\nDisallow: /private\r\n");

        self::assertFalse($guard->isAllowed('/private/x'));
    }

    public function testMalformedInputDoesNotCrash(): void
    {
        $inputs = [
            "no-colon-line\nUser-agent: *\n",
            "User-agent\nDisallow: /\n",
            str_repeat("User-agent: *\nDisallow: /\n", 500),
            "User-agent: *\nDisallow: " . str_repeat('/', 1000) . "\n",
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(static function () use ($input): void {
                $g = RobotsGuard::fromString($input);
                $g->isAllowed('/x');
            }, '畸形 robots.txt 不应导致崩溃');
        }
    }

    public function testSummaryIsReadable(): void
    {
        $guard   = RobotsGuard::fromString($this->robots());
        $summary = $guard->summary();

        self::assertStringContains('UA', $summary);
        self::assertStringContains('Crawl-delay', $summary);
    }

    public function testEmptyRobotsSummary(): void
    {
        self::assertStringContains('允许抓取全部', (new RobotsGuard())->summary());
    }

    public function testRawAccessor(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\n");
        self::assertStringContains('User-agent', $guard->raw());
    }

    public function testParseIsIdempotentWhenCalledTwice(): void
    {
        $guard = RobotsGuard::fromString("User-agent: *\nDisallow: /a\n");
        $guard->parse("User-agent: *\nDisallow: /b\n");

        self::assertTrue($guard->isAllowed('/a'), '重新解析应覆盖旧规则');
        self::assertFalse($guard->isAllowed('/b'));
    }
}
