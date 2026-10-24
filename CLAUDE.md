# CLAUDE.md

本项目（门诊一体化系统）的全部开发约定统一维护在根目录 **[AGENTS.md](AGENTS.md)**，开始任何开发前请先完整阅读并严格遵守。当前基准版本以 `app/config/bootstrap.php` 的 `APP_VERSION` 为准。

## 版本与文档索引

- 版本号：`bootstrap.php APP_VERSION` / README 徽章 / `package.json` 三处必须同步。
- 系统变更日志：**`docs/CHANGELOG.md`**（已从根目录迁移）。
- 部署配置模板：**`docs/nginx.conf.example`**（已从根目录迁移）。

## 常用命令（Commands）

### 数据生成（统一 CLI，模块化造数架构）
- 全量测试造数（默认）：`php tools/bin/seed.php --all`
- Demo 演示环境数据：`php tools/bin/seed.php --scene=demo`
- 叫号大屏专项测试：`php tools/bin/seed.php --scene=call`
- 多科室分诊叫号专项：`php tools/bin/seed.php --scene=dept_call`
- 指定医生接诊专项：`php tools/bin/seed.php --scene="doctor=2001"`
- 指定科室接诊：`php tools/bin/seed.php --scene="dept=2,5"`
- 仅重置药品与库存：`php tools/bin/seed.php --module=drug`
- 无系统 php 时统一前缀：`frankenphp php-cli tools/bin/seed.php ...`（macOS 本机为 `~/.local/bin/frankenphp`；已装系统 php 的环境直接用 `php tools/bin/seed.php ...`）

### 代码检查与 Lint
- PHP 语法检查：`php tools/lint/php-lint.php`（本机用 `~/.local/bin/frankenphp php-cli tools/lint/php-lint.php` 或 `npm run lint`）
- CI 全量校验：`php tools/lint/ci-lint.php`
- 前端 JS 语法检查（JXA，非 node）：`osascript -l JavaScript tools/lint/jscheck.js <file.js>`

### 本地运行（可选，默认在线部署；见 AGENTS.md「运行模式与本地测试服务器」）
- 启用了本地测试服务器时：`npm run dev`（默认端口 8000）；macOS 本机二进制
  `~/.local/bin/frankenphp php-server --root public/ --listen 0.0.0.0:8080`；
  已装系统 php 时 `php -S 0.0.0.0:8080 router.php`

## 重点速记

- 本地服务器：默认在线模式**不检测、不启动**；首次接手先按 AGENTS.md 询问用户，
  偏好记录在 `.agent-local-server.conf`；仅 `enabled=true` 时才做服务器健康检查
- 每次修改：同步版本号 / `docs/CHANGELOG.md` / README，并按 AGENTS.md「任务拆分与分级提交推送」约定提交推送
- 提交信息：Conventional Commits 前缀标题（feat / fix / docs 等）+
  空一行后附详细正文，逐条列出本次更新细节
  （`git commit -m "<标题>" -m "<正文>"`）
- 药品与处方：`allow_split` 拆零约束、库存最小单位整数存储、开方数量按包装容量覆盖单次剂量（见 AGENTS.md）
- 约束：不新增第三方依赖，严格保持 PHP 7.x 兼容