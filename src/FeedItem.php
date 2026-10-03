<?php
/**
 * 订阅源条目模型。
 *
 * 统一 RSS 2.0、Atom 1.0、RSS 1.0（RDF）三种格式的解析结果，
 * 让调用方不必关心源的类型差异。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

use InvalidArgumentException;

/**
 * 订阅源条目。
 */
class FeedItem
{
    /** @var string 条目标题 */
    protected $title = '';

    /** @var string 正文或摘要 */
    protected $content = '';

    /** @var string 纯文本摘要 */
    protected $summary = '';

    /** @var string 原文链接 */
    protected $link = '';

    /** @var string 唯一 ID */
    protected $guid = '';

    /** @var int 发布时间戳 */
    protected $publishedAt = 0;

    /** @var int 更新时间戳 */
    protected $updatedAt = 0;

    /** @var string 作者名 */
    protected $author = '';

    /** @var string 作者邮箱 */
    protected $authorEmail = '';

    /** @var array<int,string> 分类标签 */
    protected $categories = [];

    /** @var array<int,string> 附件 URL */
    protected $enclosures = [];

    /** @var array<string,array{type:string,length:int}> 附件元信息，按 URL 索引 */
    protected $enclosureMeta = [];

    /** @var string 内容类型：text / html / xhtml */
    protected $contentType = 'html';

    /** @var string 语言标记 */
    protected $language = '';

    /** @var int 评论数 */
    protected $commentCount = 0;

    /** @var string 源名称 */
    protected $feedTitle = '';

    /**
     * 构造函数。
     *
     * @param array<string,mixed> $data 初始字段。
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            // 只允许调用本类真实声明的 setter，避免传入如
            // ['class' => ...] 时拼出任意方法名。
            $setter = 'set' . ucfirst((string) $key);
            if (method_exists(self::class, $setter) && strpos($setter, 'set') === 0) {
                $method = new \ReflectionMethod(self::class, $setter);
                if ($method->isPublic() && !$method->isStatic()) {
                    $this->$setter($value);
                }
            }
        }
    }

    /* ================================================================
     *  读写方法
     * ================================================================ */

    /**
     * 取得标题。
     *
     * 源未提供 title 时，从正文截取前 80 字兜底，
     * 保证调用方拿到的标题永远可展示。
     */
    public function title(): string
    {
        if ($this->title !== '') {
            return $this->title;
        }
        if ($this->content !== '') {
            return mb_substr(trim(strip_tags($this->content)), 0, 80);
        }

        return '';
    }

    public function setTitle(string $title): self
    {
        $this->title = $this->cleanText($title);

        return $this;
    }

    /**
     * 取得正文（HTML）。
     */
    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * 取得源提供的纯文本摘要。
     */
    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * 取得纯文本摘要（必要时从正文生成）。
     */
    public function plainSummary(int $length = 200): string
    {
        if ($this->summary !== '') {
            return mb_strlen($this->summary) > $length
                ? mb_substr($this->summary, 0, $length) . '…'
                : $this->summary;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($this->content)) ?? '');

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $this->cleanText($summary);

        return $this;
    }

    /**
     * 原文链接。
     */
    public function link(string $link = ''): string
    {
        return $link !== '' ? $link : $this->link;
    }

    public function setLink(string $link): self
    {
        $this->link = trim($link);

        return $this;
    }

    /**
     * 唯一 ID（缺失时回退到链接）。
     */
    public function guid(): string
    {
        return $this->guid !== '' ? $this->guid : $this->link;
    }

    public function setGuid(string $guid): self
    {
        $this->guid = trim($guid);

        return $this;
    }

    /**
     * 取得发布时间戳。
     */
    public function publishedAt(): int
    {
        return $this->publishedAt;
    }

    public function setPublishedAt($at): self
    {
        $this->publishedAt = $this->toTimestamp($at);

        return $this;
    }

    /**
     * 取得更新时间戳。
     */
    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt($at): self
    {
        $this->updatedAt = $this->toTimestamp($at);

        return $this;
    }

    /**
     * 发布时间（ISO 8601 格式）。
     */
    public function publishedIso(): string
    {
        return $this->publishedAt > 0 ? date('c', $this->publishedAt) : '';
    }

    /**
     * 取得作者名，缺失时回退到邮箱。
     */
    public function author(): string
    {
        return $this->author !== '' ? $this->author : $this->authorEmail;
    }

    public function setAuthor(string $author): self
    {
        $this->author = $this->cleanText($author);

        return $this;
    }

    public function setAuthorEmail(string $email): self
    {
        $this->authorEmail = trim($email);

        return $this;
    }

    public function authorEmail(): string
    {
        return $this->authorEmail;
    }

    /**
     * 取得分类标签。
     *
     * @return array<int,string>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * 覆盖设置分类标签。
     *
     * @param array<int,string> $categories 标签列表。
     */
    public function setCategories(array $categories): self
    {
        $this->categories = [];
        foreach ($categories as $category) {
            $this->addCategory((string) $category);
        }

        return $this;
    }

    /**
     * 追加分类。
     */
    public function addCategory(string $category): self
    {
        $category = $this->cleanText($category);
        if ($category !== '' && !in_array($category, $this->categories, true)) {
            $this->categories[] = $category;
        }

        return $this;
    }

    /**
     * 附件。
     *
     * @return array<int,string>
     */
    public function enclosures(): array
    {
        return $this->enclosures;
    }

    /**
     * 追加附件 URL。
     */
    public function addEnclosure(string $url, string $type = '', int $length = 0): self
    {
        $url = trim($url);
        if ($url === '') {
            return $this;
        }

        $this->enclosures[] = $url;
        $this->enclosureMeta[$url] = ['type' => $type, 'length' => $length];

        return $this;
    }

    /**
     * 取得附件元信息。
     *
     * @return array<string,array{type:string,length:int}>
     */
    public function enclosureMeta(): array
    {
        return $this->enclosureMeta;
    }

    /**
     * 内容类型。
     */
    public function contentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $type): self
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['text', 'html', 'xhtml'], true)) {
            $this->contentType = $type;
        }

        return $this;
    }

    /**
     * 语言标记。
     */
    public function language(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = trim($language);

        return $this;
    }

    /**
     * 评论数。
     */
    public function commentCount(): int
    {
        return $this->commentCount;
    }

    public function setCommentCount($count): self
    {
        $this->commentCount = is_numeric($count) ? (int) $count : 0;

        return $this;
    }

    /**
     * 所属源名称。
     */
    public function feedTitle(): string
    {
        return $this->feedTitle;
    }

    public function setFeedTitle(string $title): self
    {
        $this->feedTitle = $this->cleanText($title);

        return $this;
    }

    /* ================================================================
     *  输出
     * ================================================================ */

    /**
     * 转为数组。
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'title'         => $this->title,
            'content'       => $this->content,
            'summary'       => $this->summary,
            'link'          => $this->link,
            'guid'          => $this->guid(),
            'published_at'  => $this->publishedAt,
            'published_iso' => $this->publishedIso(),
            'updated_at'    => $this->updatedAt,
            'author'        => $this->author(),
            'author_email'  => $this->authorEmail,
            'categories'    => $this->categories,
            'enclosures'    => $this->enclosures,
            'content_type'  => $this->contentType,
            'language'      => $this->language,
            'comment_count' => $this->commentCount,
            'feed_title'    => $this->feedTitle,
        ];
    }

    /**
     * 人类可读摘要。
     */
    public function describe(): string
    {
        $parts = [$this->title()];
        if ($this->author() !== '') {
            $parts[] = '作者：' . $this->author();
        }
        if ($this->publishedIso() !== '') {
            $parts[] = '发布：' . $this->publishedIso();
        }
        if ($this->link !== '') {
            $parts[] = '链接：' . $this->link;
        }
        $summary = $this->plainSummary(80);
        if ($summary !== '') {
            $parts[] = '摘要：' . $summary;
        }

        return implode(' | ', $parts);
    }

    /* ================================================================
     *  内部工具
     * ================================================================ */

    /**
     * 去除 HTML 标签与多余空白。
     */
    protected function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * 归一时间为时间戳。
     *
     * @param mixed $value 时间戳或日期字符串。
     */
    protected function toTimestamp($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $value = trim($value);
        // 已是时间戳
        if (preg_match('/^\d{9,11}$/', $value) === 1) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }
}
