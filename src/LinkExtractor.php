<?php
/**
 * HTML 正文链接提取器。
 *
 * 从 HTML 中提取全部 <a> 链接，并做四类判定：
 * - 内外链分类：同站（含子域）/ 站外 / 相对路径 / 锚点 / 协议相对；
 * - nofollow / noopener / noreferrer / ugc / sponsored 等 rel 标记；
 * - 特殊用途识别：分页、上一篇下一篇、下载、导航、作者、评论数、邮件订阅；
 * - 安全性检查：javascript: / data: 等危险协议。
 *
 * 合规提示：no follow 判定只用于**分析**页面结构，
 * 不应据此绕过搜索引擎的链接关系计算。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

/**
 * 链接提取器。
 */
class LinkExtractor
{
    /** 链接类型：站内 */
    public const TYPE_INTERNAL = 'internal';

    /** 链接类型：站外 */
    public const TYPE_EXTERNAL = 'external';

    /** 链接类型：相对路径 */
    public const TYPE_RELATIVE = 'relative';

    /** 链接类型：锚点 */
    public const TYPE_ANCHOR = 'anchor';

    /** 链接类型：协议相对 */
    public const TYPE_PROTOCOL_RELATIVE = 'protocol_relative';

    /** 链接类型：邮件 */
    public const TYPE_MAILTO = 'mailto';

    /** 链接类型：电话 */
    public const TYPE_TEL = 'tel';

    /** 链接类型：危险（javascript: 等） */
    public const TYPE_DANGEROUS = 'dangerous';

    /** @var string 站点主机名 */
    protected $siteHost = '';

    /** @var string 站点协议（用于补全相对链接） */
    protected $scheme = 'https';

    /** @var array<int,string> 需要在正文中忽略的 CSS 类名 */
    protected $ignoreClasses = ['nav', 'menu', 'sidebar', 'footer', 'header', 'comment', 'breadcrumb', 'pagination', 'widget', 'ad', 'ads', 'related'];

    /**
     * 构造函数。
     *
     * @param string $siteHost 站点主机名。
     * @param string $scheme   站点协议。
     */
    public function __construct(string $siteHost = '', string $scheme = 'https')
    {
        $this->siteHost = strtolower(trim($siteHost));
        $this->scheme   = in_array(strtolower($scheme), ['http', 'https'], true) ? strtolower($scheme) : 'https';
    }

    /**
     * 提取全部链接。
     *
     * @param string $html 页面 HTML。
     * @return array<int,array<string,mixed>>
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $links = [];

        // 必须捕获 href **前后**的全部属性：<a href="..." rel="nofollow"> 中
        // rel 位于 href 之后，若只取 href 之前的部分，rel/target/class 会被丢掉。
        $pattern = '#<a\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>(.*?)</a>#is';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $seen = [];
        foreach ($matches as $match) {
            $rawAttrs = $match[1] ?? '';
            $attrs    = $this->parseAttributes($rawAttrs);

            $href = '';
            if (isset($attrs['href'])) {
                $href = trim($attrs['href']);
            }
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '') {
                continue;
            }

            $anchorText = trim(strip_tags($match[2] ?? ''));
            $anchorText = html_entity_decode($anchorText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $link = $this->buildLink($href, $anchorText, $attrs, $rawAttrs);

            // 同一 URL + 锚文本只保留第一条
            $key = $link['url'] . '|' . $link['anchor'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $links[] = $link;
        }

        return $links;
    }

    /**
     * 提取并分类：返回统计摘要。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function analyze(string $html): array
    {
        $links = $this->extract($html);

        $byType   = [];
        $nofollow = [];
        $special  = [];
        $dangerous = [];

        foreach ($links as $link) {
            $byType[$link['type']] = ($byType[$link['type']] ?? 0) + 1;
            if ($link['nofollow']) {
                $nofollow[] = $link;
            }
            if ($link['purpose'] !== 'normal') {
                $special[] = $link;
            }
            if ($link['dangerous']) {
                $dangerous[] = $link;
            }
        }

        $externalRatio = count($links) > 0
            ? round((($byType[self::TYPE_EXTERNAL] ?? 0)) / count($links), 4)
            : 0.0;

        return [
            'total'          => count($links),
            'by_type'        => $byType,
            'unique_urls'    => count(array_unique(array_column($links, 'url'))),
            'nofollow_count' => count($nofollow),
            'special_count'  => count($special),
            'dangerous_count' => count($dangerous),
            'dangerous'      => $dangerous,
            'external_ratio' => $externalRatio,
            'assessment'     => $this->assess($externalRatio, count($nofollow), count($links), count($dangerous)),
            'links'          => $links,
        ];
    }

    /**
     * 只提取正文区域的链接（剔除导航、侧栏、页脚、评论区）。
     *
     * 采用容器剔除法：先移除 class / id 含导航特征的区块，再提取链接。
     *
     * @param string $html 页面 HTML。
     * @return array<int,array<string,mixed>>
     */
    public function extractContent(string $html): array
    {
        return $this->extract($this->stripNonContent($html));
    }

    /**
     * 剔除导航、侧栏、页脚、评论区等非正文区块。
     *
     * @param string $html 页面 HTML。
     * @return string
     */
    public function stripNonContent(string $html): string
    {
        $result = $html;

        // 1. 移除语义标签
        $result = preg_replace('#<(nav|header|footer|aside)\b[^>]*>.*?</\1>#is', '', $result) ?? $result;

        // 2. 移除 class / id 含导航特征的容器（含配对标签与自闭合两种）
        foreach ($this->ignoreClasses as $class) {
            $pattern = '#<([a-z][a-z0-9]*)\b[^>]*(?:class|id)\s*=\s*["\'][^"\']*\b'
                . preg_quote($class, '#')
                . '\b[^"\']*["\'][^>]*>.*?</\1>#is';
            $result = preg_replace($pattern, '', $result) ?? $result;
        }

        // 3. 移除 HTML 注释（常含导航占位）
        $result = preg_replace('#<!--.*?-->#s', '', $result) ?? $result;

        return $result;
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 构造单个链接的结构化描述。
     *
     * @param string               $href       原始 href。
     * @param string               $anchorText 锚文本。
     * @param array<string,string> $attrs      属性表。
     * @param string               $rawAttrs   原始属性串。
     * @return array<string,mixed>
     */
    protected function buildLink(string $href, string $anchorText, array $attrs, string $rawAttrs): array
    {
        $relList = $this->relList($attrs['rel'] ?? '');
        $type    = $this->classify($href);
        $url     = $this->absoluteUrl($href);
        $danger  = $type === self::TYPE_DANGEROUS;

        return [
            'href'        => $href,
            'url'         => $url,
            'anchor'      => $anchorText,
            'type'        => $type,
            'internal'    => $type === self::TYPE_INTERNAL,
            'external'    => $type === self::TYPE_EXTERNAL,
            'rel'         => $relList,
            'nofollow'    => in_array('nofollow', $relList, true),
            'noopener'    => in_array('noopener', $relList, true) || (in_array('noreferrer', $relList, true) && $attrs['target'] === '_blank'),
            'noreferrer'  => in_array('noreferrer', $relList, true),
            'ugc'         => in_array('ugc', $relList, true),
            'sponsored'   => in_array('sponsored', $relList, true),
            'target'      => $attrs['target'] ?? '',
            'title'       => $attrs['title'] ?? '',
            'purpose'     => $danger ? 'dangerous' : $this->detectPurpose($href, $anchorText, $attrs),
            'dangerous'   => $danger,
            'raw_attrs'   => trim($rawAttrs),
        ];
    }

    /**
     * 解析 rel 属性为小写数组。
     *
     * @return array<int,string>
     */
    protected function relList(string $rel): array
    {
        $rel = strtolower(trim($rel));
        if ($rel === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $rel) ?: []));
    }

    /**
     * 判定链接类型。
     */
    protected function classify(string $href): string
    {
        if ($href === '') {
            return self::TYPE_RELATIVE;
        }
        if ($href[0] === '#') {
            return self::TYPE_ANCHOR;
        }
        if (strpos($href, '//') === 0) {
            return self::TYPE_PROTOCOL_RELATIVE;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme === 'mailto') {
            return self::TYPE_MAILTO;
        }
        if ($scheme === 'tel') {
            return self::TYPE_TEL;
        }
        if ($scheme === 'javascript' || $scheme === 'vbscript' || $scheme === 'data') {
            return self::TYPE_DANGEROUS;
        }
        // http 与 https 都是正常协议；其余带 scheme 的一律视为危险
        // （javascript / vbscript / data / file / ftp 等）
        if ($scheme !== 'http' && $scheme !== 'https' && $scheme !== '') {
            return self::TYPE_DANGEROUS;
        }

        if ($scheme === '') {
            // 相对路径：无法在缺少基准的情况下判定内外链
            return $this->siteHost !== '' && $this->looksInternal($href)
                ? self::TYPE_INTERNAL
                : self::TYPE_RELATIVE;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host === '') {
            return self::TYPE_RELATIVE;
        }

        return $this->isSameSite($host) ? self::TYPE_INTERNAL : self::TYPE_EXTERNAL;
    }

    /**
     * 判断 host 是否属于本站（含子域）。
     */
    protected function isSameSite(string $host): bool
    {
        $host   = strtolower($host);
        $origin = $this->siteHost;

        if ($origin === '') {
            return false;
        }

        return $host === $origin || substr($host, -strlen('.' . $origin)) === '.' . $origin;
    }

    /**
     * 启发式判断相对路径是否指向本站。
     */
    protected function looksInternal(string $href): bool
    {
        if ($href === '' || $href[0] === '/' || $href[0] === '#' || $href[0] === '?') {
            return true;
        }

        // 形如 blog/post-1.html 的单段相对路径，视为站内
        return strpos($href, '/') === false && preg_match('/^[\w\-.]+\.(html?|php|aspx?)$/i', $href) === 1;
    }

    /**
     * 补全为绝对 URL。
     */
    protected function absoluteUrl(string $href): string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return $href;
        }
        if (strpos($href, '//') === 0) {
            return $this->scheme . ':' . $href;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme !== '') {
            return $href;
        }
        if ($this->siteHost === '') {
            return $href;
        }
        if ($href[0] === '/') {
            return $this->scheme . '://' . $this->siteHost . $href;
        }

        return $this->scheme . '://' . $this->siteHost . '/' . $href;
    }

    /**
     * 识别链接用途。
     *
     * @param string               $href       原始 href。
     * @param string               $anchorText 锚文本。
     * @param array<string,string> $attrs      属性表。
     * @return string pagination / prev_next / download / author / comment / subscribe / mail / tel / external / normal
     */
    protected function detectPurpose(string $href, string $anchorText, array $attrs): string
    {
        $lowerHref = strtolower($href);
        $lowerText = strtolower($anchorText);
        $classAttr = strtolower(($attrs['class'] ?? '') . ' ' . ($attrs['id'] ?? ''));
        $combined  = $lowerHref . ' ' . $lowerText . ' ' . $classAttr;

        $rules = [
            'pagination' => ['/page/', 'pagination', 'paged', '分页', '下一页', '上一页'],
            'prev_next'  => ['/prev', '/next', 'prev-post', 'next-post', 'post-nav'],
            'download'   => ['.zip', '.rar', '.7z', '.tar.gz', '.pdf', '.epub', 'download', '下载'],
            'author'     => ['/author/', '/user/', 'author', 'byline', '作者'],
            'comment'    => ['#comments', '#respond', 'comment', '评论', 'reply'],
            'subscribe'  => ['/feed', '/rss', '.atom', 'subscribe', 'rss', '订阅'],
        ];

        foreach ($rules as $purpose => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($combined, $needle) !== false) {
                    return $purpose;
                }
            }
        }

        $type = $this->classify($href);
        if ($type === self::TYPE_MAILTO) {
            return 'mail';
        }
        if ($type === self::TYPE_TEL) {
            return 'tel';
        }
        if ($type === self::TYPE_EXTERNAL) {
            return 'external';
        }

        return 'normal';
    }

    /**
     * 评估链接结构。
     *
     * @return array{level:string,message:string,advice:array<int,string>}
     */
    protected function assess(float $externalRatio, int $nofollow, int $total, int $dangerous): array
    {
        $advice = [];

        if ($dangerous > 0) {
            $advice[] = sprintf('存在 %d 个 javascript:/data: 等危险协议链接，应立即移除。', $dangerous);
        }

        if ($externalRatio > 0.5 && $total >= 10) {
            $advice[] = sprintf('站外链接占比 %.0f%%，超过 50%%，正文权威性信号可能被稀释。', $externalRatio * 100);
        } elseif ($externalRatio < 0.05 && $total >= 10) {
            $advice[] = '几乎没有站外链接，建议适当引用权威来源以增强可信度。';
        } else {
            $advice[] = sprintf('站外链接占比 %.0f%%，处于合理区间。', $externalRatio * 100);
        }

        if ($nofollow === 0 && $externalRatio > 0.3) {
            $advice[] = '站外链接较多但无一条带 rel="nofollow"，付费或 UGC 链接应补充该属性。';
        }

        $level = 'ok';
        if ($dangerous > 0) {
            $level = 'danger';
        } elseif ($externalRatio > 0.7) {
            $level = 'warn';
        }

        return [
            'level'   => $level,
            'message' => $level === 'danger'
                ? '存在危险协议链接，必须处理。'
                : ($level === 'warn' ? '站外链接密度偏高，建议复核。' : '链接结构正常。'),
            'advice'  => $advice,
        ];
    }

    /**
     * 解析标签属性。
     *
     * @param string $raw 属性串。
     * @return array<string,string>
     */
    protected function parseAttributes(string $raw): array
    {
        $attrs = [];
        if (preg_match_all(
            '#([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*(?:=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#s',
            $raw,
            $matches,
            PREG_SET_ORDER
        ) === false) {
            return $attrs;
        }

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[3] ?? '';
                if ($value === '' && isset($match[4])) {
                    $value = $match[4];
                }
                if ($value === '' && isset($match[5])) {
                    $value = $match[5];
                }
            } else {
                $value = $name;
            }
            $attrs[$name] = $value;
        }

        return $attrs;
    }
}
