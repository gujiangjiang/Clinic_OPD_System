# AGENTS.md — 项目约定（给 AI 协作的维护指南）

本文件用于约束后续每次代码更新 / 修改时的自动化行为，请严格遵守。

## 版本标识

- 系统基准版本：**v9.6.1**（`bootstrap.php APP_VERSION`、README 徽章、`package.json` 三者必须同步）。

## 本地运行环境（本机 macOS arm64）

- 本机 **未安装系统 php**，统一使用单文件静态 PHP 二进制：
  `~/.local/bin/frankenphp`（FrankenPHP v1.12.7，内置 PHP 8.5.9 + SQLite）。
- 启动本地测试服务器（public 为 Web 根目录，等同生产 Nginx 配置，见 `docs/nginx.conf.example`）：

  ```bash
  ~/.local/bin/frankenphp php-server --root public/ --listen 0.0.0.0:8080
  ```

  或 `npm run dev` / `npm run start`（默认端口 8000，可用 `PORT` 环境变量覆盖）。
  首次访问 `http://localhost:8080` 会自动进入安装向导（6 步）。

- 语法检查（不需要系统 php，用 tokenizer 校验全部 PHP 文件）：
  `npm run lint` 或 `~/.local/bin/frankenphp php-cli tools/lint/php-lint.php`。

> 若本机安装系统 php 后可恢复 `php -S 0.0.0.0:8080 router.php` 方式。

## 基础设施配置库（config.db，v8.20+ 架构铁律）

- 基础设施配置（主库驱动/连接凭证、缓存驱动、App Key、维护模式）统一存放于
  `data/db/config.db`（独立于主业务库），由 `app/core/db/ConfigStore.php` 读写。
- 打开前必须校验 16 字节 Magic Header（`SQLite format 3\0`），损坏文件自动备份为
  `config.db.corrupt.[timestamp]` 并优雅降级回退默认配置，**严禁抛 500**。
- 主业务数据独立存放于主数据库；删除 config.db 仅重置配置不破坏业务数据
  （安装向导可【关联现有数据库】重新绑定）。
- 未生成 config.db 时系统按 bootstrap 默认常量运行（旧版向后兼容）。
- 数据库迁移（SQLite↔MySQL↔PostgreSQL）由 `app/core/db/DatabaseMigrator.php` 执行，
  经 `MigrationRunner` 以 nohup 后台 CLI 任务运行（`tools/cli/db_migrate_run.php`）：
  全站锁定（`app/includes/migrating_lock.php` 进度条 + 重新登录 + 管理员取消）、
  取消/失败自动回退原库、成功后由管理员确认再切换主库指针；迁移/切换启动时
  强制清除全部用户会话。多库备份（backup_save/backup_run）同步主库到备份库不动指针，
  支持定时自动备份（`tools/cli/db_backup_run.php`，页面调度到点后台执行、当日去重）；
  双向实时同步（dual_save + DatabaseManager::mirrorWrite）为 RAID1 式写镜像，
  仅同驱动可靠、失败静默降级，与备份功能分离。
- 驱动选项（数据库 sqlite/mysql/pgsql、缓存 file/apcu/redis/memcached）统一注册在
  `app/config/drivers.php`（唯一数据源）：安装向导、系统设置、后端校验共用，
  新增驱动仅维护该文件一处，前端下拉与参数表单自动动态渲染。

## 药品与处方规则（v8.17 核心约束）

- 药品模型含 `allow_split`（是否支持拆零零售）：**不可拆零药品仅支持按包装单位（盒/瓶）销售；允许拆零药品方可选择最小单位（支/片/粒）**，前后端双重校验。
- 底层数据库 `drugs.qty`（实时物理库存）及警戒库存（`warn_qty`）**必须且只能以最小单位整数存储与计算**，严禁浮点数库存；整盒售出扣减 `数量 × pack_size`、拆零扣减实际支/粒数。
- 开处方数量推算必须结合整包装容量（`pack_size × spec_dose`）计算，保障覆盖单次剂量底线——**严禁将单次剂量数值直接赋值给开药盒数**；当开立总量不足以支付单次剂量时，前后端必须做强拦截阻断。

## Tools 与数据工厂架构（严禁单体脚本）

- `tools/` 已重构为模块化架构（scenarios/ 场景脚本已全部拆分合并到 seeder/ 并删除）：
  - `tools/bin/`：统一 CLI 控制台调度入口（`php tools/bin/seed.php --all` /
    `--scene=visit|call|dept_call` / `--scene="doctor=工号"` / `--scene="dept=2,5"` / `--module=drug`）。
  - `tools/seeder/`：单一职责数据工厂类（`Seeder` 基类、`DeptSeeder`/`UserSeeder`/
    `DrugSeeder`/`LabSeeder`（含检验组合与危急值）/`ExamSeeder`/`DisposalSeeder`/
    `PackageSeeder`/`TemplateSeeder`/`VisitSeeder`（患者就诊链，合并原
    demo/doctor2001/full 三场景）/`QueueSeeder`（叫号队列，合并原 call/dept_call
    两场景）/`FhirDemoSeeder`（FHIR/HL7 全链路验证数据：3 套旅程 + DICOM UID/Series
    + 危急值，`--module=fhir` / `--scene=fhir`）/`VisitFlowEngine`/`PreflightChecker`）。
  - `tools/lint/`：语法检查工具（原 `tools/schema/` 一次性迁移/修复脚本已于本次复盘清理移除）。
- **开发铁律**：后续任何测试造数需求，严禁在 `tools/` 根目录随意新建孤立的 `seed_xxx.php` 脚本，
  必须在 `seeder/` 中扩展复用（场景通过 `tools/bin/seed.php` 组合调度）；
  造数一律通过统一 CLI 入口调度。
- **测试造数不提交铁律**：为测试功能执行的全量/场景 seed（如 `php tools/bin/seed.php --all` 或
  `--scene=...`）仅写入运行时数据库（`data/db/*.db`，已被 .gitignore 忽略），属于测试环境数据，
  **严禁**：① 将 seed 执行过程/命令写入 docs/CHANGELOG.md；② 对 seed 产生的改动执行
  `git add -A && git commit`；③ 以「造数/seed」为标题创建任何 commit。commit 只允许包含真实
  代码/文档改动。测试造数前后用 `git status --short` 确认工作区无 seed 产生的文件污染。

## 会话管理（Session 多驱动架构铁律）

- 会话统一由 `app/core/auth/Session.php` 驱动分发（`files` / `redis` / `memcached` 多驱动，环境变量 `SESSION_DRIVER` 切换，默认 `files` 零依赖），**严禁在业务逻辑中直接编写 `ini_set('session.*')` 或直接 `session_start()`**。
- 配置 redis/memcached 但扩展缺失或连接失败 → `Session::start()` 自动 `error_log` 告警并平滑降级 files，绝不白屏。
- **任何新增的纯只读、高频轮询类接口（大屏/心跳/队列/危急值/站内消息/待办统计等），在鉴权完成后必须调用 `Session::closeReadOnly()` 立即释放 Session 独占锁**，根除并发串行排队。

## 每次修改必须执行的自动化步骤

1. **任务完成前必须监测开发服务器（新增铁律）**：
   - 每完成一个任务/修复、在提交之前，必须执行服务器健康检查
     `npm run server:ensure`（即 `~/.local/bin/frankenphp php-cli tools/lint/php-server-check.php`）。
   - 该脚本探测开发服务器（默认端口 8000，PORT 环境变量可覆盖，8080 回退）是否存活；
     已挂则自动以 nohup 后台重启并验证恢复，输出存活/已重启/重启失败三种结果。
   - **禁止在任务收尾时用 `pkill -f frankenphp` 清理测试进程**——这会误杀开发服务器，
     导致工作区失联。需要清理测试用 PHP 进程时，按端口精准匹配（如
     `lsof -tiTCP:8090 -sTCP:LISTEN | xargs kill`），并在收尾前跑一次 `server:ensure`。

2. **同步版本与日志**：
   - 有功能变化时递增版本号（README 顶部徽章 + `bootstrap.php APP_VERSION` + `package.json` 三处同步）。
   - 在 `docs/CHANGELOG.md` 顶部按既有格式新增条目（新增 / 修复 / 变更 / 移除 / 安全），
     日期使用当天日期。如果本次只是文档 / 配置说明类改动，可在最新版本小节补充
     「文档」条目，不必单独开版本号。
   - `README.md` 若功能 / 目录 / 运行方式有变化需同步更新（如新脚本、新目录）。

3. **任务拆分与分级提交推送**：
   - 接到任务后根据复杂度自行决定是否拆分为多个子任务
     （使用 todo list 跟踪进度），逐个完成。
   - **一处修改 = 一次提交**：每个独立改动点单独提交一个 commit（如「预览尺寸」与
     「按钮样式」是两个改动点就分两个 commit），不要将多个改动点混在一个 commit 里，
     便于溯源与回退。改完一处立即 `git add -A && git commit` 一次。
   - **小步骤**：每完善 / 修复一个小步骤，立即本地提交一次：
     `git add -A && git commit -m "<简洁的中文说明>"`
   - **主要任务完成**：递增版本号（README 徽章 + CHANGELOG 新版本小节），
     并推送到远程：
     `git push origin <当前分支>`
   - **大重构 / 大变动**：适当提升次版本号（如 2.5.x → 2.6.0）。
   - **提交信息规范（Conventional Commits）**：`<type>: <中文简述>`，
     常用 type：`feat`（新功能）/ `fix`（缺陷修复）/ `docs`（文档）/
     `style`（样式 / 格式）/ `refactor`（重构）/ `perf`（性能）/ `chore`（杂项），
     如 `feat: 新增检验报告模块`、`fix: 修复收费金额合计错误`；
     禁止使用无前缀的纯中文提交信息。
     除第一行标题外，必须空一行后附**详细正文**，逐条列出本次更新细节
     （改动点、涉及模块 / 文件、CHANGELOG 同步情况等），即：
     `git commit -m "<标题>" -m "<正文多行细节>"`。
     GitHub 提交列表显示标题，展开详情页显示正文。
   - 禁止提交运行时数据（data/、public/uploads 内容已被 .gitignore 忽略）。
     **例外铁律：`data/db/icd10.db`（ICD-10 诊断字典库）必须始终纳入版本管理**
     （.gitignore 已用 `!data/db/icd10.db` 显式反忽略），严禁 `git rm` / `git rm --cached`
     删除或在 `git add -A` 时遗漏该文件；提交前用 `npm run lint` 校验
     （lint 内置守护：文件缺失或未被 git 跟踪即判失败）。

## 前端资源登记（Assets.php 铁律）

- 全站公共 CSS/JS、各页面额外资源与 Service Worker 预缓存清单统一登记于
  `app/includes/ui/Assets.php`（`CSS_CORE` / `JS_CORE` / `JS_EMR` / `JS_DEPT_WORK` …
  常量 + `precache()`），由 `layout.php` 据此生成 `<link>/<script>` 标签。
- **任何新增前端模块（css/js）必须在 `Assets.php` 登记**，页面通过
  `Assets::cssTags()/jsTags()/emrTags()` 输出；严禁在 `layout.php`、`Router` 或
  业务视图中硬编码资源路径，避免版本参数不一致、SW 预缓存清单漂移与按需加载失效。
- 资源版本参数默认按文件 mtime（`Assets::mtimeVer()`），缺失回退 `APP_VERSION`。

## 其他约定

- 遵循 README「开发约定」：单文件小、职责单一；公共字典统一维护；数据库分散迁移；接口与页面分离。
- 不擅自新增第三方依赖；严格保持 PHP 7.x 兼容（不使用 PHP 8 专有语法）。
- **UI 文案克制铁律**：标题、按钮、占位符、提示语只承载必要信息，禁止添加
  「（滚动加载）」「（含 xxx）」「（可编辑）」等冗余备注/解释性后缀——成熟系统
  的 UI 不说废话；空态/空数据一律用 `.empty` 居中组件（图标 + 简短文案），
  不用纯文字裸奔。
