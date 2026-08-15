# Super SEO 智能优化

中文化的 WordPress SEO + PageSpeed 插件，目标是给小白客户一个简单入口：

- 后台菜单：`超级SEO`
- 多服务商大模型：Claude（Anthropic）、OpenAI GPT、Kimi（月之暗面）、Qwen 通义千问、DeepSeek、任意 OpenAI 兼容接口
- **图片 ALT 智能识别**：用视觉模型看懂图片，自动写 alt 描述标签（单张 / 批量 / 上传时自动）
- 文章、页面、产品、分类里支持 `AI一键生成`，先预览再由用户保存
- 自动输出 meta description、canonical、Open Graph、基础 Schema，可选兼容旧式 meta keywords
- 逐页 `noindex` 开关，勾选后同时输出 robots meta 并移出站点地图
- 生成 `/super-seo-sitemap.xml`
- 保留虚拟 `robots.txt` 现有规则并自动追加 Sitemap
- 本地 PageSpeed/SEO 启发式检测：Meta、Schema、OG、Canonical、图片属性、LCP 图片、阻塞脚本、robots/sitemap 和插件重叠风险
- PageSpeed 模式：安全、平衡、激进，逐步开启 WebP、JS defer、首图 preload、HTML 压缩
- 图片懒加载、首图高优先级、首屏大图 preload、WebP 生成/替换
- 安全延迟非核心 JS、关闭 Emoji/Embed、可选 HTML 压缩
- WooCommerce 优先的 AI 产品画像、批量 Meta 建议（支持一键撤销）、AI 文章生成、草稿/定时/直接发布模式

## 使用

1. 把整个 `super seo` 文件夹放到 `wp-content/plugins/`。
2. 在 WordPress 后台启用 `Super SEO 智能优化`。
3. 进入 `超级SEO` 菜单，选服务商并填 API Key。接口必须使用 HTTPS。
4. 到文章、页面、产品或分类编辑页，点击 `AI一键生成`，检查后保存。
5. 到 `图片 ALT 智能识别` 面板，先对单张图片试效果，再点“开始批量识别”。
6. 在 `超级SEO` 里运行本地 PageSpeed/SEO 检测，按报告逐步调整模式和配置。
7. 打开 `/robots.txt` 和 `/super-seo-sitemap.xml` 确认可访问。

## 服务商与模型

| 服务商 | 默认接口 | 默认文字模型 | 默认视觉模型 | 能读图 |
|---|---|---|---|---|
| Claude（Anthropic） | `https://api.anthropic.com/v1/messages` | `claude-opus-5` | `claude-opus-5` | ✅ |
| OpenAI GPT | `https://api.openai.com/v1/chat/completions` | `gpt-4o` | `gpt-4o` | ✅ |
| Kimi（月之暗面） | `https://api.moonshot.cn/v1/chat/completions` | `kimi-latest` | `kimi-latest` | ✅ |
| Qwen 通义千问 | `https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions` | `qwen-plus` | `qwen-vl-max` | ✅ |
| DeepSeek | `https://api.deepseek.com/chat/completions` | `deepseek-chat` | — | ❌ |
| 自定义 | 自填 | 自填 | 自填 | 取决于模型 |

文字和图片可以用两家不同的服务商（例如文字用便宜的 DeepSeek、图片用 Claude）。两边选同一家时，视觉 Key 留空会自动复用文字的 Key。

插件按服务商自动选择请求格式：Claude 走 Anthropic Messages API（`x-api-key` + `anthropic-version` 头），其余走 OpenAI 兼容的 `/chat/completions`。Claude 5 / GPT-5 一类模型不接受 `temperature`，插件会自动跳过该参数，不用手动改。

## 关于图片 ALT

- 图片会先在服务器上缩到 1024px（可调）再送去识别，控制 token 花费。实测 340KB 的原图压到约 58KB。
- alt 语言留空时跟随**站点语言**（`get_locale()`），不是 AI 文案语言——alt 是给访客看的，必须和站点一致。
- 默认只补空白的 alt，不覆盖人工写过的内容；要覆盖请显式勾选。媒体标题只在原标题是 `IMG_1234` 这类占位名时才改写。
- 判定为装饰性的图片会写入空 alt，这是无障碍规范的正确做法。
- **限流和服务商故障不会把图片标记成失败**：批量会当场暂停并保留进度，等恢复后继续即可，不会丢图也不会重复计费。只有真正的永久错误（密钥无效、文件损坏、格式不支持）才会记为失败。
- 每批有**时间预算**（默认 20 秒，`super_seo_vision_batch_seconds` 过滤器可调），到点就收手把剩下的交给下一次请求，避免撞 PHP `max_execution_time`。
- 失败的图片可以单独重试（「只重试失败的」），不会连带重跑已成功的图片。
- 后台显示累计 token 消耗，方便估算费用。
- 批量识别是浏览器驱动的循环，关闭页面会中断，已处理的部分不受影响。

## 关于关键词

“焦点关键词”用于帮助 AI 理解页面主题，并指导标题、描述、正文、标签和图片 alt 的优化。默认不输出 `<meta name="keywords">`，因为 Google 不使用该标签作为网页排名信号。后台提供了兼容旧系统的开关，但常规 Google SEO 不建议开启。

## 关于 PageSpeed

插件不接入 Google PageSpeed API，后台的检测是本地启发式扫描，用来发现能由插件自动或半自动修复的问题。

几个默认行为值得知道：

- 只有在检测到其它 SEO 插件（Yoast / Rank Math / AIOSEO / SEOPress 等）时，插件才会开启整页输出缓冲去重复标签，否则不做这件事，避免白白牺牲 TTFB。可用 `super_seo_needs_head_dedupe` 过滤器强制开关。
- HTML 压缩只把连续空白折叠成一个空格，不会删掉标签之间的空格——删掉会让 `</span> <span>` 之间的单词粘在一起。
- 可访问性补丁只包含通用规则（focus 轮廓、给空图标按钮补 aria-label）。站点专属的对比度修正请用 `super_seo_accessibility_css` 过滤器注入，不要写进插件。

最终分数仍受主题代码、服务器缓存、真实图片压缩质量、第三方插件和 CDN 配置影响。

## 关于 AI 自动化

AI 产品画像会优先分析 WooCommerce 产品、产品分类、属性、现有 SEO Meta 和站点信息。文章生成支持严格产品相关、行业科普和增长长尾词三种策略；发布模式支持草稿、定时和直接发布，默认是草稿。直接发布需要额外开启，并且会经过产品相关性、禁用/未支持承诺、标题、描述、字数、重复标题和分类 ID 校验。

⚠️ Google 对规模化生成的低价值内容有明确的垃圾内容政策。质量门禁只能挡住明显不合格的内容，挡不住“合格但没价值”的内容，建议保持默认的草稿模式并人工过一遍再发。

批量 Meta 建议在应用前会保存旧值快照，面板上有“撤销上次批量应用”按钮可以一键还原。

## 安全说明

API Key 保存在独立的 `super_seo_credentials` 选项里，并且不参与 autoload，不会在每个前台请求中被加载；也不会写入任何插件文件。接口地址强制 HTTPS，站内检测只允许抓取本站同域 URL。

## 与 Super Rocket 一起使用

如果站点同时启用 Super Rocket，建议让 Super Rocket 负责缓存、WebP、JS 延迟和 HTML 压缩，Super SEO 负责 SEO 标签、Schema、Sitemap、AI 生成和图片 alt。Super SEO 保存设置、文章 SEO 或分类 SEO 后会自动尝试清理 Super Rocket 缓存，避免前台继续显示旧标题或旧描述。

## 开发

```bash
php tests/run.php   # 不依赖 WordPress 的纯函数回归测试
```
