<?php
/**
 * HTML 元数据提取器。
 *
 * 提取内容：
 * - 基础 SEO：title / description / keywords / author / robots
 * - canonical 链接与 hreflang 多语言标注
 * - Open Graph（og:*）与 Twitter Card（twitter:*）
 * - 页面内 JSON-LD 结构化数据（application/ld+json）
 * - 基础信息：字符集、视口、favicon、站点名、语言
 *
 * 实现要点：
 * - 纯正则实现，不依赖 DOMDocument（部分 PHP 环境未启用 ext-dom）；
 * - 所有提取均为「只读」，不发起任何网络请求；
 * - 遇到重复标签时保留第一个非空值（与搜索引擎行为一致）。
 *
 * 合规提示：本类仅用于解析**已合法获取**的 HTML（例如用户自己站点、
 * 已获授权的公开内容、或用于本地测试）。抓取他人站点前请先用 RobotsGuard
 * 确认 robots.txt 允许，并遵守 RateLimiter 的礼貌间隔。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

/**
 * 元数据提取器。
 */
class MetaExtractor
{
    /** @var int OG 属性名前缀长度（用于统一 key 大小写） */
    protected const OG_PREFIX = 'og:';

    /** @var string 站点主机名，用于判断 og:url 归属 */
    protected $siteHost = '';

    /**
     * 构造函数。
     *
     * @param string $siteHost 站点主机名。
     */
    public function __construct(string $siteHost = '')
    {
        $this->siteHost = strtolower(trim($siteHost));
    }

    /**
     * 从 HTML 中提取全部元数据。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return $this->emptyResult();
        }

        $metas    = $this->collectMetas($html);
        $links    = $this->collectLinks($html);
        $jsonLd   = $this->extractJsonLd($html);

        $data = $this->emptyResult();

        // ---- 基础 SEO ----
        $data['title']        = $this->extractTitleTag($html);
        $data['description']  = $this->firstContent($metas, ['description', 'og:description', 'twitter:description']);
        $data['keywords']     = $this->extractKeywords($metas);
        $data['author']       = $this->firstContent($metas, ['author', 'article:author', 'og:article:author']);
        $data['robots']       = $this->firstContent($metas, ['robots']);
        $data['generator']    = $this->extractGenerator($html);
        $data['charset']      = $this->extractCharset($html, $metas);
        $data['viewport']     = $this->firstContent($metas, ['viewport']);
        $data['lang']         = $this->extractLang($html);
        $data['favicon']      = $this->extractFavicon($links);

        // ---- canonical 与 hreflang ----
        $data['canonical']    = $this->extractCanonical($links);
        $data['hreflang']     = $this->extractHreflang($links);
        $data['alternate']    = $this->extractAlternate($links);

        // ---- Open Graph ----
        $data['og']           = $this->extractPrefixed($metas, 'og:');
        $data['twitter']      = $this->extractPrefixed($metas, 'twitter:');
        $data['article']      = $this->extractArticle($metas);

        // ---- 结构化数据 ----
        $data['json_ld']      = $jsonLd;
        $data['json_ld_count'] = count($jsonLd);

        // ---- 图片 ----
        $data['images']       = $this->extractImages($metas, $html);

        return $data;
    }

    /**
     * 仅提取 title。
     */
    public function title(string $html): string
    {
        return $this->extractTitleTag($html);
    }

    /**
     * 仅提取 description。
     */
    public function description(string $html): string
    {
        $metas = $this->collectMetas($html);

        return $this->firstContent($metas, ['description', 'og:description', 'twitter:description']);
    }

    /**
     * 仅提取 canonical。
     */
    public function canonical(string $html): string
    {
        return $this->extractCanonical($this->collectLinks($html));
    }

    /**
     * 仅提取 JSON-LD。
     *
     * @return array<int,array<string,mixed>>
     */
    public function jsonLd(string $html): array
    {
        return $this->extractJsonLd($html);
    }

    /**
     * 仅提取 hreflang 标注。
     *
     * @return array<string,string> 语言 => URL
     */
    public function hreflang(string $html): array
    {
        return $this->extractHreflang($this->collectLinks($html));
    }

    /**
     * 生成元数据质量报告。
     *
     * 用于内容入库前的自检：缺 description、标题过长、
     * 缺 canonical 等都会在这里暴露出来。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function analyze(string $html): array
    {
        $data   = $this->extract($html);
        $issues = [];
        $og     = isset($data['og']) && is_array($data['og']) ? $data['og'] : [];
        $tw     = isset($data['twitter']) && is_array($data['twitter']) ? $data['twitter'] : [];

        if ($data['title'] === '') {
            $issues[] = ['level' => 'error', 'message' => '缺少 <title>。'];
        } elseif (mb_strlen($data['title']) > 65) {
            $issues[] = [
                'level'  => 'warning',
                'message' => sprintf('title 长 %d 字符，超过 65 会被搜索结果截断。', mb_strlen($data['title'])),
            ];
        }

        if ($data['description'] === '') {
            $issues[] = ['level' => 'error', 'message' => '缺少 meta description。'];
        } elseif (mb_strlen($data['description']) > 160) {
            $issues[] = [
                'level'  => 'warning',
                'message' => sprintf('description 长 %d 字符，超过 160 会被截断。', mb_strlen($data['description'])),
            ];
        }

        if ($data['canonical'] === '') {
            $issues[] = ['level' => 'notice', 'message' => '缺少 canonical，可能产生重复内容。'];
        }

        if (!isset($og['og:title']) && !isset($og['og:description'])) {
            $issues[] = ['level' => 'notice', 'message' => '缺少 Open Graph，分享到社交平台时无摘要。'];
        }

        if (!isset($og['og:image'])) {
            $issues[] = ['level' => 'warning', 'message' => '缺少 og:image，社交分享无封面图。'];
        }

        if (!isset($tw['twitter:card'])) {
            $issues[] = ['level' => 'notice', 'message' => '缺少 twitter:card，默认摘要卡片展示效果差。'];
        }

        if ($data['json_ld_count'] === 0) {
            $issues[] = ['level' => 'notice', 'message' => '页面无 JSON-LD 结构化数据，无法生成富媒体结果。'];
        }

        return [
            'data'        => $data,
            'issues'      => $issues,
            'issue_count' => count($issues),
            'score'       => $this->qualityScore($issues),
        ];
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 收集全部 <meta> 标签。
     *
     * @param string $html 页面 HTML。
     * @return array<string,string> name/property => content
     */
    protected function collectMetas(string $html): array
    {
        $out = [];
        if (preg_match_all('#<meta\b([^>]*)/?>#i', $html, $matches) === false) {
            return $out;
        }

        foreach ($matches[1] as $raw) {
            $attrs = $this->parseAttributes($raw);
            $name  = $attrs['name'] ?? $attrs['property'] ?? $attrs['http-equiv'] ?? '';
            if ($name === '') {
                continue;
            }
            $name = strtolower(trim($name));
            $content = trim($attrs['content'] ?? '');

            // 保留第一个非空值
            if ($content !== '' && !isset($out[$name])) {
                $out[$name] = $content;
            }
        }

        return $out;
    }

    /**
     * 收集全部 <link> 标签。
     *
     * @param string $html 页面 HTML。
     * @return array<int,array<string,string>> 每项为 rel + 目标
     */
    protected function collectLinks(string $html): array
    {
        $out = [];
        if (preg_match_all('#<link\b([^>]*)/?>#i', $html, $matches) === false) {
            return $out;
        }

        foreach ($matches[1] as $raw) {
            $attrs = $this->parseAttributes($raw);
            $href  = trim($attrs['href'] ?? '');
            if ($href === '') {
                continue;
            }
            $out[] = [
                'rel'      => strtolower(trim($attrs['rel'] ?? '')),
                'href'     => $href,
                'hreflang' => trim($attrs['hreflang'] ?? ''),
                'type'     => strtolower(trim($attrs['type'] ?? '')),
                'title'    => trim($attrs['title'] ?? ''),
                'sizes'    => trim($attrs['sizes'] ?? ''),
                'media'    => trim($attrs['media'] ?? ''),
            ];
        }

        return $out;
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
            $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attrs;
    }

    /**
     * 取第一个匹配的 content。
     *
     * @param array<string,string> $metas 元数据表。
     * @param array<int,string>    $names 候选名称。
     */
    protected function firstContent(array $metas, array $names, string $default = ''): string
    {
        foreach ($names as $name) {
            if (isset($metas[$name]) && trim($metas[$name]) !== '') {
                return trim($metas[$name]);
            }
        }

        return $default;
    }

    /**
     * 提取 <title>。
     */
    protected function extractTitleTag(string $html): string
    {
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) !== 1) {
            return '';
        }

        return $this->cleanText($m[1]);
    }

    /**
     * 提取 keywords（拆分为数组）。
     *
     * @param array<string,string> $metas 元数据表。
     * @return array<int,string>
     */
    protected function extractKeywords(array $metas): array
    {
        $raw = $this->firstContent($metas, ['keywords', 'news_keywords']);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[,;，；]/u', $raw) ?: [] as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '') {
                $out[] = $keyword;
            }
        }

        return $out;
    }

    /**
     * 提取 generator。
     */
    protected function extractGenerator(string $html): string
    {
        if (preg_match('#<meta\b[^>]*name=["\']generator["\'][^>]*>#i', $html, $m) === 1) {
            $attrs = $this->parseAttributes($m[0]);

            return trim($attrs['content'] ?? '');
        }

        return '';
    }

    /**
     * 提取字符集。
     *
     * @param array<string,string> $metas 元数据表。
     */
    protected function extractCharset(string $html, array $metas): string
    {
        if (preg_match('#<meta\b[^>]*charset=["\']?([a-zA-Z0-9_\-]+)#i', $html, $m) === 1) {
            return strtolower(trim($m[1]));
        }

        return '';
    }

    /**
     * 提取页面语言。
     */
    protected function extractLang(string $html): string
    {
        if (preg_match('#<html\b[^>]*\blang=["\']([a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*)["\']#i', $html, $m) === 1) {
            return strtolower(trim($m[1]));
        }

        return '';
    }

    /**
     * 提取 favicon。
     *
     * @param array<int,array<string,string>> $links link 标签列表。
     */
    protected function extractFavicon(array $links): string
    {
        foreach ($links as $link) {
            if ($link['rel'] === 'icon' || $link['rel'] === 'shortcut icon') {
                return $link['href'];
            }
        }

        return '';
    }

    /**
     * 提取 canonical。
     *
     * @param array<int,array<string,string>> $links link 标签列表。
     */
    protected function extractCanonical(array $links): string
    {
        foreach ($links as $link) {
            if ($link['rel'] === 'canonical') {
                return $link['href'];
            }
        }

        return '';
    }

    /**
     * 提取 hreflang 标注。
     *
     * @param array<int,array<string,string>> $links link 标签列表。
     * @return array<string,string>
     */
    protected function extractHreflang(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if ($link['hreflang'] === '' || $link['rel'] !== 'alternate') {
                continue;
            }
            // x-default 单独保留
            $out[strtolower($link['hreflang'])] = $link['href'];
        }

        return $out;
    }

    /**
     * 提取 alternate 链接（RSS / 分页等）。
     *
     * @param array<int,array<string,string>> $links link 标签列表。
     * @return array<int,array<string,string>>
     */
    protected function extractAlternate(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if ($link['rel'] === 'alternate' && $link['hreflang'] === '') {
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * 提取指定前缀的元数据。
     *
     * @param array<string,string> $metas  元数据表。
     * @param string               $prefix 前缀，如 'og:'。
     * @return array<string,string>
     */
    protected function extractPrefixed(array $metas, string $prefix): array
    {
        $out = [];
        foreach ($metas as $name => $content) {
            if (strpos($name, $prefix) === 0) {
                $out[$name] = $content;
            }
        }

        return $out;
    }

    /**
     * 提取 article:* 元数据。
     *
     * @param array<string,string> $metas 元数据表。
     * @return array<string,string>
     */
    protected function extractArticle(array $metas): array
    {
        $out = [];
        foreach ($metas as $name => $content) {
            if (strpos($name, 'article:') === 0) {
                $out[$name] = $content;
            }
        }

        return $out;
    }

    /**
     * 提取 JSON-LD 结构化数据。
     *
     * 解码失败或非对象结果会被跳过，不抛异常。
     *
     * @param string $html 页面 HTML。
     * @return array<int,array<string,mixed>>
     */
    protected function extractJsonLd(string $html): array
    {
        $out = [];
        if (preg_match_all(
            '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        ) === false) {
            return $out;
        }

        foreach ($matches[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * 提取页面图片（og:image + picture/img 标签）。
     *
     * @param array<string,string> $metas 元数据表。
     * @param string               $html  页面 HTML。
     * @return array<int,string>
     */
    protected function extractImages(array $metas, string $html): array
    {
        $out = [];

        foreach (['og:image', 'twitter:image', 'twitter:image:src'] as $name) {
            if (isset($metas[$name]) && trim($metas[$name]) !== '') {
                $out[] = trim($metas[$name]);
            }
        }

        if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $matches) !== false) {
            foreach ($matches[0] as $raw) {
                $attrs = $this->parseAttributes($raw);
                $src   = trim($attrs['src'] ?? '');
                if ($src !== '' && stripos($src, 'data:') !== 0) {
                    $out[] = $src;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 依据问题列表计算质量分。
     *
     * @param array<int,array{level:string,message:string}> $issues 问题列表。
     */
    protected function qualityScore(array $issues): int
    {
        $score = 100;
        $penalty = ['error' => 20, 'warning' => 8, 'notice' => 3];
        foreach ($issues as $issue) {
            $score -= $penalty[$issue['level']] ?? 0;
        }

        return (int) max(0, min(100, $score));
    }

    /**
     * 文本清洗：去标签、解实体、压缩空白。
     *
     * 顺序很关键：必须**先去标签再解实体**。
     * 反过来做的话，`&lt;C&gt;` 会先被解成 `<C>`，随后被 strip_tags 当成
     * 真实标签删掉，导致标题里的比较文本凭空消失。
     */
    protected function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * 空结果骨架。
     *
     * @return array<string,mixed>
     */
    protected function emptyResult(): array
    {
        return [
            'title'         => '',
            'description'   => '',
            'keywords'      => [],
            'author'        => '',
            'robots'        => '',
            'generator'     => '',
            'charset'       => '',
            'viewport'      => '',
            'lang'          => '',
            'favicon'       => '',
            'canonical'     => '',
            'hreflang'      => [],
            'alternate'     => [],
            'og'            => [],
            'twitter'       => [],
            'article'       => [],
            'json_ld'       => [],
            'json_ld_count' => 0,
            'images'        => [],
            'site_host'     => $this->siteHost,
        ];
    }
}
