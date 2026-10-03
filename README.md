# MornRain Scrape Kit

合规的 HTML 解析与元数据提取库。SEO 元信息、RSS/Atom 流式解析、链接内外链分析、HTML 白名单清洗、robots.txt 判定与礼貌限速。

[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-406%20passed-success.svg)](tests/)
[![PHPStan](https://img.shields.io/badge/static%20analysis-clean-brightgreen.svg)](CONTRIBUTING.md)

## 简介

写爬虫时，80% 的代码不是「抓」，而是「解析」和「判断能不能抓」：

- 页面里哪些是真正的正文？导航和页脚混在里面，直接用会得到一堆垃圾摘要；
- 这条链接是站内还是站外？带没带 `nofollow`？
- 抓来的 HTML 能不能直接用？里面埋着 `<script>` 和 `onclick` 怎么办？
- 订阅源是 RSS 还是 Atom？10 MB 的一万条订阅源会不会撑爆内存？
- **这个站点的 robots.txt 到底允不允许我抓这一页？**

更麻烦的是合规：抓太快、把不该抓的抓了，法律责任在你而不在代码。

MornRain Scrape Kit 把这些**纯解析**的工作收敛成 6 个类，
并且**不含任何 HTTP 客户端**——它不主动发一个包，
所有网络请求都由你自己发起，库只负责「判断该不该发」和「怎么把结果洗干净」。

## 特性

| 能力 | 说明 |
| --- | --- |
| 元数据提取 | title / description / keywords / canonical / hreflang / OG / Twitter / article / JSON-LD |
| 质量体检 | `analyze()` 输出 0~100 分，逐条列出缺失项与超长项 |
| RSS / Atom 解析 | 三种格式统一为 `FeedItem`，`XMLReader` 两段式流式解析，内存 O(单条) |
| XXE 防护 | 主动拒绝含 DOCTYPE 的订阅源 |
| 链接分析 | 8 种类型分类、rel 标记解析、8 种用途识别、内外链比例评估 |
| 正文提取 | `stripNonContent()` 剔除导航 / 侧栏 / 页脚 / 评论区 |
| HTML 清洗 | 60+ 标签白名单、属性白名单、URL 协议白名单、样式过滤 |
| robots.txt | 遵循 RFC 9309：最长 UA 匹配、最长规则匹配、Allow 平局胜出 |
| 礼貌限速 | 按主机限速、全局限速、每日上限、自动采用 Crawl-delay |
| 零网络请求 | 全部离线可用，可安全接入 CI |

## 安装

```bash
composer require mornrain-lin/morn-scrape-kit
```

依赖：`ext-mbstring`、`ext-xmlreader`（均为常见默认扩展）。

或手动引入：

```php
require_once __DIR__ . '/morn-scrape-kit/src/FeedItem.php';
require_once __DIR__ . '/morn-scrape-kit/src/MetaExtractor.php';
require_once __DIR__ . '/morn-scrape-kit/src/RssParser.php';
require_once __DIR__ . '/morn-scrape-kit/src/LinkExtractor.php';
require_once __DIR__ . '/morn-scrape-kit/src/HtmlCleaner.php';
require_once __DIR__ . '/morn-scrape-kit/src/RobotsGuard.php';
require_once __DIR__ . '/morn-scrape-kit/src/RateLimiter.php';
```

## 快速开始

### 提取元数据

```php
use MornRain\ScrapeKit\MetaExtractor;

$extractor = new MetaExtractor('example.com');
$meta = $extractor->extract($html);

echo $meta['title'];
echo $meta['description'];
echo $meta['canonical'];
print_r($meta['keywords']);
print_r($meta['hreflang']);   // ['zh-cn' => '…', 'en-us' => '…', 'x-default' => '…']
print_r($meta['og']);
print_r($meta['twitter']);
print_r($meta['json_ld']);    // 页面内所有 JSON-LD 块
```

### 质量体检

```php
$report = $extractor->analyze($html);

echo '质量分: ' . $report['score'] . '/100' . PHP_EOL;
foreach ($report['issues'] as $issue) {
    echo '[' . $issue['level'] . '] ' . $issue['message'] . PHP_EOL;
}
// [ERROR] 缺少 meta description。
// [WARNING] title 长 120 字符，超过 65 会被搜索结果截断。
// [NOTICE] 缺少 canonical，可能产生重复内容。
```

### 解析订阅源

```php
use MornRain\ScrapeKit\RssParser;

$parser = new RssParser();
$result = $parser->parse($feedXml);

echo $result['format'];        // rss / atom / rdf
echo $result['meta']['title'];
echo $result['meta']['link'];

foreach ($result['items'] as $item) {
    echo $item->title();
    echo $item->link();
    echo $item->publishedIso();          // ISO 8601
    echo $item->author();                // 缺失时回退到邮箱
    print_r($item->categories());
    echo $item->plainSummary(120);       // 自动去标签的纯文本摘要
}

// 只要摘要不要正文？省内存
$items = $parser->withContent(false)->items($feedXml);
// 只要前 10 条？
$items = $parser->maxItems(10)->items($feedXml);
```

### 链接分析

```php
use MornRain\ScrapeKit\LinkExtractor;

$links = new LinkExtractor('example.com', 'https');
$analysis = $links->analyze($html);

print_r($analysis['by_type']);      // ['internal' => 14, 'external' => 2, ...]
echo $analysis['external_ratio'];   // 站外占比
echo $analysis['assessment']['message'];

// 只要正文链接（自动剔除导航/侧栏/页脚/评论区）
$contentLinks = $links->extractContent($html);
```

### 清洗 HTML

```php
use MornRain\ScrapeKit\HtmlCleaner;

$cleaner = new HtmlCleaner();

$safe = $cleaner->clean($html);        // 白名单 HTML
$text = $cleaner->toText($html);       // 纯文本（块级标签自动补换行）
$sum  = $cleaner->summarize($html);    // 200 字摘要

// 极简模式
$plain = (new HtmlCleaner())->stripImages()->stripLinks()->clean($html);

// 保留经过过滤的内联样式
$styled = $cleaner->keepStyle()->clean($html);

// 提取标题大纲
print_r($cleaner->outline($html));
// [['level' => 1, 'text' => '缓存击穿…'], ['level' => 2, 'text' => '为什么会发生'], …]
```

### robots.txt 判定

```php
use MornRain\ScrapeKit\RobotsGuard;

$guard = RobotsGuard::fromString(file_get_contents($robotsTxt));

if ($guard->isAllowed($url, 'MyBot')) {
    // 允许
} else {
    echo $guard->check($url, 'MyBot')['rule'];
    // 禁止抓取: /wp-admin/x（由 Disallow: /wp-admin/ 决定）
}

echo $guard->crawlDelay('*');   // 2.0
print_r($guard->sitemaps());
```

### 礼貌限速

```php
use MornRain\ScrapeKit\RateLimiter;

$limiter = new RateLimiter(1.0);
$limiter->applyRobots($guard, 'MyBot')->dailyLimit(100);

$result = $limiter->acquire($url);
if (! $result['allowed']) {
    usleep((int) ($result['wait'] * 1000000));
}

// 或者直接阻塞等待
$limiter->waitFor($url, 30.0);
```

### 完整合规抓取流程

本库**不含 HTTP 客户端**，请配合 cURL / Guzzle 使用。推荐的调用顺序：

```php
use MornRain\ScrapeKit\{RobotsGuard, RateLimiter, HtmlCleaner, LinkExtractor};

// 1. 抓 robots.txt
$robots = RobotsGuard::fromString(fetch("https://example.com/robots.txt"));

// 2. 判定是否允许
$check = $robots->check($url, 'MyBot');
if (! $check['allowed']) {
    throw new RuntimeException('robots.txt 禁止抓取：' . $check['rule']);
}

// 3. 限速
$limiter = new RateLimiter(1.0);
$limiter->applyRobots($robots, 'MyBot');
$wait = $limiter->waitFor($url, 30.0);
if (! $wait['granted']) {
    throw new RuntimeException('限速等待超时：' . $wait['reason']);
}

// 4. 发起请求（你自己的 HTTP 客户端）
$html = fetch($url);

// 5. 清洗
$cleaner = new HtmlCleaner();
$content = $cleaner->clean((new LinkExtractor('example.com'))->stripNonContent($html));
```

## API 一览表

### `MetaExtractor`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $siteHost = '')` | 站点主机名 |
| `extract` | `(string $html): array` | 提取全部元数据 |
| `title` / `description` / `canonical` | `(string $html): string` | 单项提取 |
| `jsonLd` | `(string $html): array` | JSON-LD |
| `hreflang` | `(string $html): array` | 多语言标注 |
| `analyze` | `(string $html): array` | 质量体检（含 `score` 与 `issues`） |

**`extract()` 返回结构：**

| 键 | 类型 | 说明 |
| --- | --- | --- |
| `title` / `description` / `author` / `robots` | string | 基础 SEO |
| `keywords` | array | 拆分后的关键词 |
| `generator` / `charset` / `viewport` / `lang` / `favicon` | string | 页面信息 |
| `canonical` | string | 规范链接 |
| `hreflang` | array | 语言 => URL |
| `alternate` | array | 非 hreflang 的 alternate link |
| `og` / `twitter` / `article` | array | 按前缀分组 |
| `json_ld` / `json_ld_count` | array / int | 结构化数据 |
| `images` | array | 图片 URL 清单 |

### `RssParser`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `parse` | `(string $xml): array` | 返回 `{items, meta, format}` |
| `items` | `(string $xml): array` | 只要条目 |
| `meta` | `(string $xml): array` | 只要源元数据 |
| `detectFormat` | `(string $xml): string` | 探测格式（不消费游标） |
| `maxItems` | `(int $max): self` | 条目数量上限，0 为不限 |
| `withContent` | `(bool $enabled = true): self` | 是否解析正文 |

### `FeedItem`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `title` / `content` / `summary` | `(): string` | 读取（缺标题时从正文兜底） |
| `plainSummary` | `(int $length = 200): string` | 纯文本摘要 |
| `link` / `guid` | `(): string` | 链接与唯一 ID |
| `publishedAt` / `updatedAt` / `publishedIso` | — | 时间 |
| `author` / `authorEmail` | `(): string` | 作者 |
| `categories` / `addCategory` / `setCategories` | — | 分类 |
| `enclosures` / `enclosureMeta` | — | 附件 |
| `contentType` / `language` / `commentCount` | — | 其他字段 |
| `set*` | — | 链式写入 |
| `toArray` / `describe` | — | 输出 |

### `LinkExtractor`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $siteHost = '', string $scheme = 'https')` | 站点信息 |
| `extract` | `(string $html): array` | 提取全部链接 |
| `extractContent` | `(string $html): array` | 只取正文区域的链接 |
| `analyze` | `(string $html): array` | 分类统计与评估建议 |
| `stripNonContent` | `(string $html): string` | 剔除导航 / 侧栏 / 页脚 / 评论区 |

### `HtmlCleaner`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `clean` | `(string $html): string` | 白名单清洗 |
| `toText` | `(string $html, bool $keepNewlines = true): string` | 纯文本 |
| `summarize` | `(string $html, int $length = 200): string` | 摘要 |
| `outline` | `(string $html): array` | 标题大纲 |
| `stripImages` / `stripLinks` / `stripStyle` | `(bool $enabled = true): self` | 极简模式 |
| `keepStyle` | `(bool $enabled = true): self` | 保留过滤后的内联样式 |
| `allowTags` / `allowAttributes` | `(array): self` | 覆盖白名单 |
| `maxLength` | `(int $length): self` | 限制输出长度 |

### `RobotsGuard`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` / `fromString` | `(string $robotsTxt)` | 构造 |
| `check` | `(string $url, string $ua = '*', ?string $baseUrl = null): array` | 详细判定 |
| `isAllowed` | `(string $url, string $ua = '*', ?string $baseUrl = null): bool` | 简判 |
| `crawlDelay` | `(string $userAgent = '*'): ?float` | 抓取间隔 |
| `sitemaps` | `(): array` | sitemap 列表 |
| `userAgents` | `(): array` | 已声明的 UA |
| `disallowRules` | `(): array` | 全部 Disallow 规则 |
| `summary` | `(): string` | 规则摘要 |
| `raw` | `(): string` | 原始内容 |

### `RateLimiter`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(float $defaultDelay = 1.0, string $storageDir = '')` | 最小间隔 |
| `acquire` | `(string $url): array` | 非阻塞判定 |
| `waitFor` | `(string $url, float $maxWait = 30.0): array` | 阻塞等待 |
| `setHostDelay` | `(string $host, float $seconds): self` | 单主机间隔 |
| `globalDelay` | `(float $seconds): self` | 全局间隔 |
| `dailyLimit` | `(int $limit): self` | 每日上限 |
| `applyRobots` | `(RobotsGuard $guard, string $ua = '*', ?float $fallback = null): self` | 采用 Crawl-delay |
| `todayCount` | `(string $url): int` | 今日请求数 |
| `reset` | `(string $url = ''): self` | 重置状态 |
| `config` | `(): array` | 当前配置 |

## Hook / 扩展点

本库**不注册任何 WordPress Hook**，也不发起网络请求。扩展通过继承完成：

```php
use MornRain\ScrapeKit\HtmlCleaner;
use MornRain\ScrapeKit\RobotsGuard;

/** 站点定制：允许 iframe 与 video（做视频聚合时） */
final class MediaCleaner extends HtmlCleaner
{
    public function __construct()
    {
        $this->allowTags(['p', 'a', 'img', 'figure', 'figcaption', 'video', 'source', 'iframe']);
        $this->allowAttributes([
            'video'  => ['src', 'controls', 'poster', 'width', 'height'],
            'source' => ['src', 'type', 'srcset'],
            'iframe' => ['src', 'width', 'height', 'allowfullscreen'],
        ]);
    }
}

/** 站点定制：在标准之上追加自己的规则 */
final class SiteRobots extends RobotsGuard
{
    public function check(string $url, string $userAgent = '*', ?string $baseUrl = null): array
    {
        $result = parent::check($url, $userAgent, $baseUrl);
        // 内部预览路径一律禁止，即使 robots.txt 没写
        if (strpos($url, '/preview/') !== false) {
            $result['allowed'] = false;
            $result['rule']    = '站点自定义规则：预览路径禁止抓取。';
        }
        return $result;
    }
}
```

## FAQ

**Q：这个库能帮我抓取网页吗？**
**不能，而且这是有意的。** 本库不含任何 HTTP 客户端，所有网络请求由你发起。
这样设计的原因：抓取行为的合规性需要由使用者根据具体场景判断——
目标是谁、你是什么身份、对方的服务条款怎么写的、有没有获得授权。
把这些决策藏在库的默认行为里，很容易在不知情下违规。
库提供的是**判断工具**（robots.txt、限速）和**解析工具**（清洗、提取）。

**Q：robots.txt 允许就一定能抓吗？**
不一定。robots.txt 是爬虫行为的技术约定，**不是授权文件**。
还需要考虑：服务条款（ToS）、版权与内容许可、数据保护法规（如 GDPR）、
robots.txt 本身的 robots  meta 标签。`RobotsGuard` 只回答技术问题。

**Q：10 MB 的 RSS 会不会撑爆内存？**
不会。`RssParser` 用 `XMLReader` 做两段式流式解析：
外层定位到 `<item>` 后用 `readOuterXml()` 取出该条目的完整 XML，
再用独立的 reader 递归解析这个**很小的片段**。
内存占用是 O(单条条目) 而非 O(整个文件)，
5000 条的订阅源与 5 条的占用几乎一样。
若只需摘要，用 `withContent(false)` 可进一步省内存。

**Q：为什么不用 SimpleXML？**
SimpleXML 一次性载入整棵树，10 MB 的 XML 展开后可能占用 200 MB+ 内存。
`XMLReader` 是 pull 模式，可逐节点控制。本库的两段式设计进一步规避了
XMLReader 游标错位问题（详见 `RssParser` 的注释）。

**Q：`stripNonContent()` 靠 class 名判断可靠吗？**
不完全可靠，这是启发式方法。语义标签（`nav` / `header` / `footer` / `aside`）
是可靠的，class 名匹配是对无标记老页面的兜底。
如果目标站点结构固定，建议覆盖本方法用自己的规则。

**Q：清洗后为什么 `style` 属性没了？**
默认丢弃（`stripStyle`）。这是有意的：内联样式是 XSS 的常见载体
（`behavior:url()`、`expression()`、`-moz-binding` 等）。
需要保留时用 `keepStyle()`，它只放行 18 个安全的排版属性，
其余全部过滤。

**Q：如何处理 `javascript:` 链接？**
`LinkExtractor` 会把它们标记为 `dangerous` 类型，
`HtmlCleaner` 会直接丢弃整个 `href`（保留锚文本）。
双重防护确保即使某一层漏判，另一层仍能拦住。

**Q：限速状态存在哪里？**
WordPress 环境用 Transient（可自动跟随对象缓存扩展），
纯 PHP 环境用临时目录下的文件 + 原子写入。
**跨请求有效**这一点很重要：只在进程内计数的限速器，
在每个请求都是新进程的场景下等于没限速。

**Q：多进程同时抓取时 `waitFor()` 会不会同时醒来？**
会，但 `waitFor()` 内部加了 0~0.3 秒随机抖动，
且拿到许可后会立刻更新 `last` 时间戳，
因此后续进程会重新计算等待时间，形成错峰。

**Q：`analyze()` 的质量分怎么算的？**
从 100 分起扣：error 每项 -20，warning 每项 -8，notice 每项 -3。
分值只用于快速排序，不要当 KPI 考核——
标题 63 字符和 61 字符对 SEO 没有本质区别。

## 目录说明

```
morn-scrape-kit/
├── README.md
├── LICENSE
├── CHANGELOG.md
├── composer.json
├── .gitignore
├── .gitattributes
├── src/
│   ├── FeedItem.php      # 统一条目模型
│   ├── MetaExtractor.php # SEO / OG / Twitter / JSON-LD 提取与质检
│   ├── RssParser.php     # RSS / Atom / RDF 流式解析
│   ├── LinkExtractor.php # 链接分类、rel 解析、正文提取
│   ├── HtmlCleaner.php   # HTML 白名单清洗
│   ├── RobotsGuard.php   # robots.txt 解析与判定（RFC 9309）
│   └── RateLimiter.php   # 礼貌限速
├── fixtures/
│   ├── sample-article.html   # 完整文章页（含 OG / JSON-LD / 危险链接）
│   ├── feed.xml              # RSS 2.0 样例
│   └── feed-atom.xml         # Atom 1.0 样例
├── tests/                   # 单元测试 + 零依赖运行器
│   ├── run-tests.php        # 零依赖测试运行器
│   ├── TestCase.php         # 断言（兼容 PHPUnit / 独立运行）
│   └── bootstrap.php        # PHPUnit 引导
├── phpunit.xml.dist         # PHPUnit 配置
├── phpcs.xml.dist           # PSR-12 代码风格
├── CONTRIBUTING.md          # 贡献指南
├── SECURITY.md              # 安全策略
└── examples/
    └── extract.php       # 10 个场景可运行示例
```

## 测试

本库提供两条等价的测试路径，用同一份用例：

```bash
# 零依赖方式，不需要 composer install
php tests/run-tests.php

# 只跑名称含某关键字的用例
php tests/run-tests.php robots

# 装了 PHPUnit 时
composer test          # 走 vendor/bin/phpunit
composer lint          # php -l 逐文件语法检查
composer lint:style    # PSR-12 代码风格
```

用例覆盖正常路径、边界情况（空值 / 零与负数 / 超长输入 / 多字节与 emoji）
与安全路径（注入、XSS、路径穿越、令牌篡改、重放）。
修bug 时请一并补上能复现该问题的断言。

参与贡献请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)；
发现安全问题请**不要**公开提issue，参见 [SECURITY.md](SECURITY.md)。

## License

MIT License — Copyright (c) 2026 mornrain-lin

详见 [LICENSE](LICENSE)。

**合规声明：** 本库是解析工具，不含 HTTP 客户端，不主动发起任何网络请求，
不收集、不传输任何数据。使用者须自行确保抓取行为符合目标站点的 robots.txt、
服务条款与当地法律法规。`RobotsGuard` 与 `RateLimiter` 的设计目的是
**帮助使用者更容易合规**，而非规避任何约束。
