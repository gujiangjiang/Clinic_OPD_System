# AGENTS.md — 项目约定（给 AI 协作的维护指南）

本文件用于约束后续每次代码更新 / 修改时的自动化行为，请严格遵守。

## 版本标识

- 系统基准版本以 `app/config/bootstrap.php` 的 `APP_VERSION` 为唯一来源
  （`README` 徽章、`package.json` 必须与之同步，发布时三处一致）。

## 运行模式与本地测试服务器（可选）

- 本项目以**在线服务器部署为主**，本地不强制安装 PHP / FrankenPHP；是否启用本地
  PHP 测试服务器由「首次询问」决定，并记录在本机标记文件 `.agent-local-server.conf`
  （项目根目录，只记本机偏好，**严禁提交**；内容示例：`enabled=true` / `enabled=false`）。
- **首次接手（标记文件不存在）**：先探测运行环境（macOS / Linux / Windows、是否已有
  php 或 frankenphp），然后**询问用户是否启用本地 PHP 测试服务器**；用户明确答复前
  不启动、不检测、不安装任何运行时。
- 用户选择启用后，按运行环境自动选择运行时（提问时一并向用户说明可选命令）：

  | 环境 | 首选 | 备选 |
  | --- | --- | --- |
  | macOS | 现有单文件二进制 `~/.local/bin/frankenphp` | `brew install dunglas/frankenphp/frankenphp`；官方脚本 `curl https://frankenphp.dev/install.sh \| sh`；已装系统 php 用 `php -S 0.0.0.0:8000 router.php` |
  | Linux | 系统 `php` 可用时 `php -S 0.0.0.0:8000 router.php` | 官方脚本 `curl https://frankenphp.dev/install.sh \| sh`；或 `brew install dunglas/frankenphp/frankenphp` |
  | Windows | 系统 `php.exe` 可用时 `php -S 0.0.0.0:8000 router.php` | PowerShell `irm https://frankenphp.dev/install.ps1 \| iex`；或官方的 Windows 免安装包（GitHub Releases，内含 PHP 二进制） |

- 启用后的启动 / 健康检查（`public/` 为 Web 根，等同生产 Nginx 配置，见 `docs/nginx.conf.example`）：

  ```bash
  npm run dev              # 启动（默认端口 8000，PORT 环境变量可覆盖）
  npm run server:ensure    # 健康检查：挂了自动 nohup 重启并验证（见「自动化步骤」）
  ```

  首次访问会自动进入安装向导（6 步）。Windows 下 npm 脚本的 `${VAR:-默认值}` 语法
  不生效，直接使用上表对应命令。

- 语法检查（任何模式均可用，不依赖本地服务器；用 tokenizer 校验全部 PHP 文件）：
  系统 php 在位时 `php tools/lint/php-lint.php`；否则
  `~/.local/bin/frankenphp php-cli tools/lint/php-lint.php`（或 `npm run lint`）。

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

## 时间与时区铁律（v10.0.0 UTC 存储框架）

- **存储契约（UTC，目标态达成）**：所有 DATETIME 列统一存 UTC（`Y-m-d H:i:s`），
  写入唯一来源 `now_str()` / `today_str()`（模式感知）；分日一律走 PHP 口径
  （`local_date_of()` / `day_range()`），业务日期列（`reg_date` 等 `*_date`）
  存本地日。展示层一律经 `fmt_time()`（唯一展示入口）换算为站点时区。
- **双模式兼容（存量旧库平滑升级）**：`config.db` 的 `time.storage` =
  `utc`（新装默认）/ `local`（关联旧库）；`time_storage_utc()` 为唯一判定，
  `$GLOBALS['TIME_STORAGE_UTC_RUNTIME']` 仅限安装向导等受控流程覆盖。
  新代码一律使用模式感知助手，**严禁**按固定口径硬编码。
- **禁止 SQL 端时间函数**（`NOW()` / `CURRENT_TIMESTAMP` / `datetime('now')` /
  `UNIX_TIMESTAMP()` / `strftime(...)` / `date('now',...)` 等）：SQLite 的
  `localtime` 取操作系统时区（容器常为 UTC）、MySQL/PG 取会话时区，与站点时区
  不一致时产生整小时级偏斜（历史 bug：「选择科室」模态框与诊室列表在线状态
  打架，即宝塔容器 UTC + 站点上海相差 8 小时所致）。
- **相对时间/租约统一走 `app/core/helpers.d/time.php`**：`ts_epoch()` /
  `is_recent()`（fail-closed：未来超过 5 秒视为失效——宁可显示离线，也不把
  陈旧数据误判为在线/占用）。大屏在线（30 秒租约）唯一判定入口
  `QueueRepository::screenOnline()`；医生绑定（300 秒租约）
  `doctorHeartbeatStale()` / `sweepStaleBindings()`。
- **跨系统交换契约（外部接口铁律，v10.1.0 起全面 UTC）**：**出站**一律标准 UTC——
  存储值经 `iso_utc()`（ISO 8601 带 Z）单点；对外“现在”用 `now_iso_utc()`；
  FHIR 由 `FhirAdapter::isoDateTime()` 单点输出 Z；HL7 DTM 带 `+0000`
  （MSH-7 / EVN-2 / PV1-44 / ORC-9 / OBR-6/7）；HIS 只读端点（server_time /
  整行时间列）、HIS 上报（RestHisDriver）、LIS 下发（LisService）同契约；
  DICOM DA/TM 业务日期直通不换算。**入站**标准 UTC——`utc_to_storage()`
  （Z/±偏移精确解析；无偏移纯串按 UTC）落库。三系统（门诊 / HIS / PACS）同一
  契约：**底层存储 UTC、对外传输 UTC、显示按各系统自身时区设置**
  （HIS/PACS 均已升级同款时间框架与 config.db 时区设置）；对外输出不受本系统
  时区设置影响，跨时区部署无需另行约定。
- **数据库会话时区锚定**：`ConnectionFactory::pinSession()`（MySQL/PG 建立连接后
  SET 为站点时区，防御性兜底；偏移串统一走 `tz_offset_str()`）；直连
  `new PDO(ConnectionFactory::dsn(...))` 的调用点必须显式调用。SQLite 无会话
  时区——时间运算必须走 PHP 端。
- **迁移/种子 SQL**：可沿用 SQLite 方言书写 `datetime('now','localtime')`——
  `DatabaseManager::dialectSqlFor()` 会在执行时改写为**当前时刻的 PHP 站点时区
  字面量**（所有驱动统一，含 SQLite）。
- **新增表时间列不设 `DEFAULT CURRENT_TIMESTAMP`**（SQLite 为 UTC），插入显式
  传 `now_str()`。
- **打印快照时区固化**：`snapshot_patient()`（快照唯一写入入口）在创建/更新时
  固化 `print_snapshots.tz` + `tz_offset`（schema v54）；覆盖病历/申请单/凭条/
  报告/诊断证明/同意书等全部快照类单据——历史单据重打不随全站时区修改或存储
  方案切换漂移（法律合规）。
- **UTC 工具链（`tools/cli/`，存量 local 库升级/回退用）**：
  - `tz_audit.php`（审计门，核心库 `tools/lint/tz_audit_lib.php`）：扫描
    DAY_WINDOW / RELATIVE / DISPLAY / PAYLOAD / PRINT 五类残留，未清零（或经
    评审逐条 `tz-ok` 豁免非展示用途）严禁切换；
  - `tz_migrate.php plan|apply|switch|rollback`：转换列以**实库 PRAGMA** 扫描
    （`_at/_time` 结尾，含版本迁移 ALTER 新增列）；`switch --yes` = 审计门 →
    预演 → 整库备份 → 转换 → 快照回溯固化 → `time.storage=utc`（数据与模式
    一步同步）；`rollback --yes` 按 JSON 清单精确还原并回切 `local`；
  - `tz_selftest.php`：当前库全链路自检（环境口径 / 纯函数 / 全库时间列抽样 /
    reg_date 一致性 / 快照固化完整性）；`--compare=副本 --full` 跨模式渲染
    逐值等价验收。
  - **标准升级流程**：审计门清零 → 副本 `apply` + `--compare --full` 零误差 →
    生产 `switch --yes` → `tz_selftest` 自检 + 与切换前备份 `--compare --full`。
  验收方式：副本 `apply` 后与原始库渲染逐值比对零误差 + `tz_selftest` 全绿 +
  审计门全绿；三系统联调已实测（HIS 只读 UTC ISO 输出、sync-orders UTC 往返、
  FHIR 资源 UTC 输出）。
- **修改全站时区的影响**：UTC 存储下历史时间点恒定——改变站点时区仅改变展示
  换算与分日归属；法律性单据按快照固化时区渲染，完全不受影响；在线/绑定等
  相对时间租约由 fail-closed 兜底，下一笔心跳（≤30 秒）自动恢复正确状态。

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
  - `tools/cli/`：后台/定时任务（`db_migrate_run.php` 迁移、`db_backup_run.php` 备份、
    `integration_outbox_run.php` 出向同步、`log_archive_run.php` 日志滑动归档、
    `tz_migrate.php` 全库时间迁移（预演/执行/一体化切换/回滚）、`tz_selftest.php`
    时间全链路自检与跨模式验收、`tz_audit.php` 时间口径审计门（核心库在
    `tools/lint/`）、`hl7_mllp_server.php` 等）。
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

## 日志与审计（三库互迁零破坏 + 生命周期归档）

- 追加写日志表：`system_logs`（操作/接口日志统一表，见 `app/config/schema/main/01_system.php`）。
  入向调用审计已统一到此表 interface 通道（含来源 IP + 原始报文），旧 `inbound_events` 表已于 v48 下线，
  **严禁再新增入向审计孤表**。审核审批流表 `audits`（`03_audits.php`）为低写量业务表，职责不同。
- **主键铁律**：日志表 `id` 必须为单列自增主键（SQLite `INTEGER PRIMARY KEY AUTOINCREMENT` /
  MySQL `AUTO_INCREMENT` / PG `SERIAL|IDENTITY`），**严禁复合主键**（破坏 SQLite 自增与三库同步）。
- **PG 分区铁律**：严禁对日志表使用 PostgreSQL 声明式分区（物理子表会被跨库反射误识别为业务表）；
  `DatabaseMigrator::sourceTables()` 已显式排除分区子表（`relispartition=false`）。
- **索引铁律**：日志表二级索引精简（写入放大），仅保留 `created_at`、`(user_id, created_at)` 与
  渠道列表所需索引；**严禁**在 `detail`/`payload` 等长文本或 JSON 列建常规索引。检索必须先经
  `created_at` 范围收敛（`LogService::queryDefaultFrom()`，设置 `log.query.default_days`，默认 3 天，0=不限）。
- **滑动归档**：`tools/cli/log_archive_run.php`（`LogArchiver`）按保留天数淘汰超期日志，各驱动最低成本：
  MySQL 分区裁剪优先/否则行级分批、PG `ctid` 分批、SQLite 事务分批 + `incremental_vacuum`；不改变表逻辑结构。
- **跨库互迁**：`DatabaseMigrator` 迁移期仅建"纯表 + 主键"，数据导入完成后由
  `createIndexes()` 批量重建二级索引（提速 + 规避方言冲突）；导入导出不得携带任何单库专用 DDL。
- **入向/出向语义**：`system_logs.remote_ip` 记录**触发者 IP**（入向=外部调用方；出向=触发该调用的
  本机/用户，反代场景取真实客户端）；出向日志另用 `target` 记录**对方系统地址**（被调用方 URL）。
  **任何外部调用（含 PACS/DICOM 出向、HIS/FHIR/HL7/LIS、存证签名）都必须 `log_interface()` 落账**；
  高频逐帧/逐实例取像按 study/series 维度做短时去重，避免刷屏。

## 每次修改必须执行的自动化步骤

1. **本地服务器检查（仅本地测试模式，按需执行）**：
   - 默认在线部署模式（标记文件 `.agent-local-server.conf` 不存在或为 `enabled=false`）：
     **不检测、不启动、不重启**本地服务器，代码编写 + 语法检查即可。
   - 仅当标记文件为 `enabled=true`（用户已启用本地测试）时：每完成一个任务/修复、
     在提交之前，执行服务器健康检查 `npm run server:ensure`（即
     `tools/lint/php-server-check.php`；无系统 php 时经 `frankenphp php-cli` 调用）。
   - 该脚本探测开发服务器（默认端口 8000，PORT 环境变量可覆盖，8080 回退）是否存活；
     已挂则自动以 nohup 后台重启并验证恢复，输出存活/已重启/重启失败三种结果。
   - **禁止在任务收尾时用 `pkill -f frankenphp` 清理测试进程**——这会误杀开发服务器，
     导致工作区失联。需要清理测试用 PHP 进程时，按端口精准匹配（如
     `lsof -tiTCP:8090 -sTCP:LISTEN | xargs kill`；Windows 用
     `netstat -ano | findstr :8090` + `taskkill /PID <pid> /F`）。

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
