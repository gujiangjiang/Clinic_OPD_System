# AGENTS.md — 项目约定（给 AI 协作的维护指南）

本文件用于约束后续每次代码更新 / 修改时的自动化行为，请严格遵守。

## 本地运行环境（本机 macOS arm64）

- 本机 **未安装系统 php**，统一使用单文件静态 PHP 二进制：
  `~/.local/bin/frankenphp`（FrankenPHP v1.12.7，内置 PHP 8.5.9 + SQLite）。
- 启动本地测试服务器（public 为 Web 根目录，等同生产 Nginx 配置）：

  ```bash
  ~/.local/bin/frankenphp php-server --root public/ --listen 0.0.0.0:8080
  ```

  或 `npm run dev` / `npm run start`（默认端口 8000，可用 `PORT` 环境变量覆盖）。
  首次访问 `http://localhost:8080` 会自动进入安装页。

- 语法检查（不需要系统 php，用 tokenizer 校验全部 PHP 文件）：
  `npm run lint` 或 `~/.local/bin/frankenphp php-cli tools/php-lint.php`。

> 若本机安装系统 php 后可恢复 `php -S 0.0.0.0:8080 router.php` 方式。

## 每次修改必须执行的自动化步骤

1. **同步版本与日志**：
   - 有功能变化时递增版本号（README 顶部徽章 + CHANGELOG 顶部新增版本小节）。
   - 在 `CHANGELOG.md` 顶部按既有格式新增条目（新增 / 修复 / 变更 / 移除 / 安全），
     日期使用当天日期。如果本次只是文档 / 配置说明类改动，可在最新版本小节补充
     「文档」条目，不必单独开版本号。
   - `README.md` 若功能 / 目录 / 运行方式有变化需同步更新（如新脚本、新目录）。

2. **自动提交 GitHub commit 并推送到远程**：
   - 修改完成后必须执行：
     `git add -A && git commit -m "<简洁的中文说明>" && git push origin <当前分支>`
   - 提交信息风格参考历史提交（简洁、中文、概括本次改动）。
   - 禁止提交运行时数据（data/、public/uploads 内容已被 .gitignore 忽略）。

## 其他约定

- 遵循 README「开发约定」：单文件小、职责单一；公共字典统一维护；数据库分散迁移；接口与页面分离。
- 不擅自新增第三方依赖；严格保持 PHP 7.x 兼容（不使用 PHP 8 专有语法）。
