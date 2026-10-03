<?php
/**
 * robots.txt 解析与抓取许可判断。
 *
 * 实现遵循 RFC 9309（2022 年正式标准）与各大搜索引擎的通用实践：
 * - User-agent 分组匹配，最精确（最长）匹配优先；
 * - 同组内 Allow / Disallow 合并，按「最长匹配优先」裁决；
 * - Allow 与 Disallow 长度相同时，Allow 胜出；
 * - 支持 * 通配符与 $ 行尾锚；
 * - 支持 Sitemap 指令；
 * - 支持 Crawl-delay（虽非标准，但被广泛使用）。
 *
 * 合规说明：本类用于**判断是否被允许抓取**，属于合规工具。
 * 请始终遵守目标站点的 robots.txt，并结合 RateLimiter 控制请求频率。
 * robots.txt 不是法律授权文件，服务条款与版权法同样适用。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

/**
 * robots.txt 守卫。
 */
class RobotsGuard
{
    /** @var array<int,array{agents:array<int,string>,rules:array<int,array<string,string>>,crawl_delay:?float,sitemap:array<int,string>}>> 解析后的规则组 */
    protected $groups = [];

    /** @var array<int,string> 全局 sitemap */
    protected $sitemaps = [];

    /** @var string 当前解析的原始内容，便于调试 */
    protected $raw = '';

    /**
     * 构造函数。
     *
     * @param string $robotsTxt robots.txt 内容。不传时构造一个「无限制」实例。
     */
    public function __construct(string $robotsTxt = '')
    {
        if (trim($robotsTxt) !== '') {
            $this->parse($robotsTxt);
        }
    }

    /**
     * 从 robots.txt 内容构建实例。
     *
     * 静态构造便于链式使用：`RobotsGuard::fromString($txt)->isAllowed($url)`。
     */
    public static function fromString(string $robotsTxt): self
    {
        return new self($robotsTxt);
    }

    /**
     * 解析 robots.txt。
     *
     * @param string $content robots.txt 内容。
     * @return self
     */
    public function parse(string $content): self
    {
        $this->groups   = [];
        $this->sitemaps = [];
        $this->raw      = $content;

        $lines      = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $current    = -1;
        $lastWasAgent = false;

        foreach ($lines as $line) {
            // 去掉行内注释
            $hashPos = strpos($line, '#');
            if ($hashPos !== false) {
                $line = substr($line, 0, $hashPos);
            }
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            $value = trim($value);

            if ($field === 'user-agent') {
                if (!$lastWasAgent) {
                    // 连续的 user-agent 行属于同一组（多 UA 共用规则）
                    $this->groups[] = [
                        'agents'      => [],
                        'rules'       => [],
                        'crawl_delay' => null,
                        'sitemap'     => [],
                    ];
                    $current = count($this->groups) - 1;
                }
                if ($current < 0) {
                    $this->groups[] = [
                        'agents'      => [],
                        'rules'       => [],
                        'crawl_delay' => null,
                        'sitemap'     => [],
                    ];
                    $current = count($this->groups) - 1;
                }
                $this->groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }

            $lastWasAgent = false;

            // sitemap 可以出现在任何位置
            if ($field === 'sitemap') {
                if ($value !== '') {
                    $this->sitemaps[] = $value;
                }
                continue;
            }

            // 没有 user-agent 的规则视为对所有爬虫生效
            if ($current < 0) {
                $this->groups[] = [
                    'agents'      => ['*'],
                    'rules'       => [],
                    'crawl_delay' => null,
                    'sitemap'     => [],
                ];
                $current = 0;
            }

            switch ($field) {
                case 'allow':
                case 'disallow':
                    if ($value !== '') {
                        $this->groups[$current]['rules'][] = [
                            'type'  => $field,
                            'value' => $value,
                        ];
                    } elseif ($field === 'disallow' && $value === '') {
                        // "Disallow:"（空值）表示允许全部，这是常见写法
                        $this->groups[$current]['rules'][] = [
                            'type'  => 'allow',
                            'value' => '',
                        ];
                    }
                    break;

                case 'crawl-delay':
                    if (is_numeric($value)) {
                        $this->groups[$current]['crawl_delay'] = (float) $value;
                    }
                    break;

                default:
                    // 未纳入标准的指令（Host / Clean-param / Noindex 等）忽略
                    break;
            }
        }

        return $this;
    }

    /**
     * 判断某 URL 是否允许抓取。
     *
     * @param string      $url        完整 URL 或路径。
     * @param string      $userAgent  爬虫标识。
     * @param string|null $baseUrl    站点根地址，用于把相对路径补成绝对路径。
     * @return array{allowed:bool,rule:string,matched_group:int,path:string}
     */
    public function check(string $url, string $userAgent = '*', ?string $baseUrl = null): array
    {
        $path = $this->extractPath($url, $baseUrl);
        $ua   = strtolower(trim($userAgent)) === '' ? '*' : strtolower(trim($userAgent));

        $groupIndex = $this->selectGroup($ua);
        if ($groupIndex < 0) {
            return [
                'allowed'       => true,
                'rule'          => '未匹配任何 User-agent 分组，按默认允许处理。',
                'matched_group' => -1,
                'path'          => $path,
            ];
        }

        $group = $this->groups[$groupIndex];
        $best  = $this->matchRules($group['rules'], $path);

        if ($best === null) {
            return [
                'allowed'       => true,
                'rule'          => sprintf('匹配 UA 分组「%s」，但无规则命中该路径。', implode(', ', $group['agents'])),
                'matched_group' => $groupIndex,
                'path'          => $path,
            ];
        }

        $allowed = $best['type'] === 'allow';

        return [
            'allowed'       => $allowed,
            'rule'          => sprintf(
                '%s: %s（由 %s: %s 决定）',
                $allowed ? '允许抓取' : '禁止抓取',
                $path,
                ucfirst($best['type']),
                $best['value'] === '' ? '(空，等于全部允许)' : $best['value']
            ),
            'matched_group' => $groupIndex,
            'path'          => $path,
        ];
    }

    /**
     * 简化的允许判断。
     */
    public function isAllowed(string $url, string $userAgent = '*', ?string $baseUrl = null): bool
    {
        return $this->check($url, $userAgent, $baseUrl)['allowed'];
    }

    /**
     * 取得该 UA 的建议抓取间隔（秒）。
     *
     * 只返回 Crawl-delay；未声明时由调用方使用 RateLimiter 的默认值。
     */
    public function crawlDelay(string $userAgent = '*'): ?float
    {
        $index = $this->selectGroup(strtolower(trim($userAgent)) === '' ? '*' : strtolower(trim($userAgent)));
        if ($index < 0) {
            return null;
        }

        return $this->groups[$index]['crawl_delay'];
    }

    /**
     * 取得 sitemap 列表。
     *
     * @return array<int,string>
     */
    public function sitemaps(): array
    {
        $all = $this->sitemaps;
        foreach ($this->groups as $group) {
            foreach ($group['sitemap'] as $sitemap) {
                $all[] = $sitemap;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * 列出所有 User-agent 分组名。
     *
     * @return array<int,string>
     */
    public function userAgents(): array
    {
        $out = [];
        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                $out[] = $agent;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 列出所有 Disallow 规则（用于快速审查）。
     *
     * @return array<int,string>
     */
    public function disallowRules(): array
    {
        $out = [];
        foreach ($this->groups as $group) {
            foreach ($group['rules'] as $rule) {
                if ($rule['type'] === 'disallow') {
                    $out[] = $rule['value'];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 生成人类可读的规则摘要。
     */
    public function summary(): string
    {
        if ($this->groups === []) {
            return 'robots.txt 为空或未解析到任何规则：允许抓取全部内容。';
        }

        $lines = [];
        foreach ($this->groups as $index => $group) {
            $lines[] = sprintf(
                '组 %d — UA: %s，规则 %d 条%s',
                $index,
                implode(', ', $group['agents']) === '' ? '(无)' : implode(', ', $group['agents']),
                count($group['rules']),
                $group['crawl_delay'] !== null ? sprintf('，Crawl-delay: %s', $group['crawl_delay']) : ''
            );
        }
        if ($this->sitemaps !== []) {
            $lines[] = 'Sitemap: ' . implode(', ', $this->sitemaps);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 取得原始内容。
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 选择适用的规则组。
     *
     * RFC 9309 规定：取 UA 名称最长的精确匹配组；
     * 无精确匹配时回退到 * 组；都没有则返回 -1。
     */
    protected function selectGroup(string $userAgent): int
    {
        $bestIndex  = -1;
        $bestLength = -1;
        $fallback   = -1;

        foreach ($this->groups as $index => $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    if ($fallback < 0) {
                        $fallback = $index;
                    }
                    continue;
                }

                // 精确匹配：UA 中包含该 token
                // 实践中各爬虫多以 "Mozilla/5.0 (compatible; Googlebot/2.1)" 形式声明
                if (stripos($userAgent, $agent) !== false) {
                    $length = strlen($agent);
                    if ($length > $bestLength) {
                        $bestLength = $length;
                        $bestIndex  = $index;
                    }
                }
            }
        }

        if ($bestIndex >= 0) {
            return $bestIndex;
        }

        return $fallback;
    }

    /**
     * 匹配规则，按最长匹配优先裁决。
     *
     * @param array<int,array{type:string,value:string}> $rules 规则列表。
     * @param string                                    $path  请求路径。
     * @return array{type:string,value:string,length:int}|null
     */
    protected function matchRules(array $rules, string $path): ?array
    {
        $best = null;

        foreach ($rules as $rule) {
            if (!$this->ruleMatches($rule['value'], $path)) {
                continue;
            }

            // 匹配长度取去掉通配符后的字面量长度（RFC 9309 的判定方式）
            $length = $this->ruleLength($rule['value']);

            if ($best === null) {
                $best = $rule + ['length' => $length];
                continue;
            }

            if ($length > $best['length']) {
                $best = $rule + ['length' => $length];
            } elseif ($length === $best['length'] && $rule['type'] === 'allow') {
                // 长度相同时 Allow 优先
                $best = $rule + ['length' => $length];
            }
        }

        return $best;
    }

    /**
     * 判断规则是否命中路径（支持 * 与 $）。
     *
     * 安全说明：这里**不使用正则**。robots.txt 来自远端，属于不可信输入；
     * 若把 `*` 直接翻译成正则的 `.*`，形如 `/*a*a*a*a*a*a*a*a*a*b` 的规则
     * 会在长路径上触发灾难性回溯（ReDoS），把 CPU 打满。
     * 因此改为手写的线性时间通配匹配，语义与 RFC 9309 一致。
     */
    protected function ruleMatches(string $rule, string $path): bool
    {
        if ($rule === '') {
            // "Disallow:" 空值 = 允许全部
            return true;
        }

        $anchored = substr($rule, -1) === '$';
        if ($anchored) {
            $rule = substr($rule, 0, -1);
        }

        if ($anchored) {
            // 行尾锚定：整串匹配。
            return $this->globMatch($rule, $path);
        }

        // 非锚定：前缀匹配。追加一个 `*` 后复用整串匹配即可，
        // 避免再写一套回溯逻辑。
        return $this->globMatch($rule . '*', $path);
    }

    /**
     * 线性时间的整串通配匹配（仅支持 `*`）。
     *
     * 双指针 + 回退到最近一个 `*`，对任意输入都是 O(n·m) 上界，
     * 不存在指数级回溯。
     *
     * @param string $pattern 通配模式（`*` 表示任意长度子串）。
     * @param string $subject 待匹配字符串。
     */
    protected function globMatch(string $pattern, string $subject): bool
    {
        $pLen = strlen($pattern);
        $sLen = strlen($subject);

        $p = 0;
        $s = 0;
        // 最近一个 `*` 的位置，以及它之后应从哪里继续尝试
        $starP = -1;
        $starS = 0;

        while ($s < $sLen) {
            if ($p < $pLen && $pattern[$p] === $subject[$s]) {
                $p++;
                $s++;
                continue;
            }

            if ($p < $pLen && $pattern[$p] === '*') {
                // 记录回退点，先让 `*` 匹配空串
                $starP = $p;
                $starS = $s;
                $p++;
                continue;
            }

            if ($starP >= 0) {
                // 回退：让上一个 `*` 多吞一个字符
                $starS++;
                $p = $starP + 1;
                $s = $starS;
                continue;
            }

            return false;
        }

        // 主题串已耗尽，模式串剩余部分必须全是 `*` 才算匹配
        while ($p < $pLen && $pattern[$p] === '*') {
            $p++;
        }

        return $p === $pLen;
    }

    /**
     * 计算规则的有效匹配长度（去掉通配符与锚点）。
     */
    protected function ruleLength(string $rule): int
    {
        $rule = rtrim($rule, '$');

        return strlen(str_replace('*', '', $rule));
    }

    /**
     * 从 URL 中提取需要匹配的路径部分。
     *
     * robots.txt 的路径匹配基于「路径 + 查询串」，
     * 因此 query 也参与匹配。
     */
    protected function extractPath(string $url, ?string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        // 已是路径
        if ($url[0] === '/') {
            return $this->pathWithQuery($url);
        }

        // 带 scheme 的绝对 URL：无论是否给了基准都直接取 path
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url) === 1) {
            $parts = parse_url($url);
            $path  = $parts['path'] ?? '/';
            if ($path === '') {
                $path = '/';
            }
            if (isset($parts['query']) && $parts['query'] !== '') {
                $path .= '?' . $parts['query'];
            }

            return $path;
        }

        // 协议相对地址 //example.com/a
        if (strpos($url, '//') === 0) {
            $parts = parse_url('https:' . $url);
            $path  = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
            if (isset($parts['query']) && $parts['query'] !== '') {
                $path .= '?' . $parts['query'];
            }

            return $path;
        }

        // 相对路径：拼上基准再解析
        if ($baseUrl === null || $baseUrl === '') {
            return '/' . ltrim($url, '/');
        }

        $parts = parse_url(rtrim($baseUrl, '/') . '/' . ltrim($url, '/'));
        $path  = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * 从「路径?查询串」形式的串中取出路径部分。
     */
    protected function pathWithQuery(string $url): string
    {
        $parts = parse_url($url);
        $path  = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }
}
