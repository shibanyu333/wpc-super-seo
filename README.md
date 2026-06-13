# Super SEO 智能优化

这是一个中文化 WordPress SEO + PageSpeed 优化插件，目标是给小白客户一个简单入口：

- 后台菜单：`超级SEO`
- DeepSeek / OpenAI 兼容接口生成 SEO 标题、描述、焦点关键词
- 文章、页面、产品、分类里支持 `AI一键生成`，先预览再由用户保存
- 自动输出 meta description、canonical、Open Graph、基础 Schema，可选兼容旧式 meta keywords
- 生成 `/super-seo-sitemap.xml`
- 保留虚拟 `robots.txt` 现有规则并自动追加 Sitemap
- 本地 PageSpeed/SEO 启发式检测：Meta、Schema、OG、Canonical、图片属性、LCP 图片、阻塞脚本、robots/sitemap 和插件重叠风险
- PageSpeed 模式：安全、平衡、激进，逐步开启 WebP、JS defer、首图 preload、HTML 压缩和主题补丁
- 图片懒加载、首图高优先级、首屏大图 preload、WebP 生成/替换
- 安全延迟非核心 JS、关闭 Emoji/Embed、可选 HTML 压缩
- 可选针对当前主题的 PageSpeed 可访问性小补丁
- WooCommerce 优先的 AI 产品画像、批量 Meta 建议、AI 文章生成、草稿/定时/直接发布模式

## 使用

1. 把整个 `super seo` 文件夹放到 `wp-content/plugins/`。
2. 在 WordPress 后台启用 `Super SEO 智能优化`。
3. 进入 `超级SEO` 菜单，填写 DeepSeek API Key。AI 接口必须使用 HTTPS，避免密钥明文传输。
4. 到文章、页面、产品或分类编辑页，点击 `AI一键生成`，检查后保存。
5. 在 `超级SEO` 里运行本地 PageSpeed/SEO 检测，按报告逐步调整模式和配置。
6. 生成产品画像后，可批量生成 Meta 建议；应用前插件会保存旧值快照，便于回滚。
7. 打开 `/robots.txt` 和 `/super-seo-sitemap.xml` 确认可访问。

## DeepSeek 默认配置

- 接口地址：`https://api.deepseek.com/chat/completions`
- 模型名称：`deepseek-chat`

也可以换成任何兼容 OpenAI Chat Completions 格式的接口。

## 关于关键词

插件里的“焦点关键词”用于帮助 AI 理解页面主题，并指导标题、描述、正文、标签和图片 alt 的优化。默认不会输出 `<meta name="keywords">`，因为 Google 不使用该标签作为网页排名信号。后台提供了兼容旧系统的开关，但常规 Google SEO 不建议开启。

## 关于 PageSpeed

插件不接入 Google PageSpeed API，后台的检测是本地启发式扫描，用来发现能由插件自动或半自动修复的问题：缺 meta description、robots.txt/sitemap、图片加载属性、首屏图发现、结构化数据和阻塞 JS 等。空按钮名称、低对比度文本等主题专项补丁默认关闭，建议只在确认适配当前主题后开启。最终分数仍受主题代码、服务器缓存、真实图片压缩质量、第三方插件和 CDN 配置影响。

## 关于 AI 自动化

AI 产品画像会优先分析 WooCommerce 产品、产品分类、属性、现有 SEO Meta 和站点信息。文章生成支持严格产品相关、行业科普和增长长尾词三种策略；发布模式支持草稿、定时和直接发布，默认是草稿。直接发布需要额外开启，并且会经过产品相关性、禁用/未支持承诺、标题、描述、字数、重复标题和分类 ID 校验。

## 与 Super Rocket 一起使用

如果站点同时启用 Super Rocket，建议让 Super Rocket 负责缓存、WebP、JS 延迟和 HTML 压缩，Super SEO 负责 SEO 标签、Schema、Sitemap、AI 生成和首屏图片兜底提示。Super SEO 保存设置、文章 SEO 或分类 SEO 后会自动尝试清理 Super Rocket 缓存，避免前台继续显示旧标题或旧描述。
