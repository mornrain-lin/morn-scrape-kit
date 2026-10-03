<?php
/**
 * RSS / Atom 解析器。
 *
 * 支持格式：
 * - RSS 2.0（`rss/channel/item`）
 * - Atom 1.0（`feed/entry`）
 * - RSS 1.0 / RDF（`rdf:RDF/item`，常见于旧式站点）
 *
 * 实现方式：使用 XMLReader 流式解析，恒定内存占用 ——
 * 即使是 10 MB、5000 条的订阅文件也不会撑爆内存。
 * 这是相对 SimpleXML（整树载入）最实质的优势。
 *
 * 安全：禁用外部实体加载（XXE 防护），并主动拒绝带 DTD 的输入。
 *
 * @package MornRain\ScrapeKit
 */

declare(strict_types=1);

namespace MornRain\ScrapeKit;

use RuntimeException;
use XMLReader;

/**
 * 订阅源解析器。
 */
class RssParser
{
    /** @var int 条目数量上限，防止恶意订阅源返回海量数据 */
    protected $maxItems = 500;

    /** @var bool 是否包含条目正文（false 时只取摘要，省内存） */
    protected $withContent = true;

    /** @var array<string,string> 订阅源级元数据 */
    protected $feedMeta = [];

    /** @var array<int,FeedItem> 解析结果 */
    protected $items = [];

    /** @var bool|null libxml 内部错误模式的原值，用于成对还原 */
    protected $libxmlPrevious = null;

    /**
     * 构造函数。
     */
    public function __construct()
    {
        if (!class_exists(XMLReader::class)) {
            throw new RuntimeException('RssParser 需要 PHP XMLReader 扩展（php-xml / ext-xmlreader）。');
        }
    }

    /**
     * 限制返回的条目数量。
     *
     * @param int $max 最大条数，0 表示不限制。
     */
    public function maxItems(int $max): self
    {
        $this->maxItems = max(0, $max);

        return $this;
    }

    /**
     * 是否解析条目正文。
     */
    public function withContent(bool $enabled = true): self
    {
        $this->withContent = $enabled;

        return $this;
    }

    /**
     * 解析订阅源。
     *
     * @param string $xml XML 文本。
     * @return array{items:array<int,FeedItem>,meta:array<string,string>,format:string}
     * @throws RuntimeException XML 不合法。
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            return ['items' => [], 'meta' => [], 'format' => 'unknown'];
        }

        $this->rejectDoctype($xml);

        $reader = $this->createReader($xml);
        $this->items   = [];
        $this->feedMeta = [];

        try {
            // 单次遍历完成格式探测与内容读取：
            // detectFormat() 会把游标读到文档末尾，不能与 readFeed() 串行使用。
            $this->readFeed($reader);
        } finally {
            $reader->close();
            // 必须还原：createReader() 会把 libxml 切到内部错误模式，
            // 若不还原，会污染同一进程内后续所有 libxml 调用。
            $this->restoreLibxml();
        }

        return [
            'items'  => $this->items,
            'meta'   => $this->feedMeta,
            'format' => $this->feedMeta['_format'] ?? 'unknown',
        ];
    }

    /**
     * 还原 libxml 的全局状态。
     *
     * libxml_use_internal_errors() 影响整个进程，必须成对调用，
     * 否则本库的解析行为会泄漏到调用方的其他 libxml 使用中。
     */
    protected function restoreLibxml(): void
    {
        if ($this->libxmlPrevious !== null) {
            libxml_use_internal_errors($this->libxmlPrevious);
            $this->libxmlPrevious = null;
        }
        libxml_clear_errors();
    }

    /**
     * 解析并只返回条目列表。
     *
     * @param string $xml XML 文本。
     * @return array<int,FeedItem>
     */
    public function items(string $xml): array
    {
        return $this->parse($xml)['items'];
    }

    /**
     * 解析并返回订阅源元数据。
     *
     * @param string $xml XML 文本。
     * @return array<string,string>
     */
    public function meta(string $xml): array
    {
        return $this->parse($xml)['meta'];
    }

    /**
     * 探测订阅源格式（独立工具方法，不影响 parse() 的游标）。
     *
     * parse() 内部在单次遍历中顺带完成探测，此方法供调用方
     * 在只关心格式、不需要解析内容的场景使用。
     *
     * @param string $xml XML 文本。
     * @return string rss / atom / rdf / unknown
     */
    public function detectFormat(string $xml): string
    {
        if (trim($xml) === '') {
            return 'unknown';
        }

        $this->libxmlPrevious = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->restoreLibxml();

            return 'unknown';
        }

        $format = 'unknown';
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                $name = strtolower($reader->localName);
                if ($name === 'rss') {
                    $format = 'rss';
                    break;
                }
                if ($name === 'feed') {
                    $format = 'atom';
                    break;
                }
                if ($name === 'rdf') {
                    $format = 'rdf';
                    break;
                }
            }
        } finally {
            $reader->close();
            $this->restoreLibxml();
        }

        return $format;
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 拒绝含 DTD 的输入（XXE 防护第一道关卡）。
     */
    protected function rejectDoctype(string $xml): void
    {
        if (preg_match('/<!DOCTYPE/i', $xml, $m, PREG_OFFSET_CAPTURE) === 1) {
            // 定位到 DOCTYPE 所在行，便于报错
            $before  = substr($xml, 0, (int) $m[0][1]);
            $line    = substr_count($before, "\n") + 1;
            throw new RuntimeException('订阅源包含 DOCTYPE 声明（第 ' . $line . ' 行），出于 XXE 防护已拒绝解析。');
        }
    }

    /**
     * 创建并初始化 XMLReader。
     */
    protected function createReader(string $xml): XMLReader
    {
        $this->libxmlPrevious = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader();
        // 只用 LIBXML_NONET 禁止网络访问。
        // 刻意**不启用** LIBXML_NOENT：它会把实体引用展开成实际内容，
        // 是 XXE 的主要放大器。实体保持未展开状态，输出即为字面量。
        $ok = $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

        if (!$ok) {
            $errors = libxml_get_errors();
            $message = $errors !== [] ? trim($errors[0]->message) : '未知错误';
            $this->restoreLibxml();
            throw new RuntimeException('XML 解析失败：' . $message);
        }

        return $reader;
    }

    /**
     * 读取订阅源内容（单次遍历，同时完成格式探测）。
     *
     * 采用「外层只负责定位，片段独立解析」的两段式结构：
     * 1. 外层用 XMLReader 定位到 <item> / <entry>，调用 readOuterXml() 取出该条目的完整 XML；
     * 2. 片段很小，用独立的 XMLReader 递归解析字段。
     *
     * 这样做的原因：XMLReader 的 read() 会推进游标，一旦在处理过程中
     * 自行调用 read()，外层循环就会错位（表现为丢条目、字段串位）。
     * 分段解析彻底避免了游标管理，且内存占用依然是 O(单条条目)。
     *
     * 格式在遇到根元素时即可确定（rss / feed / rdf），
     * 因此不需要独立的探测轮次。
     */
    protected function readFeed(XMLReader $reader): void
    {
        $format   = 'unknown';
        $itemTags = ['item', 'entry'];
        // RDF 的 channel 元数据与 item 同级，采集全程；
        // RSS / Atom 的元数据在首个条目之前，采到首条即停。
        $metaDone = false;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $name = strtolower($reader->localName);

            // 根元素决定格式（rss / feed / rdf）
            if ($format === 'unknown') {
                if ($name !== 'rss' && $name !== 'feed' && $name !== 'rdf') {
                    continue;
                }
                $format     = $name === 'feed' ? 'atom' : ($name === 'rdf' ? 'rdf' : 'rss');
                $itemTags   = $format === 'atom' ? ['entry'] : ['item'];
                $this->feedMeta['_format'] = $format;
            }

            if (in_array($name, $itemTags, true)) {
                $itemXml = $reader->readOuterXml();
                if (is_string($itemXml) && $itemXml !== '') {
                    $item = $this->parseItemFragment($itemXml, $format);
                    if ($item !== null) {
                        $this->pushItem($item);
                    }
                }
                if ($format !== 'rdf') {
                    $metaDone = true;
                }
                if ($this->maxItems > 0 && count($this->items) >= $this->maxItems) {
                    return;
                }
                continue;
            }

            if (!$metaDone) {
                $this->readFeedMeta($reader, $name);
            }
        }
    }

    /**
     * 解析单个条目的 XML 片段。
     *
     * @param string $xml    条目 XML。
     * @param string $format 订阅源格式。
     * @return FeedItem|null 无有效内容的条目返回 null。
     */
    protected function parseItemFragment(string $xml, string $format): ?FeedItem
    {
        $this->libxmlPrevious = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->restoreLibxml();

            return null;
        }

        $item  = new FeedItem();
        $links = [];

        try {
            // 先跳到片段根元素（<item> / <entry>）之后，只处理其子元素，
            // 否则根元素会被当成普通字段把整条内容吞掉。
            if (!$reader->read()) {
                return null;
            }

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = strtolower($reader->localName);
                $ns   = $reader->namespaceURI ?? '';

                // Atom 的 <author><name>…</name><email>…</email></author>
                // 与 <category term="…" label="…"/> 需要按属性 / 子元素取值。
                // RSS 的 <author> 是纯文本，不能走这里。
                $isAtom = $format === 'atom';

                if ($isAtom && ($name === 'author' || $name === 'contributor')) {
                    $this->readAuthorBlock($reader, $item, $name);
                    continue;
                }

                if ($isAtom && $name === 'category') {
                    $term  = trim((string) $reader->getAttribute('term'));
                    $label = trim((string) $reader->getAttribute('label'));
                    $value = $term !== '' ? $term : $label;
                    if ($value !== '') {
                        $item->addCategory($value);
                    }
                    if (!$reader->isEmptyElement) {
                        $text = trim($this->readElementText($reader));
                        if ($text !== '') {
                            $item->addCategory($text);
                        }
                    }
                    continue;
                }

                // 附件：<enclosure url= type= length=>
                if ($name === 'enclosure') {
                    $url = (string) $reader->getAttribute('url');
                    if ($url !== '') {
                        $item->addEnclosure(
                            $url,
                            (string) $reader->getAttribute('type'),
                            (int) $reader->getAttribute('length')
                        );
                    }
                    continue;
                }

                // Atom 的 <link href= rel=>，需要读属性
                if ($name === 'link' && $reader->isEmptyElement) {
                    $href = (string) $reader->getAttribute('href');
                    $rel  = strtolower((string) $reader->getAttribute('rel'));
                    $type = strtolower((string) $reader->getAttribute('type'));
                    if ($href !== '') {
                        $links[] = ['href' => $href, 'rel' => $rel, 'type' => $type];
                    }
                    continue;
                }

                // 容器元素：link 的子元素（如 <source>）、content 的 div 等，交由子元素处理
                if ($reader->isEmptyElement) {
                    continue;
                }

                $value = $this->readElementText($reader);

                // content:encoded 走命名空间，localName 仍是 encoded
                if ($name === 'encoded' || ($name === 'content' && strpos($ns, 'purl.org/rss/1.0/modules/content/') !== false)) {
                    if ($this->withContent) {
                        $item->setContent($value);
                    }
                    continue;
                }

                // dc:creator
                if ($name === 'creator' || strpos($ns, 'purl.org/dc/') !== false) {
                    $item->setAuthor($value);
                    continue;
                }

                $this->handleValue($item, $format, $name, $value);
            }
        } finally {
            $reader->close();
            $this->restoreLibxml();
        }

        // Atom 的 alternate link 作为条目链接
        if ($item->link() === '') {
            foreach ($links as $link) {
                if ($link['rel'] === '' || $link['rel'] === 'alternate') {
                    $item->setLink($link['href']);
                    break;
                }
            }
        }

        // 发布时间的兜底
        if ($item->publishedAt() === 0 && $item->updatedAt() > 0) {
            $item->setPublishedAt($item->updatedAt());
        }

        $title = $this->feedMeta['title'] ?? '';
        if ($title !== '') {
            $item->setFeedTitle($title);
        }

        return $item;
    }

    /**
     * 读取 Atom 的 <author> / <contributor> 块。
     *
     * 结构为 <author><name>…</name><email>…</email><uri>…</uri></author>，
     * 必须逐个子元素取值，否则 name 与 email 会被拼成一串。
     *
     * @param FeedItem $item   当前条目。
     * @param string   $block  外层元素名。
     */
    protected function readAuthorBlock(XMLReader $reader, FeedItem $item, string $block = 'author'): void
    {
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth <= $depth) {
                return;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $child = strtolower($reader->localName);
            if ($child === $block) {
                // 嵌套的同名元素（极少见的 contributor 套 author）交给上层
                return;
            }
            if ($reader->isEmptyElement) {
                continue;
            }

            $value = trim($this->readElementText($reader));
            if ($value === '') {
                continue;
            }

            switch ($child) {
                case 'name':
                    $item->setAuthor($value);
                    break;
                case 'email':
                    $item->setAuthorEmail($value);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * 读取当前元素并返回其纯文本内容（含 CDATA）。
     *
     * 递归下降以正确处理嵌套结构（如 Atom 的 <content type="xhtml"><div>…</div></content>）。
     *
     * @return string
     */
    protected function readElementText(XMLReader $reader): string
    {
        $text    = '';
        $depth   = $reader->depth;
        $hasText = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                $text .= (string) $reader->value;
                $hasText = true;
                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                // 回到本元素层级或其之上即结束
                if ($reader->depth <= $depth) {
                    return $text;
                }
                continue;
            }
        }

        return $hasText ? $text : '';
    }

    /**
     * 采集订阅源级元数据。
     *
     * 内部使用 readElementText() 一次性消费整个元素，
     * 保证外层循环的游标始终停在元素起始处之后一位。
     *
     * @param string $name 元素名（小写）。
     */
    protected function readFeedMeta(XMLReader $reader, string $name): void
    {
        $map = [
            'title'          => 'title',
            'description'    => 'description',
            'subtitle'       => 'subtitle',
            'language'       => 'language',
            'updated'        => 'updated',
            'pubdate'        => 'pub_date',
            'lastbuilddate'  => 'last_build_date',
            'copyright'      => 'copyright',
            'managingeditor' => 'managing_editor',
            'webmaster'      => 'webmaster',
            'generator'      => 'generator',
            'id'             => 'id',
        ];

        // link 需要区分 RSS（文本）与 Atom（href 属性）
        if ($name === 'link') {
            $href = (string) $reader->getAttribute('href');
            if ($href !== '') {
                $rel = strtolower((string) $reader->getAttribute('rel'));
                if (($rel === '' || $rel === 'alternate') && !isset($this->feedMeta['link'])) {
                    $this->feedMeta['link'] = $href;
                }

                return;
            }

            $value = trim($this->readElementText($reader));
            if ($value !== '' && !isset($this->feedMeta['link'])) {
                $this->feedMeta['link'] = $value;
            }

            return;
        }

        if (!isset($map[$name]) || $reader->isEmptyElement) {
            return;
        }

        $value = trim($this->readElementText($reader));
        if ($value !== '' && !isset($this->feedMeta[$map[$name]])) {
            $this->feedMeta[$map[$name]] = $value;
        }
    }

    /**
     * 把读到的字段值写入条目。
     *
     * @param FeedItem $item   当前条目。
     * @param string   $format 格式。
     * @param string   $field  字段名（小写）。
     * @param string   $text   字段值。
     */
    protected function handleValue(FeedItem $item, string $format, string $field, string $text): void
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 空值：description / summary / content 例外（允许空节点）
        if ($text === '' && !in_array($field, ['description', 'summary', 'content'], true)) {
            return;
        }

        switch ($field) {
            // 通用
            case 'title':
                $item->setTitle($text);
                break;
            case 'guid':
            case 'id':
                $item->setGuid($text);
                break;
            case 'link':
                // RSS 的 link 是文本节点；Atom 的 link 是 href 属性（在片段解析里已处理）
                if ($format !== 'atom') {
                    $item->setLink($text);
                }
                break;

            // RSS
            case 'description':
                $item->setSummary($text);
                if ($this->withContent && $item->content() === '') {
                    $item->setContent($text);
                }
                break;
            case 'pubdate':
                $item->setPublishedAt($text);
                break;
            case 'author':
                // RSS 两种常见写法：
                //   1) 纯邮箱：linmo@example.com
                //   2) 邮箱 + 括号姓名：linmo@example.com (林墨)
                if (preg_match('/^([^@\s<>()]+)@([^@\s<>()]+)\s*\((.+)\)$/u', $text, $m) === 1) {
                    $item->setAuthorEmail($m[1] . '@' . $m[2]);
                    $item->setAuthor(trim($m[3]));
                } elseif (preg_match('/^[^@\s<>()]+@[^@\s<>()]+$/', $text) === 1) {
                    $item->setAuthorEmail($text);
                } else {
                    $item->setAuthor($text);
                }
                break;
            case 'comments':
                $item->setLink($text);
                break;
            case 'category':
                $item->addCategory($text);
                break;

            // Atom
            case 'summary':
                $item->setSummary($text);
                if ($this->withContent && $item->content() === '') {
                    $item->setContent($text);
                }
                break;
            case 'content':
                if ($this->withContent) {
                    $item->setContent($text);
                }
                break;
            case 'updated':
                $item->setUpdatedAt($text);
                if ($item->publishedAt() === 0) {
                    $item->setPublishedAt($text);
                }
                break;
            case 'published':
                $item->setPublishedAt($text);
                break;

            default:
                break;
        }
    }

    /**
     * 推入结果列表 respecting 上限。
     */
    protected function pushItem(FeedItem $item): void
    {
        if ($this->maxItems > 0 && count($this->items) >= $this->maxItems) {
            return;
        }
        // 无标题且无链接的条目没有意义
        if ($item->title() === '' && $item->link() === '') {
            return;
        }

        $this->items[] = $item;
    }
}
