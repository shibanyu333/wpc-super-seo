# Super SEO 智能优化

这是一个中文化 WordPress SEO + PageSpeed 优化插件，目标是给小白客户一个简单入口：

- 后台菜单：`超级SEO`
- DeepSeek / OpenAI 兼容接口生成 SEO 标题、描述、关键词
- 文章、页面、产品、分类里支持 `AI一键生成`
- 自动输出 meta description、keywords、canonical、Open Graph、基础 Schema
- 生成 `/super-seo-sitemap.xml`
- 修复虚拟 `robots.txt` 并自动追加 Sitemap
- 图片懒加载、首图高优先级、首屏大图 preload、WebP 生成/替换
- 安全延迟非核心 JS、关闭 Emoji/Embed、可选 HTML 压缩
- 内置针对 PageSpeed 常见扣分项的可访问性小补丁

## 使用

1. 把整个 `super seo` 文件夹放到 `wp-content/plugins/`。
2. 在 WordPress 后台启用 `Super SEO 智能优化`。
3. 进入 `超级SEO` 菜单，填写 DeepSeek API Key。
4. 到文章、页面、产品或分类编辑页，点击 `AI一键生成`，检查后保存。
5. 打开 `/robots.txt` 和 `/super-seo-sitemap.xml` 确认可访问。

## DeepSeek 默认配置

- 接口地址：`https://api.deepseek.com/chat/completions`
- 模型名称：`deepseek-chat`

也可以换成任何兼容 OpenAI Chat Completions 格式的接口。

## 关于 PageSpeed

插件会覆盖本次报告中的核心痛点：缺 meta description、robots.txt 格式、图片加载/体积、首屏图发现、阻塞 JS、空按钮名称和部分低对比度文本。最终分数仍受主题代码、服务器缓存、真实图片压缩质量、第三方插件和 CDN 配置影响。

## 与 Super Rocket 一起使用

如果站点同时启用 Super Rocket，建议让 Super Rocket 负责缓存、WebP、JS 延迟和 HTML 压缩，Super SEO 负责 SEO 标签、Schema、Sitemap、AI 生成和首屏图片兜底提示。Super SEO 保存设置、文章 SEO 或分类 SEO 后会自动尝试清理 Super Rocket 缓存，避免前台继续显示旧标题或旧描述。
