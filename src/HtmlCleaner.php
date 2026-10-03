<?php
/**
 * HTML 正文清洗器。
 *
 * 用于把外部 HTML 收敛为「可安全再发布」的子集：
 * - 标签白名单：只保留排版类标签，丢弃 script / style / iframe / form 等；
 * - 属性白名单：剥离所有事件属性（on*）与 style 中的表达式；
 * - URL 白名单：仅允许 http / https / mailto / tel 与站内相对路径；
 * - 可选：清除内联样式与图片，实现「极简模式」；
 * - 自动补全：把常见的排版结构（居中、缩进）映射到白名单标签。
 *
 * 设计取向：**宁可少留，不可留错**。任何判定不了的构造一律丢弃。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

/**
 * HTML 清洗器。
 */
class HtmlCleaner
{
    /** @var array<int,string> 允许保留的标签 */
    protected $allowedTags = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'cite', 'code', 'col', 'colgroup',
        'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p',
        'pre', 'q', 's', 'samp', 'section', 'small', 'span', 'strong', 'sub', 'summary',
        'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'wbr',
    ];

    /** @var array<string,array<int,string>> 标签允许的属性 */
    protected $allowedAttributes = [
        'a'          => ['href', 'title', 'rel', 'target'],
        'img'        => ['src', 'alt', 'width', 'height', 'title', 'loading'],
        'td'         => ['colspan', 'rowspan', 'align'],
        'th'         => ['colspan', 'rowspan', 'scope', 'align'],
        'col'        => ['span'],
        'colgroup'   => ['span'],
        'ol'         => ['start', 'type', 'reversed'],
        'time'       => ['datetime'],
        'blockquote' => ['cite'],
        'q'          => ['cite'],
        'del'        => ['cite', 'datetime'],
        'ins'        => ['cite', 'datetime'],
        'details'    => ['open'],
    ];

    /** @var array<int,string> 全局允许的属性 */
    protected $globalAttributes = ['class', 'id', 'dir', 'lang', 'title', 'style'];

    /** @var array<int,string> 允许的 URL 协议 */
    protected $allowedProtocols = ['http', 'https', 'mailto', 'tel'];

    /** @var bool 是否保留内联 style（经安全化处理） */
    protected $keepStyle = false;

    /** @var bool 是否把 style 属性整体丢弃（优先级高于 keepStyle） */
    protected $stripStyle = true;

    /** @var bool 是否移除图片 */
    protected $stripImages = false;

    /** @var bool 是否移除全部链接（只留文字） */
    protected $stripLinks = false;

    /** @var array<int,string> style 中允许的属性 */
    protected $safeStyleProperties = [
        'color', 'background-color', 'font-size', 'font-weight', 'font-style',
        'text-align', 'text-decoration', 'line-height', 'margin', 'margin-top',
        'margin-bottom', 'padding', 'padding-top', 'padding-bottom', 'width',
        'max-width', 'height', 'border', 'border-radius', 'display',
    ];

    /** @var int 正文最大长度（字符），0 表示不限制 */
    protected $maxLength = 0;

    /**
     * 保留（安全化的）内联样式。
     */
    public function keepStyle(bool $enabled = true): self
    {
        $this->keepStyle = $enabled;
        if ($enabled) {
            $this->stripStyle = false;
        }

        return $this;
    }

    /**
     * 丢弃全部 style 属性（默认行为）。
     */
    public function stripStyle(bool $enabled = true): self
    {
        $this->stripStyle = $enabled;
        if ($enabled) {
            $this->keepStyle = false;
        }

        return $this;
    }

    /**
     * 移除图片。
     */
    public function stripImages(bool $enabled = true): self
    {
        $this->stripImages = $enabled;

        return $this;
    }

    /**
     * 移除链接（保留锚文本）。
     */
    public function stripLinks(bool $enabled = true): self
    {
        $this->stripLinks = $enabled;

        return $this;
    }

    /**
     * 限制正文最大长度。
     */
    public function maxLength(int $length): self
    {
        $this->maxLength = max(0, $length);

        return $this;
    }

    /**
     * 覆盖标签白名单。
     *
     * @param array<int,string> $tags 标签列表。
     */
    public function allowTags(array $tags): self
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag !== '' && preg_match('/^[a-z][a-z0-9]*$/', $tag) === 1) {
                $clean[] = $tag;
            }
        }
        $this->allowedTags = array_values(array_unique($clean));

        return $this;
    }

    /**
     * 覆盖属性白名单。
     *
     * @param array<string,array<int,string>> $map 标签 => 属性列表。
     */
    public function allowAttributes(array $map): self
    {
        $clean = [];
        foreach ($map as $tag => $attrs) {
            $tag = strtolower(trim((string) $tag));
            if ($tag === '' || !is_array($attrs)) {
                continue;
            }
            $list = [];
            foreach ($attrs as $attr) {
                $attr = strtolower(trim((string) $attr));
                if ($attr !== '' && preg_match('/^[a-z][a-z0-9\-]*$/', $attr) === 1) {
                    $list[] = $attr;
                }
            }
            if ($list !== []) {
                $clean[$tag] = $list;
            }
        }
        $this->allowedAttributes = $clean;

        return $this;
    }

    /**
     * 清洗 HTML。
     *
     * @param string $html 原始 HTML。
     * @return string 清洗后的 HTML
     */
    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = $this->stripDangerousBlocks($html);
        $html = $this->stripComments($html);
        $html = $this->normalize($html);

        $result = preg_replace_callback(
            // 属性值内的引号优先匹配，避免 `class="a>b"` 这类值里的 `>`
            // 被误当作标签结束，从而把后半段内容当作文本泄漏到输出中。
            '#<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(/?)\s*>#s',
            function (array $m): string {
                return $this->renderTag($m[1] === '/', strtolower($m[2]), $m[3], $m[4] === '/');
            },
            $html
        );

        $result = $this->tidy($result ?? '');

        if ($this->maxLength > 0 && mb_strlen($result) > $this->maxLength) {
            $result = mb_substr($result, 0, $this->maxLength);
        }

        return $result;
    }

    /**
     * 清洗为纯文本。
     *
     * @param string $html  原始 HTML。
     * @param int    $keepNewlines 是否保留段落换行。
     */
    public function toText(string $html, bool $keepNewlines = true): string
    {
        $text = $this->clean($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // 块级标签结束后补换行，避免整段糊成一行
        if ($keepNewlines) {
            $text = str_replace(['</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</div>', '</li>', '</tr>', '</blockquote>', '<br>', '<br/>', '<br />'], "\n", $text);
            $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? $text;
            $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        } else {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        }

        // 去除不可见控制字符
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * 清洗并返回摘要。
     *
     * @param string $html   原始 HTML。
     * @param int    $length 摘要长度。
     */
    public function summarize(string $html, int $length = 200): string
    {
        $text = $this->toText($html, false);
        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    /**
     * 提取正文纯文本中的标题层级结构（用于内容大纲）。
     *
     * @param string $html 原始 HTML。
     * @return array<int,array{level:int,text:string}>
     */
    public function outline(string $html): array
    {
        $out  = [];
        $html = $this->stripDangerousBlocks($html);

        if (preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $out[] = ['level' => (int) $match[1], 'text' => $text];
            }
        }

        return $out;
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 移除整块丢弃的元素（含其内容）。
     */
    protected function stripDangerousBlocks(string $html): string
    {
        $blockTags = [
            'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
            'noscript', 'template', 'svg', 'math', 'form', 'button', 'select', 'option',
            'textarea', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area',
            'link', 'meta', 'base', 'title',
        ];

        foreach ($blockTags as $tag) {
            // 成对标签连内容一起删
            $html = preg_replace('#<\s*' . $tag . '\b[^>]*>.*?<\s*/\s*' . $tag . '\s*>#is', '', $html) ?? $html;
            // 自闭合形式
            $html = preg_replace('#<\s*' . $tag . '\b[^>]*/?>#is', '', $html) ?? $html;
            // 未闭合的起始标签
            $html = preg_replace('#<\s*' . $tag . '\b[^>]*>#is', '', $html) ?? $html;
        }

        return $html;
    }

    /**
     * 移除 HTML 注释与条件注释。
     */
    protected function stripComments(string $html): string
    {
        $html = preg_replace('#<!--(?!\[if).*?-->#s', '', $html) ?? $html;
        // IE 条件注释与 CDATA
        $html = preg_replace('#\[if[^\]]*\]>.*?<!\[endif\]#is', '', $html) ?? $html;
        $html = str_replace(['<![CDATA[', ']]>'], '', $html);

        return $html;
    }

    /**
     * 预归一：把危险属性直接删掉，避免进入属性解析阶段。
     */
    protected function normalize(string $html): string
    {
        // 所有事件属性
        $html = preg_replace('/\son[a-z]{3,20}\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;
        // srcdoc（iframe 的 HTML 注入点）
        $html = preg_replace('/\ssrcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;
        // 危险协议（无论在什么属性里）
        $html = preg_replace(
            '/=\s*("|\')\s*(javascript|vbscript|data)\s*:[^"\']*\1/i',
            '=""',
            $html
        ) ?? $html;
        // 去掉属性值里的控制字符（绕过 "java\tscript:"）。
        // 这里刻意不用带嵌套量词的写法（如 (?!\1).*），避免 ReDoS：
        // 属性值内的控制字符一律把整个属性置空即可，无需匹配到具体位置。
        $html = preg_replace('/=(["\'])[^\"\'>]*[\x00-\x08\x0B\x0C\x0E-\x1F][^\"\'>]*\1/i', '=""', $html) ?? $html;

        return $html;
    }

    /**
     * 渲染单个标签。
     *
     * @param bool   $closing  是否为闭合标签。
     * @param string $tag      标签名（小写）。
     * @param string $rawAttrs 原始属性串。
     * @param bool   $selfEnd  是否自闭合。
     */
    protected function renderTag(bool $closing, string $tag, string $rawAttrs, bool $selfEnd): string
    {
        if (!in_array($tag, $this->allowedTags, true)) {
            return '';
        }

        // 移除链接模式：拆掉 <a> 标签，保留内部文字。
        // 开标签与闭标签都要处理，否则会留下孤立的 </a>。
        if ($this->stripLinks && $tag === 'a') {
            return '';
        }

        if ($closing) {
            return '</' . $tag . '>';
        }

        // 移除图片模式：整段丢弃 <img>，而不是只剥掉 src。
        // 保留空壳 <img alt="x"> 会渲染出破碎占位图，反而更糟。
        if ($this->stripImages && $tag === 'img') {
            return '';
        }

        $attrs = $this->filterAttributes($tag, $rawAttrs);
        if ($attrs === '' && in_array($tag, ['br', 'hr', 'img', 'col', 'wbr'], true)) {
            return '<' . $tag . ($selfEnd ? ' /' : '') . '>';
        }

        return '<' . $tag . $attrs . ($selfEnd ? ' /' : '') . '>';
    }

    /**
     * 过滤属性。
     */
    protected function filterAttributes(string $tag, string $rawAttrs): string
    {
        $allowed = array_merge($this->globalAttributes, $this->allowedAttributes[$tag] ?? []);
        if ($this->stripStyle || !$this->keepStyle) {
            $allowed = array_values(array_diff($allowed, ['style']));
        }

        $out = '';
        if (preg_match_all(
            '#([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*(?:=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#s',
            $rawAttrs,
            $matches,
            PREG_SET_ORDER
        ) === false) {
            return '';
        }

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            // 无条件拒绝：事件属性、样式属性、带 xmlns 的命名空间注入
            if (strpos($name, 'on') === 0 || $name === 'style' && $this->stripStyle) {
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                continue;
            }

            $value = '';
            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[3] ?? '';
                if ($value === '' && isset($match[4])) {
                    $value = $match[4];
                }
                if ($value === '' && isset($match[5])) {
                    $value = $match[5];
                }
            } else {
                // 无值属性：仅允许 details[open]
                if ($tag === 'details' && $name === 'open') {
                    $out .= ' open';
                }
                continue;
            }

            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;

            // 图片与链接的特殊处理
            if ($name === 'src' || $name === 'href' || $name === 'cite') {
                if ($tag === 'img' && $name === 'src' && $this->stripImages) {
                    continue;
                }
                $safe = $this->safeUrl($value);
                if ($safe === '') {
                    continue;
                }
                $value = $safe;
            }

            if ($name === 'target') {
                $value = strtolower($value) === '_blank' ? '_blank' : '';
                if ($value === '') {
                    continue;
                }
                // target=_blank 必须配合 noopener，统一在循环结束后追加，
                // 避免此处与结尾处各追加一次产生重复 rel。
            }

            if ($name === 'style') {
                $value = $this->safeStyle($value);
                if ($value === '') {
                    continue;
                }
            }

            // class / id 过滤：只保留安全的类名，避免 CSS 层面的信息泄露
            if ($name === 'class' || $name === 'id') {
                $value = $this->safeTokenList($value);
                if ($value === '') {
                    continue;
                }
            }

            // 数字属性校验
            if (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span', 'start'], true)) {
                if (preg_match('/^\d{1,5}$/', trim($value)) !== 1) {
                    continue;
                }
            }

            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        // 外链补 noopener
        if (strpos($out, 'target="_blank"') !== false && strpos($out, 'rel=') === false) {
            $out .= ' rel="noopener noreferrer"';
        }

        return $out;
    }

    /**
     * URL 安全化。
     */
    protected function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // 去掉控制字符与内部空白，防 "java\tscript:"
        $url = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? $url;
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $m) !== 1) {
            // 相对路径 / 锚点
            if (preg_match('#^[a-zA-Z0-9/_\-.~%?&=:@+]+(\#[a-zA-Z0-9\-_]+)?$#', $url) === 1) {
                return $url;
            }

            return '';
        }

        $scheme = strtolower($m[1]);
        if (!in_array($scheme, $this->allowedProtocols, true)) {
            return '';
        }

        return $url;
    }

    /**
     * class / id 值过滤：仅保留字母数字与连字符。
     */
    protected function safeTokenList(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $clean  = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[^A-Za-z0-9_\-]/', '', $token) ?? '';
            if ($token !== '' && mb_strlen($token) <= 64) {
                $clean[] = $token;
            }
        }

        return implode(' ', array_slice($clean, 0, 12));
    }

    /**
     * style 值安全化。
     */
    protected function safeStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $declaration) {
            if (strpos($declaration, ':') === false) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $declaration, 2));
            $prop = strtolower($prop);

            if (!in_array($prop, $this->safeStyleProperties, true)) {
                continue;
            }
            // 阻断表达式、url()、注释、括号闭合等注入手法
            if (preg_match('/[<>{}()@;\\\\]|expression|javascript:|url\s*\(|behavior|\/\*|\*\//i', $value) === 1) {
                continue;
            }
            if (mb_strlen($value) > 60) {
                continue;
            }

            $out[] = $prop . ': ' . $value;
        }

        return implode('; ', $out);
    }

    /**
     * 收尾整理：平衡标签、压缩空白。
     */
    protected function tidy(string $html): string
    {
        // 移除空标签对（<p></p> 之类）
        $html = preg_replace('#<(p|div|span|section|details|blockquote)\b[^>]*>\s*</\1>#i', '', $html) ?? $html;
        // 压缩连续空白，但保留 pre / code 内的原样
        $html = preg_replace('/[ \t]{2,}/', ' ', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }
}
