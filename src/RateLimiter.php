<?php
/**
 * 礼貌抓取限速器。
 *
 * 目标：不给自己添麻烦，也不给对方添麻烦。
 *
 * 机制：
 * - 按「主机名」分别维护最小请求间隔，默认 1 秒；
 * - 可叠加全局间隔与每日请求上限，避免失控的循环；
 * - 遵守 robots.txt 的 Crawl-delay（由 RobotsGuard 提供时自动采用）；
 * - 状态持久化到 Transient（WordPress）或文件（纯 PHP），
 *   因此跨请求有效，单进程内退出即失效的做法是危险的；
 * - 每次调用返回建议等待秒数，调用方 sleep 后再请求。
 *
 * 合规提醒：限速是**最低要求**，不是充分条件。
 * 抓取前请确认 robots.txt 允许，并遵守目标站点的服务条款。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

use InvalidArgumentException;
use Exception;

/**
 * 限速器。
 */
class RateLimiter
{
    /** 状态键前缀 */
    public const KEY_PREFIX = 'morn_rate_';

    /** @var float 默认同域最小间隔（秒） */
    protected $defaultDelay;

    /** @var float 全局最小间隔（秒），0 表示不限制 */
    protected $globalDelay = 0.0;

    /** @var int 同一主机每日最大请求数，0 表示不限制 */
    protected $dailyLimit = 0;

    /** @var string 状态存储目录（纯 PHP 环境） */
    protected $storageDir;

    /** @var int 状态有效期（秒），保证每日计数自然滚动 */
    protected $stateTtl = 172800;

    /** @var array<string,float> 进程内缓存的间隔设置，键为 host */
    protected $hostDelays = [];

    /**
     * 构造函数。
     *
     * @param float  $defaultDelay 同域最小间隔（秒）。
     * @param string $storageDir   状态存储目录。
     */
    public function __construct(float $defaultDelay = 1.0, string $storageDir = '')
    {
        if ($defaultDelay < 0) {
            throw new InvalidArgumentException('请求间隔不得为负');
        }

        $this->defaultDelay = $defaultDelay;
        $this->storageDir   = $storageDir !== ''
            ? rtrim(str_replace('\\', '/', $storageDir), '/')
            : rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/morn-scrape-kit';
    }

    /**
     * 设置全局最小间隔。
     */
    public function globalDelay(float $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('全局间隔不得为负');
        }
        $this->globalDelay = $seconds;

        return $this;
    }

    /**
     * 设置同一主机的每日请求上限。
     */
    public function dailyLimit(int $limit): self
    {
        $this->dailyLimit = max(0, $limit);

        return $this;
    }

    /**
     * 为指定主机单独设置间隔。
     *
     * 通常用于配合 robots.txt 的 Crawl-delay。
     */
    public function setHostDelay(string $host, float $seconds): self
    {
        $host = strtolower(trim($host));
        if ($host !== '' && $seconds >= 0) {
            $this->hostDelays[$host] = $seconds;
        }

        return $this;
    }

    /**
     * 从 robots.txt 批量导入 Crawl-delay。
     *
     * @param RobotsGuard $guard 已解析的 robots.txt。
     * @param float       $fallback robots.txt 未声明时使用的间隔。
     */
    public function applyRobots(RobotsGuard $guard, string $userAgent = '*', ?float $fallback = null): self
    {
        $delay = $guard->crawlDelay($userAgent);
        if ($delay === null) {
            // 该 UA 分组未声明 Crawl-delay：回退到通配组，再回退到调用方默认值
            $delay = $guard->crawlDelay('*');
        }
        if ($delay !== null && $delay > 0) {
            $this->defaultDelay = $delay;
        } elseif ($fallback !== null && $fallback > 0) {
            $this->defaultDelay = $fallback;
        }

        return $this;
    }

    /**
     * 尝试获取一次请求许可。
     *
     * @param string $url 待请求的 URL 或主机名。
     * @return array{
     *     allowed: bool,
     *     wait: float,
     *     reason: string,
     *     host: string,
     *     today_count: int
     * }
     */
    public function acquire(string $url): array
    {
        $host = $this->hostOf($url);
        if ($host === '') {
            return [
                'allowed'     => false,
                'wait'        => 0.0,
                'reason'      => '无法从目标解析主机名，已阻止请求。',
                'host'        => '',
                'today_count' => 0,
            ];
        }

        $state = $this->readState($host);
        $now   = time();

        // ---- 每日上限 ----
        $today = date('Y-m-d', $now);
        if (($state['date'] ?? '') !== $today) {
            $state = ['date' => $today, 'count' => 0, 'last' => 0.0];
        }
        if ($this->dailyLimit > 0 && $state['count'] >= $this->dailyLimit) {
            return [
                'allowed'     => false,
                'wait'        => $this->secondsUntilTomorrow($now),
                'reason'      => sprintf('主机 %s 今日请求数已达上限 %d 次。', $host, $this->dailyLimit),
                'host'        => $host,
                'today_count' => $state['count'],
            ];
        }

        // ---- 同域间隔 ----
        $delay = $this->delayFor($host);
        $last  = (float) ($state['last'] ?? 0.0);
        $elapsed = $last > 0 ? microtime(true) - $last : $delay;

        if ($last > 0 && $elapsed < $delay) {
            $wait = round($delay - $elapsed, 3);
            return [
                'allowed'     => false,
                'wait'        => $wait,
                'reason'      => sprintf('主机 %s 需等待 %.3f 秒（最小间隔 %.3f 秒）。', $host, $wait, $delay),
                'host'        => $host,
                'today_count' => $state['count'],
            ];
        }

        // ---- 全局间隔 ----
        if ($this->globalDelay > 0) {
            $globalState = $this->readState('__global__');
            $globalLast  = (float) ($globalState['last'] ?? 0.0);
            $globalElapsed = $globalLast > 0 ? microtime(true) - $globalLast : $this->globalDelay;
            if ($globalLast > 0 && $globalElapsed < $this->globalDelay) {
                $wait = round($this->globalDelay - $globalElapsed, 3);
                return [
                    'allowed'     => false,
                    'wait'        => $wait,
                    'reason'      => sprintf('全局间隔限制：需等待 %.3f 秒。', $wait),
                    'host'        => $host,
                    'today_count' => $state['count'],
                ];
            }
            $this->writeState('__global__', ['last' => microtime(true)]);
        }

        // ---- 放行并记账 ----
        $state['last']  = microtime(true);
        $state['count'] = (int) $state['count'] + 1;
        $this->writeState($host, $state);

        return [
            'allowed'     => true,
            'wait'        => 0.0,
            'reason'      => sprintf('允许请求 %s（今日第 %d 次）。', $host, $state['count']),
            'host'        => $host,
            'today_count' => $state['count'],
        ];
    }

    /**
     * 阻塞式等待：直到获得许可或超时。
     *
     * @param string $url     目标 URL。
     * @param float  $maxWait 最长等待秒数，超时返回 false。
     * @return array{granted:bool,waited:float,reason:string}
     */
    public function waitFor(string $url, float $maxWait = 30.0): array
    {
        $start   = microtime(true);
        $waited  = 0.0;
        $lastMsg = '';

        while (true) {
            $result = $this->acquire($url);
            if ($result['allowed']) {
                return [
                    'granted' => true,
                    'waited'  => round($waited, 3),
                    'reason'  => $result['reason'],
                ];
            }

            $lastMsg = $result['reason'];
            $sleep   = $result['wait'];
            if ($sleep <= 0) {
                $sleep = 1.0;
            }
            if ($waited + $sleep > $maxWait) {
                return [
                    'granted' => false,
                    'waited'  => round($waited, 3),
                    'reason'  => sprintf('等待超时（已等 %.1f 秒）：%s', $waited, $lastMsg),
                ];
            }

            // 加一点随机抖动，避免多进程同时醒来形成新的尖峰
            usleep((int) (($sleep + $this->jitter()) * 1000000));
            $waited += $sleep;
        }
    }

    /**
     * 取得某主机的今日请求数。
     */
    public function todayCount(string $url): int
    {
        $host  = $this->hostOf($url);
        $state = $this->readState($host);

        if (($state['date'] ?? '') !== date('Y-m-d')) {
            return 0;
        }

        return (int) ($state['count'] ?? 0);
    }

    /**
     * 清空某主机的限速状态（调试用）。
     */
    public function reset(string $url = ''): self
    {
        if ($url === '') {
            $this->purgeAll();

            return $this;
        }

        $this->deleteState($this->hostOf($url));

        return $this;
    }

    /**
     * 取得当前生效的间隔设置。
     *
     * @return array<string,mixed>
     */
    public function config(): array
    {
        return [
            'default_delay' => $this->defaultDelay,
            'global_delay'  => $this->globalDelay,
            'daily_limit'   => $this->dailyLimit,
            'host_delays'   => $this->hostDelays,
            'storage_dir'   => $this->storageDir,
        ];
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 取得指定主机的最小间隔。
     */
    protected function delayFor(string $host): float
    {
        return $this->hostDelays[$host] ?? $this->defaultDelay;
    }

    /**
     * 从 URL 或字符串中提取主机名。
     */
    protected function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // 本身就是主机名
        if (strpos($url, '/') === false && strpos($url, '.') !== false) {
            $host = preg_replace('/^[^@\s]*@/', '', $url) ?? $url;   // 去掉 user:pass@

            return strtolower(trim($host, '.'));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return strtolower($host);
    }

    /**
     * 随机抖动（0 ~ 0.3 秒）。
     *
     * 用 random_int() 而非 mt_rand()：多进程抓取时若共用同一种子的
     * mt_rand 序列，各进程的抖动会高度同步，反而形成新的请求尖峰。
     */
    protected function jitter(): float
    {
        if (function_exists('random_int')) {
            try {
                return random_int(0, 300) / 1000;
            } catch (Exception $e) {
                // 落到下方实现
            }
        }

        return mt_rand(0, 300) / 1000;
    }

    /**
     * 距离明天零点的秒数。
     */
    protected function secondsUntilTomorrow(int $now): float
    {
        $midnight = strtotime('tomorrow midnight');
        if ($midnight === false) {
            return 3600.0;
        }

        return max(1.0, (float) ($midnight - $now));
    }

    /**
     * 读取状态。
     *
     * @return array<string,mixed>
     */
    protected function readState(string $host): array
    {
        $key = self::KEY_PREFIX . md5($host);

        if (function_exists('get_transient')) {
            $value = get_transient($key);

            return is_array($value) ? $value : [];
        }

        $file = $this->pathFor($key);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 写入状态。
     *
     * @param array<string,mixed> $state 状态数据。
     */
    protected function writeState(string $host, array $state): void
    {
        $key = self::KEY_PREFIX . md5($host);

        if (function_exists('set_transient')) {
            set_transient($key, $state, $this->stateTtl);

            return;
        }

        $dir = $this->storageDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $this->pathFor($key);
        // 临时文件名带上 pid 与随机串，避免同进程内连续写互相覆盖
        $tmp  = $file . '.' . $this->tempSuffix() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            // 失败时必须清理临时文件，否则会在状态目录里堆积垃圾
            @unlink($tmp);
        }
    }

    /**
     * 生成临时文件后缀。
     */
    protected function tempSuffix(): string
    {
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        if (function_exists('random_bytes')) {
            try {
                return $pid . '.' . bin2hex(random_bytes(4));
            } catch (Exception $e) {
                // 落到下方实现
            }
        }

        return $pid . '.' . uniqid('', true);
    }

    /**
     * 删除状态。
     */
    protected function deleteState(string $host): void
    {
        $key = self::KEY_PREFIX . md5($host);

        if (function_exists('delete_transient')) {
            delete_transient($key);

            return;
        }

        @unlink($this->pathFor($key));
    }

    /**
     * 清空全部限速状态。
     *
     * WordPress 环境下 Transient 无法按前缀枚举，因此**不做任何删除**，
     * 只清文件层。此处必须让调用方知晓这一限制，故记录到 error_log。
     */
    protected function purgeAll(): void
    {
        if (function_exists('get_transient') || function_exists('wp_cache_flush')) {
            // Transient / 对象缓存不支持按前缀批量删除，逐个 host 调用
            // reset($url) 才能清除。此处明确不静默假装成功。
            error_log('[morn-scrape-kit] purgeAll() 在 WordPress 环境下无法清空 Transient，请改用 reset($url) 逐个清除。');

            return;
        }

        if (!is_dir($this->storageDir)) {
            return;
        }
        foreach ((array) glob($this->storageDir . '/' . self::KEY_PREFIX . '*.json') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * 状态文件路径。
     */
    protected function pathFor(string $key): string
    {
        return $this->storageDir . '/' . $key . '.json';
    }
}
