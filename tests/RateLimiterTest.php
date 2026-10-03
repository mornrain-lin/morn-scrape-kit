<?php
/**
 * * RateLimiter 测试。
 *
 * @package MornRain\ScrapeKit\Tests
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit\Tests;

use InvalidArgumentException;
use MornRain\ScrapeKit\RateLimiter;
use MornRain\ScrapeKit\RobotsGuard;

/**
 * RateLimiter 测试。
 */
class RateLimiterTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/morn-rl-test-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testRejectsNegativeDelay(): void
    {
        $dir = $this->dir;
        self::assertThrows(InvalidArgumentException::class, static function () use ($dir): void {
            new RateLimiter(-1.0, $dir);
        });
    }

    public function testGlobalDelayRejectsNegative(): void
    {
        $limiter = new RateLimiter(1.0, $this->dir);
        self::assertThrows(InvalidArgumentException::class, static function () use ($limiter): void {
            $limiter->globalDelay(-1.0);
        });
    }

    public function testFirstRequestIsAllowed(): void
    {
        $limiter = new RateLimiter(1.0, $this->dir);
        $result  = $limiter->acquire('https://example.com/a');

        self::assertTrue($result['allowed']);
        self::assertSame('example.com', $result['host']);
        self::assertSame(1, $result['today_count']);
    }

    public function testSecondImmediateRequestIsBlocked(): void
    {
        $limiter = new RateLimiter(10.0, $this->dir);
        self::assertTrue($limiter->acquire('https://example.com/a')['allowed']);

        $second = $limiter->acquire('https://example.com/b');
        self::assertFalse($second['allowed'], '未到间隔时间应被限速');
        self::assertTrue($second['wait'] > 0, '应给出建议等待秒数');
    }

    public function testDifferentHostsAreIndependent(): void
    {
        $limiter = new RateLimiter(10.0, $this->dir);

        self::assertTrue($limiter->acquire('https://a.com/1')['allowed']);
        self::assertTrue($limiter->acquire('https://b.com/1')['allowed'], '不同主机不互相限速');
    }

    public function testHostDelayOverride(): void
    {
        $limiter = new RateLimiter(10.0, $this->dir);
        $limiter->setHostDelay('example.com', 0.0);

        self::assertTrue($limiter->acquire('https://example.com/1')['allowed']);
        self::assertTrue($limiter->acquire('https://example.com/2')['allowed'], '间隔为 0 时不限制');
    }

    public function testDailyLimitBlocksAfterThreshold(): void
    {
        $limiter = (new RateLimiter(0.0, $this->dir))->dailyLimit(2);

        self::assertTrue($limiter->acquire('https://example.com/1')['allowed']);
        self::assertTrue($limiter->acquire('https://example.com/2')['allowed']);

        $third = $limiter->acquire('https://example.com/3');
        self::assertFalse($third['allowed'], '超出每日上限应被拦截');
        self::assertTrue($third['wait'] > 0);
    }

    public function testTodayCount(): void
    {
        $limiter = (new RateLimiter(0.0, $this->dir))->dailyLimit(10);
        $limiter->acquire('https://example.com/a');
        $limiter->acquire('https://example.com/b');

        self::assertSame(2, $limiter->todayCount('https://example.com/c'));
    }

    public function testInvalidUrlIsBlocked(): void
    {
        $limiter = new RateLimiter(1.0, $this->dir);

        self::assertFalse($limiter->acquire('')['allowed'], '空 URL 必须阻止');
        self::assertFalse($limiter->acquire('not a url')['allowed']);
    }

    public function testHostExtractionFromBareHostname(): void
    {
        $limiter = new RateLimiter(0.0, $this->dir);
        $result  = $limiter->acquire('example.com');

        self::assertSame('example.com', $result['host']);
    }

    public function testHostExtractionStripsCredentials(): void
    {
        $limiter = new RateLimiter(0.0, $this->dir);
        $result  = $limiter->acquire('https://user:pass@example.com/x');

        self::assertSame('example.com', $result['host'], '不应把凭据算进主机名');
    }

    public function testApplyRobotsUsesCrawlDelay(): void
    {
        $guard   = RobotsGuard::fromString("User-agent: *\nCrawl-delay: 3\n");
        $limiter = (new RateLimiter(1.0, $this->dir))->applyRobots($guard, '*');

        self::assertEqualsWithDelta(3.0, $limiter->config()['default_delay'], 0.001);
    }

    public function testApplyRobotsFallsBackWhenNotDeclared(): void
    {
        $guard   = RobotsGuard::fromString("User-agent: SomeBot\nDisallow: /\n");
        $limiter = (new RateLimiter(1.0, $this->dir))->applyRobots($guard, 'SomeBot', 5.0);

        self::assertEqualsWithDelta(5.0, $limiter->config()['default_delay'], 0.001, '未声明时应使用 fallback');
    }

    public function testWaitForTimesOutGracefully(): void
    {
        $limiter = (new RateLimiter(30.0, $this->dir));
        $limiter->acquire('https://example.com/a');

        $start = microtime(true);
        $result = $limiter->waitFor('https://example.com/b', 0.2);
        $elapsed = microtime(true) - $start;

        self::assertFalse($result['granted']);
        self::assertStringContains('超时', $result['reason']);
        self::assertTrue($elapsed < 5, "等待超时不应超出预期，实际 {$elapsed}s");
    }

    public function testWaitForReturnsImmediatelyWhenAllowed(): void
    {
        $limiter = new RateLimiter(0.0, $this->dir);
        $result  = $limiter->waitFor('https://example.com/a', 1.0);

        self::assertTrue($result['granted']);
    }

    public function testGlobalDelayBlocksAcrossHosts(): void
    {
        $limiter = (new RateLimiter(0.0, $this->dir))->globalDelay(30.0);

        self::assertTrue($limiter->acquire('https://a.com/1')['allowed']);
        self::assertFalse($limiter->acquire('https://b.com/1')['allowed'], '全局间隔应跨主机生效');
    }

    public function testResetClearsHostState(): void
    {
        $limiter = new RateLimiter(30.0, $this->dir);
        $limiter->acquire('https://example.com/a');
        self::assertFalse($limiter->acquire('https://example.com/b')['allowed']);

        $limiter->reset('https://example.com/a');
        self::assertTrue($limiter->acquire('https://example.com/c')['allowed'], 'reset 后应恢复放行');
    }

    public function testConfigShape(): void
    {
        $config = (new RateLimiter(1.5, $this->dir))->config();

        foreach (['default_delay', 'global_delay', 'daily_limit', 'host_delays', 'storage_dir'] as $key) {
            self::assertArrayHasKey($key, $config);
        }
        self::assertEqualsWithDelta(1.5, $config['default_delay'], 0.001);
    }

    public function testNoTempFilesLeftBehind(): void
    {
        $limiter = new RateLimiter(0.0, $this->dir);
        for ($i = 0; $i < 5; $i++) {
            $limiter->acquire('https://example.com/' . $i);
        }

        $tmp = glob($this->dir . '/*.tmp') ?: [];
        self::assertCount(0, $tmp, '不应残留临时文件');
    }
}
