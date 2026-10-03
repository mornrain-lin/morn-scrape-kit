<?php
/**
 * morn-scrape-kit 使用示例。
 *
 * 运行方式（CLI）：
 *   php examples/extract.php
 *
 * 覆盖元数据提取、链接分析、HTML 清洗、RSS/Atom 解析、
 * robots.txt 判定与限速。全部本地运行，不发起任何网络请求。
 *
 * 合规提示：本示例解析的 HTML 均为随包分发的本地样例文件。
 * 实际使用时请先用 RobotsGuard 确认目标站点允许抓取，
 * 并用 RateLimiter 控制请求频率。
 */

declare(strict_types=1);

require __DIR__ . '/../src/FeedItem.php';
require __DIR__ . '/../src/MetaExtractor.php';
require __DIR__ . '/../src/RssParser.php';
require __DIR__ . '/../src/LinkExtractor.php';
require __DIR__ . '/../src/HtmlCleaner.php';
require __DIR__ . '/../src/RobotsGuard.php';
require __DIR__ . '/../src/RateLimiter.php';

use MornRain\ScrapeKit\HtmlCleaner;
use MornRain\ScrapeKit\LinkExtractor;
use MornRain\ScrapeKit\MetaExtractor;
use MornRain\ScrapeKit\RateLimiter;
use MornRain\ScrapeKit\RobotsGuard;
use MornRain\ScrapeKit\RssParser;

function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

$articleHtml = (string) file_get_contents(__DIR__ . '/../fixtures/sample-article.html');

/* ------------------------------------------------------------------ */
section('1. MetaExtractor：元数据提取');

$extractor = new MetaExtractor('example.com');
$meta      = $extractor->extract($articleHtml);

printf('title       : %s' . PHP_EOL, $meta['title']);
printf('description : %s' . PHP_EOL, mb_substr($meta['description'], 0, 60) . '…');
printf('keywords    : %s' . PHP_EOL, implode(' / ', $meta['keywords']));
printf('author      : %s' . PHP_EOL, $meta['author']);
printf('robots      : %s' . PHP_EOL, $meta['robots']);
printf('generator   : %s' . PHP_EOL, $meta['generator']);
printf('charset     : %s' . PHP_EOL, $meta['charset']);
printf('lang        : %s' . PHP_EOL, $meta['lang']);
printf('favicon     : %s' . PHP_EOL, $meta['favicon']);
printf('canonical   : %s' . PHP_EOL, $meta['canonical']);
printf('images      : %d 个' . PHP_EOL, count($meta['images']));

echo PHP_EOL . 'hreflang 多语言标注：' . PHP_EOL;
foreach ($meta['hreflang'] as $lang => $url) {
    echo '    ' . str_pad($lang, 10) . ' → ' . $url . PHP_EOL;
}

echo PHP_EOL . 'Open Graph：' . PHP_EOL;
foreach ($meta['og'] as $key => $value) {
    echo '    ' . str_pad($key, 24) . ' = ' . (mb_strlen($value) > 56 ? mb_substr($value, 0, 56) . '…' : $value) . PHP_EOL;
}

echo PHP_EOL . 'Twitter Card：' . PHP_EOL;
foreach ($meta['twitter'] as $key => $value) {
    echo '    ' . str_pad($key, 24) . ' = ' . $value . PHP_EOL;
}

echo PHP_EOL . 'article:* 元数据：' . PHP_EOL;
foreach ($meta['article'] as $key => $value) {
    echo '    ' . str_pad($key, 24) . ' = ' . $value . PHP_EOL;
}

echo PHP_EOL . 'JSON-LD 结构化数据（' . $meta['json_ld_count'] . ' 块）：' . PHP_EOL;
foreach ($meta['json_ld'] as $index => $block) {
    $type = $block['@type'] ?? '(未知)';
    echo '    #' . $index . ' ' . $type . ' → ' . implode(', ', array_keys($block)) . PHP_EOL;
}

/* ------------------------------------------------------------------ */
section('2. MetaExtractor::analyze()：元数据质量体检');

$report = $extractor->analyze($articleHtml);
printf('质量分: %d / 100，问题 %d 项' . PHP_EOL, $report['score'], $report['issue_count']);
foreach ($report['issues'] as $issue) {
    echo '    [' . strtoupper($issue['level']) . '] ' . $issue['message'] . PHP_EOL;
}

// 反例：缺 title / description / canonical 的页面
$poor = '<html><head><title>' . str_repeat('超长标题', 30) . '</title></head><body>无描述</body></html>';
echo PHP_EOL . '[残缺页面] 质量分: ' . $extractor->analyze($poor)['score'] . PHP_EOL;
foreach ($extractor->analyze($poor)['issues'] as $issue) {
    echo '    [' . strtoupper($issue['level']) . '] ' . $issue['message'] . PHP_EOL;
}

/* ------------------------------------------------------------------ */
section('3. LinkExtractor：链接分析');

$links = new LinkExtractor('example.com', 'https');
$analysis = $links->analyze($articleHtml);

printf('总链接 %d 个，去重后 %d 个 URL' . PHP_EOL, $analysis['total'], $analysis['unique_urls']);
echo '按类型：' . PHP_EOL;
foreach ($analysis['by_type'] as $type => $count) {
    echo '    ' . str_pad($type, 20) . $count . PHP_EOL;
}
printf('nofollow: %d 个，站外占比 %.0f%%，危险链接 %d 个' . PHP_EOL,
    $analysis['nofollow_count'],
    $analysis['external_ratio'] * 100,
    $analysis['dangerous_count']
);
echo '评估：[' . $analysis['assessment']['level'] . '] ' . $analysis['assessment']['message'] . PHP_EOL;
foreach ($analysis['assessment']['advice'] as $advice) {
    echo '    → ' . $advice . PHP_EOL;
}

echo PHP_EOL . '特殊用途链接：' . PHP_EOL;
foreach ($analysis['links'] as $link) {
    if ($link['purpose'] === 'normal') {
        continue;
    }
    printf(
        "    %-11s %-46s %s%s\n",
        $link['purpose'],
        mb_substr($link['url'], 0, 46),
        $link['anchor'] !== '' ? '"' . $link['anchor'] . '" ' : '',
        $link['nofollow'] ? '[nofollow]' : ''
    );
}

echo PHP_EOL . '危险链接（应被拦截）：' . PHP_EOL;
foreach ($analysis['dangerous'] as $link) {
    echo '    ' . $link['type'] . ' → ' . mb_substr($link['href'], 0, 50) . PHP_EOL;
}

echo PHP_EOL . '正文链接（已剔除导航 / 侧栏 / 页脚 / 评论区）：' . PHP_EOL;
$contentLinks = $links->extractContent($articleHtml);
printf('    全文 %d 个 → 正文 %d 个（剔除 %d 个）' . PHP_EOL,
    $analysis['total'],
    count($contentLinks),
    $analysis['total'] - count($contentLinks)
);
foreach ($contentLinks as $link) {
    if ($link['external']) {
        echo '    外链: ' . $link['url'] . ($link['nofollow'] ? ' [nofollow]' : '') . PHP_EOL;
    }
}

/* ------------------------------------------------------------------ */
section('4. HtmlCleaner：HTML 清洗');

$cleaner = new HtmlCleaner();

$dirty = '<div class="post" onclick="alert(1)">'
    . '<script>alert(document.cookie)</script>'
    . '<style>body{margin:0}</style>'
    . '<p style="color:red;behavior:url(x);text-align:center">正文段落</p>'
    . '<a href="javascript:alert(2)">恶意链接</a>'
    . '<a href="https://example.com/ok" target="_blank">正常外链</a>'
    . '<img src="x.png" onerror="alert(3)" alt="图">'
    . '<iframe src="https://evil.example"></iframe>'
    . '<svg onload="alert(4)"><script>alert(5)</script></svg>'
    . '<form action="/x"><input name="a"></form>'
    . '<!-- 注释中的秘密 -->'
    . '<h2>小标题</h2><blockquote>引用</blockquote>'
    . '</div>';

echo '清洗前: ' . $dirty . PHP_EOL . PHP_EOL;
echo '清洗后: ' . $cleaner->clean($dirty) . PHP_EOL . PHP_EOL;

echo '纯文本: ' . $cleaner->toText($dirty) . PHP_EOL;
echo '摘要  : ' . $cleaner->summarize($dirty, 60) . PHP_EOL;

echo PHP_EOL . '正文清洗（保留内联样式白名单）：' . PHP_EOL;
echo $cleaner->keepStyle()->clean($dirty) . PHP_EOL;

echo PHP_EOL . '极简模式（去图片 + 去链接 + 保留文字）：' . PHP_EOL;
$minimal = (new HtmlCleaner())->stripImages()->stripLinks();
echo $minimal->clean('<p>看这里 <a href="https://example.com">详情</a> <img src="a.png" alt="图"></p>') . PHP_EOL;

echo PHP_EOL . '内容大纲：' . PHP_EOL;
foreach ($cleaner->outline($articleHtml) as $node) {
    echo '    ' . str_repeat('  ', $node['level'] - 1) . 'H' . $node['level'] . ' ' . $node['text'] . PHP_EOL;
}

echo PHP_EOL . '清洗后的正文（先用 LinkExtractor 剔除导航/页脚，再取摘要）：' . PHP_EOL;
$contentOnly = $cleaner->clean($links->stripNonContent($articleHtml));
echo '    ' . mb_substr($cleaner->summarize($contentOnly, 200), 0, 200) . '…' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('5. RssParser：RSS 2.0 解析');

$rss = (string) file_get_contents(__DIR__ . '/../fixtures/feed.xml');
$parser = new RssParser();
$result = $parser->parse($rss);

printf('格式: %s，条目: %d 条' . PHP_EOL, $result['format'], count($result['items']));
echo '订阅源元数据：' . PHP_EOL;
foreach ($result['meta'] as $key => $value) {
    echo '    ' . str_pad($key, 18) . ' = ' . (mb_strlen($value) > 52 ? mb_substr($value, 0, 52) . '…' : $value) . PHP_EOL;
}

echo PHP_EOL . '条目：' . PHP_EOL;
foreach ($result['items'] as $index => $item) {
    echo '[' . $index . '] ' . $item->title() . PHP_EOL;
    echo '     链接  : ' . ($item->link() ?: '-') . PHP_EOL;
    echo '     时间  : ' . ($item->publishedIso() ?: '-') . PHP_EOL;
    echo '     作者  : ' . ($item->author() ?: '-') . PHP_EOL;
    echo '     分类  : ' . (implode(', ', $item->categories()) ?: '-') . PHP_EOL;
    echo '     附件  : ' . (implode(', ', $item->enclosures()) ?: '-') . PHP_EOL;
    echo '     摘要  : ' . mb_substr($item->plainSummary(70), 0, 70) . PHP_EOL;
    echo '     正文  : ' . mb_strlen(strip_tags($item->content())) . ' 字符' . PHP_EOL;
}

/* ------------------------------------------------------------------ */
section('6. RssParser：Atom 1.0 解析');

$atom = (string) file_get_contents(__DIR__ . '/../fixtures/feed-atom.xml');
$atomResult = $parser->parse($atom);

printf('格式: %s，条目: %d 条' . PHP_EOL, $atomResult['format'], count($atomResult['items']));
echo '订阅源元数据：' . PHP_EOL;
foreach ($atomResult['meta'] as $key => $value) {
    echo '    ' . str_pad($key, 18) . ' = ' . $value . PHP_EOL;
}

echo PHP_EOL . '条目：' . PHP_EOL;
foreach ($atomResult['items'] as $index => $item) {
    echo '[' . $index . '] ' . $item->title() . PHP_EOL;
    echo '     链接  : ' . ($item->link() ?: '-') . PHP_EOL;
    echo '     时间  : ' . ($item->publishedIso() ?: '-') . PHP_EOL;
    echo '     作者  : ' . ($item->author() ?: '-') . ' <' . ($item->authorEmail() ?: '-') . '>' . PHP_EOL;
    echo '     分类  : ' . (implode(', ', $item->categories()) ?: '-') . PHP_EOL;
    echo '     内容  : ' . mb_substr($item->content(), 0, 60) . PHP_EOL;
}

echo PHP_EOL . '格式探测: feed.xml → ' . $parser->detectFormat($rss)
    . '，feed-atom.xml → ' . $parser->detectFormat($atom) . PHP_EOL;

echo PHP_EOL . 'maxItems(2) 限制生效：' . count((new RssParser())->maxItems(2)->items($rss)) . ' 条' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('7. RssParser：异常与安全处理');

$cases = [
    '空输入'       => '',
    '非法 XML'    => '<rss><channel><title>未闭合</channel>',
    '含 DOCTYPE'  => '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&xxe;</title></channel></rss>',
    '无条目'      => '<?xml version="1.0"?><rss version="2.0"><channel><title>空源</title></channel></rss>',
];

foreach ($cases as $label => $xml) {
    try {
        $r = $parser->parse($xml);
        printf('  %-12s → 正常，格式 %s，%d 条条目' . PHP_EOL, $label, $r['format'], count($r['items']));
    } catch (RuntimeException $e) {
        printf('  %-12s → 已拒绝：%s' . PHP_EOL, $label, $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
section('8. RobotsGuard：robots.txt 解析与判定');

$robotsTxt = <<<'ROBOTS'
# 示例 robots.txt
User-agent: *
Disallow: /wp-admin/
Disallow: /wp-login.php
Disallow: /tmp/
Allow: /wp-admin/admin-ajax.php
Crawl-delay: 2

User-agent: BadBot
Disallow: /

User-agent: Googlebot
User-agent: Bingbot
Disallow: /private/
Disallow: /search?
Allow: /search/public
Sitemap: https://example.com/sitemap.xml
ROBOTS;

$guard = RobotsGuard::fromString($robotsTxt);

echo $guard->summary() . PHP_EOL . PHP_EOL;

echo '已声明的 User-agent: ' . implode(', ', $guard->userAgents()) . PHP_EOL;
echo 'Disallow 规则: ' . implode(', ', $guard->disallowRules()) . PHP_EOL;
echo 'Sitemap: ' . implode(', ', $guard->sitemaps()) . PHP_EOL;
echo 'Crawl-delay(*): ' . var_export($guard->crawlDelay('*'), true) . ' 秒' . PHP_EOL;
echo 'Crawl-delay(Googlebot): ' . var_export($guard->crawlDelay('Googlebot'), true)
    . '（该 UA 分组未声明，按标准应回退到 * 组或调用方默认值）' . PHP_EOL . PHP_EOL;

$checks = [
    ['/', '*'],
    ['/blog/hello-world', '*'],
    ['/wp-admin/install.php', '*'],
    ['/wp-login.php', '*'],
    ['/wp-admin/admin-ajax.php', '*'],
    ['/tmp/cache.tmp', '*'],
    ['/private/notes', 'Googlebot'],
    ['/private/notes', '*'],
    ['/search?q=php', 'Googlebot'],
    ['/search/public?q=php', 'Googlebot'],
    ['/anything', 'BadBot'],
    ['/anything', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
];

echo str_pad('路径', 30) . str_pad('UA', 22) . '结果' . PHP_EOL;
echo str_repeat('-', 78) . PHP_EOL;
foreach ($checks as [$path, $ua]) {
    $check = $guard->check($path, $ua);
    printf(
        "%-30s %-22s %s\n",
        mb_substr($path, 0, 28),
        mb_substr($ua, 0, 20),
        $check['allowed'] ? '允许' : '禁止'
    );
}

echo PHP_EOL . '最长匹配优先示例（/search? 与 /search/public）：' . PHP_EOL;
echo '  /search?q=php        → ' . ($guard->isAllowed('/search?q=php', 'Googlebot') ? '允许' : '禁止')
    . '（Disallow 长度 8 更长）' . PHP_EOL;
echo '  /search/public?q=php → ' . ($guard->isAllowed('/search/public?q=php', 'Googlebot') ? '允许' : '禁止')
    . '（Allow 长度 15 更长）' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('9. RateLimiter：礼貌抓取限速');

$limiter = new RateLimiter(1.0, sys_get_temp_dir() . '/morn-scrape-demo');
$limiter->applyRobots($guard, '*')->dailyLimit(5);
$limiter->reset();

echo '配置: ' . json_encode($limiter->config(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo '（Crawl-delay 2 秒已从 robots.txt 应用到 default_delay）' . PHP_EOL . PHP_EOL;

$urls = [
    'https://example.com/blog/1',
    'https://example.com/blog/2',
    'https://example.com/blog/3',
];

foreach ($urls as $url) {
    $result = $limiter->acquire($url);
    printf(
        "  %-32s %s  %s\n",
        parse_url($url, PHP_URL_PATH),
        $result['allowed'] ? '放行' : sprintf('限流(等 %.1fs)', $result['wait']),
        $result['reason']
    );
}

// 另一个主机，独立计数
$other = $limiter->acquire('https://other-site.test/page');
echo '  ' . sprintf('%-32s %s', 'other-site.test/page', $other['allowed'] ? '放行' : '限流')
    . '  ' . $other['reason'] . PHP_EOL;

echo PHP_EOL . '今日请求数（example.com）: ' . $limiter->todayCount('https://example.com/blog/1') . PHP_EOL;

// 触发每日上限
$limiter->setHostDelay('example.com', 0.0);
for ($i = 0; $i < 5; $i++) {
    $r = $limiter->acquire('https://example.com/blog/x' . $i);
}
$blocked = $limiter->acquire('https://example.com/blog/blocked');
echo '触发每日上限后: ' . ($blocked['allowed'] ? '仍放行' : '已阻止')
    . ' → ' . $blocked['reason'] . PHP_EOL;

$limiter->reset();
echo PHP_EOL . 'reset() 后今日请求数: ' . $limiter->todayCount('https://example.com/blog/1') . PHP_EOL;

/* ------------------------------------------------------------------ */
section('10. 综合：抓取前的合规检查流程');

// 这个流程才是本库推荐的使用方式
function crawlPreflight(string $url, string $userAgent, RobotsGuard $guard, RateLimiter $limiter): array
{
    $steps = [];

    $check = $guard->check($url, $userAgent);
    $steps[] = [
        'step'    => 'robots.txt',
        'passed'  => $check['allowed'],
        'detail'  => $check['rule'],
    ];

    $rate = $limiter->acquire($url);
    $steps[] = [
        'step'    => '限速',
        'passed'  => $rate['allowed'],
        'detail'  => $rate['reason'],
    ];

    return [
        'can_crawl' => $check['allowed'] && $rate['allowed'],
        'steps'     => $steps,
    ];
}

$limiter->reset();
$limiter->setHostDelay('example.com', 0.0);

foreach (['https://example.com/blog/ok', 'https://example.com/wp-admin/x'] as $target) {
    $pre = crawlPreflight($target, 'MornRainBot', $guard, $limiter);
    echo ($pre['can_crawl'] ? '允许' : '拒绝') . '  ' . $target . PHP_EOL;
    foreach ($pre['steps'] as $step) {
        echo '    ' . ($step['passed'] ? '[通过]' : '[拦截]') . ' ' . str_pad($step['step'], 12) . $step['detail'] . PHP_EOL;
    }
}

// 收尾
$limiter->reset();

echo PHP_EOL . 'ScrapeKit 示例运行结束。' . PHP_EOL;
echo '提示：本库不含任何 HTTP 客户端，请在自行确认合规后配合 cURL / Guzzle 使用。' . PHP_EOL;
