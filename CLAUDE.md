# CLAUDE.md

本项目（门诊一体化系统）的全部开发约定统一维护在根目录 **[AGENTS.md](AGENTS.md)**，开始任何开发前请先完整阅读并严格遵守。

重点速记：

- 本地运行：`~/.local/bin/frankenphp php-server --root public/ --listen 0.0.0.0:8080`（无系统 php）
- 每次修改：同步版本号 / CHANGELOG / README，并按 AGENTS.md「任务拆分与分级提交推送」约定提交推送
- 提交信息：Conventional Commits 前缀标题（feat / fix / docs 等）+
  空一行后附详细正文，逐条列出本次更新细节
  （`git commit -m "<标题>" -m "<正文>"`）
- 约束：不新增第三方依赖，严格保持 PHP 7.x 兼容
