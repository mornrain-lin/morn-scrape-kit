# Changelog

本项目遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)。

## [1.0.1] - 2026-10-03

### Security

- **PHP 7.4 兼容性被破坏**：`LinkExtractor` 使用了 `str_contains()`（PHP 8.0+），
  与本库声明的「PHP 7.4+」矛盾，在 7.4 上会直接致命错误。现改用 `strpos()`。
- **`robots.txt` 规则匹配存在 ReDoS**：`ruleMatches()` 把规则里的 `*` 直接翻译成
  正则的 `.*`。由于 `robots.txt` 来自远端（不可信输入），
  形如 `Disallow: /*a*a*a*a*a*a*a*a*a*a*b` 的规则会在长路径上触发灾难性回溯，
  可被用来打满 CPU。现改为手写的线性时间通配匹配（双指针 + 回退到最近的 `*`），
  并对 18 组用例做了新旧实现的差分验证，确认语义等价。
- **XXE 加固**：移除 `XMLReader` 的 `LIBXML_NOENT` 标志。
  该标志会展开实体引用，是 XXE 的主要放大器；实体保持未展开即可。
  配合既有的 `DOCTYPE` 拒绝逻辑，形成双重防护。
- **HTML 清洗器 ReDoS**：`normalize()` 中使用了 `(?:(?!\1).)*` 这类嵌套量词
  写法，在病态输入下存在指数级回溯风险。现改为不含嵌套量词的等价写法。
- **`target="_blank"` 的 `rel` 属性被追加两次**：一处加在循环内、一处加在循环后，
  产生重复属性。现只在循环后追加一次。

### Fixed

- **libxml 全局状态泄漏**：`libxml_use_internal_errors()` 影响整个进程，
  原实现只在失败分支还原，成功路径会把「内部错误模式」遗留给调用方，
  污染同进程内后续所有 libxml 调用。现统一由 `restoreLibxml()` 成对还原。
- **HTML 属性值中的 `>` 导致内容泄漏**：`class="a>b"` 中的 `>` 会被当作标签结束符，
  使剩余内容作为纯文本泄漏到输出中。现调整标签匹配正则，引号内容优先匹配。
- **`stripImages()` 留下空壳标签**：原本只剥掉 `src`，输出 `<img alt="x">`，
  浏览器会渲染出破碎占位图。现整段丢弃 `<img>`。
- **`keepStyle()` 完全无效**：`style` 不在全局属性白名单里，
  导致即使显式开启也会被 `filterAttributes()` 过滤掉。现已加入白名单。
- **`rel` / `target` 属性被丢弃**：`LinkExtractor` 的正则只捕获 `href` **之前**的属性，
  而 `rel="nofollow"` 通常写在 `href` 之后，导致 nofollow / noopener / ugc
  全部检测不到。现改为捕获完整的属性串。
- **`MetaExtractor` 标题丢失尖括号内容**：`cleanText()` 先解实体再 `strip_tags()`，
  导致 `&lt;C&gt;` 被解成 `<C>` 后当作真实标签删除。现调整为先去标签再解实体。
- **临时文件泄漏**：`RateLimiter::writeState()` 在 `rename()` 失败时不清理临时文件，
  会在状态目录堆积垃圾；且临时文件名仅含 pid，同进程内连续写可能互相覆盖。
  现改为 pid + 随机后缀，并在失败时 `unlink`。
- **`purgeAll()` 在 WordPress 下静默无效**：原实现直接 `return` 且不留痕迹，
  调用方会误以为已清空。现通过 `error_log` 明确告知限制并指向 `reset($url)`。
- **限速抖动使用 `mt_rand`**：多进程共用同一种子序列会让抖动高度同步，
  反而形成新的请求尖峰。现改用 `random_int`。
- `FeedItem` 构造器的动态 setter 派发改为经 `ReflectionMethod` 校验，
  确认为本类声明的 public 非静态方法。

### Added

- `tests/`：128 个用例 / 296 条断言，覆盖元数据提取、RSS/Atom/RDF 解析、
  XXE 拒绝、链接分类与 rel 判定、HTML 清洗与 XSS 防护、
  robots.txt 匹配语义与 ReDoS 防护、限速器行为。
- `tests/run-tests.php`：零依赖测试运行器。
- `phpunit.xml.dist`、`phpcs.xml.dist`（PSR-12）、`CONTRIBUTING.md`、`SECURITY.md`。

## [1.0.0] - 2026-10-02

### 新增

- `MetaExtractor`：HTML 元数据提取
  - 基础 SEO：title / description / keywords / author / robots / generator
  - 页面信息：charset / viewport / lang / favicon
  - `canonical` 与 `hreflang` 多语言标注（含 x-default）
  - Open Graph（`og:*`）、Twitter Card（`twitter:*`）、`article:*` 元数据
  - 页面内全部 JSON-LD 结构化数据
  - 图片清单（og:image + img 标签）
  - `analyze()` 质量体检：标题长度、description 缺失、canonical 缺失、
    OG 缺失、JSON-LD 缺失，输出 0~100 质量分
  - 纯正则实现，不依赖 ext-dom
- `RssParser`：RSS 2.0 / Atom 1.0 / RSS 1.0(RDF) 解析
  - 基于 `XMLReader` 的**两段式流式解析**：外层定位条目并 `readOuterXml()`，
    片段独立递归解析，彻底避免游标错位
  - 内存占用 O(单条条目)，可处理超大订阅源
  - 单次遍历同时完成格式探测与内容读取
  - XXE 防护：主动拒绝含 DOCTYPE 的输入
  - 完整字段：title / link / guid / pubDate / author / category /
    description / content:encoded / enclosure / dc:creator
  - Atom 特殊处理：`link[rel]` 属性、`author/name` 与 `author/email` 分开取、
    `category[term]`、`content type="xhtml"`、转义标题还原
  - RSS 的 `邮箱 (姓名)` 作者格式拆解
  - `maxItems()` 数量上限，防止恶意订阅源
  - `withContent()` 可只取摘要不取正文，省内存
- `FeedItem`：统一条目模型，屏蔽 RSS / Atom 字段差异
  - 缺标题时从正文截取兜底
  - `plainSummary()` 纯文本摘要（自动去标签、压缩空白、截断）
  - `toArray()` / `describe()`
- `LinkExtractor`：链接提取与分析
  - 7 种类型：internal / external / relative / anchor /
    protocol_relative / mailto / tel / dangerous
  - rel 标记解析：nofollow / noopener / noreferrer / ugc / sponsored
  - 用途识别：pagination / prev_next / download / author / comment /
    subscribe / mail / tel / external
  - `stripNonContent()` 剔除导航、侧栏、页脚、评论区
  - `analyze()` 内外链比例评估与改进建议
- `HtmlCleaner`：HTML 白名单清洗
  - 60+ 排版标签白名单，属性逐标签白名单
  - 剥离所有 `on*` 事件属性、`srcdoc`、危险协议、控制字符
  - `style` 属性经属性级白名单过滤，或整体丢弃
  - URL 协议白名单（http / https / mailto / tel）
  - `class` / `id` 令牌过滤
  - `target="_blank"` 自动补 `rel="noopener noreferrer"`
  - `toText()` 纯文本化（块级标签补换行）、`summarize()` 摘要、`outline()` 标题大纲
  - `stripImages()` / `stripLinks()` / `stripStyle()` 极简模式
- `RobotsGuard`：robots.txt 解析与抓取许可判定
  - 遵循 RFC 9309：最长 UA 匹配优先，无匹配回退 `*` 组
  - Allow / Disallow 最长匹配优先，长度相同时 Allow 胜出
  - 支持 `*` 通配与 `$` 行尾锚
  - 多 UA 共用规则组（连续的 user-agent 行）
  - `Crawl-delay` 与 `Sitemap` 提取
  - `isAllowed()` 简判与 `check()` 详细判定（含命中规则说明）
  - 空值 `Disallow:` 正确识别为「允许全部」
- `RateLimiter`：礼貌抓取限速
  - 按主机名分别维护最小请求间隔
  - 全局间隔与每日请求上限
  - `applyRobots()` 自动采用 robots.txt 的 Crawl-delay，
    未声明时回退通配组再到调用方默认值
  - 状态持久化到 Transient（WP）或原子写入文件（纯 PHP），跨请求有效
  - `acquire()` 非阻塞判定、`waitFor()` 阻塞等待（含随机抖动）
  - 等待超时返回建议秒数
- `fixtures/`：`sample-article.html`（含 OG / Twitter / JSON-LD / hreflang /
  危险链接的完整文章页）、`feed.xml`（RSS 2.0）、`feed-atom.xml`（Atom 1.0）
- `examples/extract.php`：10 个场景的可运行示例，含抓取前合规检查流程

### 合规声明

本库是**解析工具箱**，不含任何 HTTP 客户端，不主动发起网络请求。
使用者须自行确保抓取行为符合目标站点的 robots.txt、服务条款与当地法律法规。
库内 `RobotsGuard` 与 `RateLimiter` 的作用是帮助使用者**更容易合规**，
而非规避约束。

[1.0.0]: https://github.com/MornRain/morn-scrape-kit/releases/tag/v1.0.0
