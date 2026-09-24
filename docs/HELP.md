# 📗 使用帮助（HELP）

> 本文件为系统的完整使用与运维指导。项目特性与介绍见 [README.md](./README.md)。
> 所有操作步骤均基于最新版本，若界面有出入请以实际版本为准。

## 🚀 快速开始

### 环境要求

- PHP ≥ 7.0（建议 7.2 ~ 7.4，未使用 PHP 8 新特性）；本机未安装系统 PHP 时，
  可使用单文件静态 PHP 运行时（FrankenPHP，内置 PHP 8.5 + SQLite，见下方「本地开发预览」）。
- SQLite 扩展（PHP 默认内置）
- 可选：Nginx + PHP-FPM（生产环境）

### 本地开发预览

```bash
# 方式一：本机已安装系统 php，使用内置服务器（public 为唯一 Web 根目录，router.php 负责静态资源与路由）
php -S 0.0.0.0:8080 router.php

# 方式二：本机无系统 php，使用单文件静态 PHP 运行时（FrankenPHP，macOS arm64）
# 1) 下载单文件二进制并放到 PATH（示例：~/.local/bin/frankenphp）
#    https://github.com/php/frankenphp/releases 选择 frankenphp-mac-arm64
# 2) 启动（public 为 Web 根目录，等同生产 Nginx 配置，首次访问自动建库）
~/.local/bin/frankenphp php-server --root public/ --listen 0.0.0.0:8080
```

浏览器访问 `http://localhost:8080`，首次访问自动进入 5 步安装向导
（环境巡检 → 数据库与缓存配置 → 医疗机构信息 → 创建管理员 → 确认执行）。

> 语法检查可运行 `npm run lint`（内部用 `tools/lint/php-lint.php` 通过 tokenizer 校验全部 PHP 文件，无需系统 php）；
> `npm run dev` / `npm run start` 默认端口 8000，可用 `PORT` 环境变量覆盖。

### 基础设施配置库（config.db）

系统基础设施配置（主库驱动与连接凭证、缓存驱动、App Key、维护模式）独立存放于
`data/db/config.db`，与主业务库完全解耦：删除 `config.db` 仅重置配置、不破坏业务数据，
安装向导提供【关联现有数据库】选项重新绑定已有主库。config.db 打开前校验 SQLite
Magic Header，损坏文件自动备份并优雅降级，绝不因配置文件损坏导致服务器 500。

驱动选项（数据库：SQLite / MySQL / PostgreSQL；缓存：File / APCu / Redis / Memcached）
统一注册在 `app/config/drivers.php`（唯一数据源）——安装向导与系统设置-数据库中心/缓存
与性能的驱动下拉及参数表单均按注册表动态渲染，后端校验共用同一白名单，新增驱动只需
维护注册表一处。

### 🧪 快速初始化与测试造数（统一 CLI）

安装完成并启动后，可通过统一造数 CLI 一键生成测试/演示数据（模块化架构，
基础字典按 `--module` 调度独立 Seeder，就诊链/叫号按 `--scene` 组合调度）：

```bash
# 全量测试造数（默认）：科室/账号/药品/检验（含 16 个检验组合与危急值）/检查/处置/套餐/模板 + 近 15 天患者就诊全链路（含待缴费/已退费/已取消状态）
php tools/bin/seed.php --all
# Demo 演示环境数据（同 --all）
php tools/bin/seed.php --scene=demo
# 患者就诊链专项（追加式，不动字典）
php tools/bin/seed.php --scene=visit
# 指定医生工号接诊专项（校验工号存在且为医生角色，如 2001 张伟）
php tools/bin/seed.php --scene="doctor=2001"
# 指定科室就诊链
php tools/bin/seed.php --scene="dept=2,5"
# 门诊叫号大屏专项：为指定科室生成当天已缴费患者（默认科室 2,5 各 30 名）
php tools/bin/seed.php --scene=call
# 医技四科室叫号专项：检验/检查/处方/护理处置各加 N 位待办患者
php tools/bin/seed.php --scene=dept_call
# 医技精细模式：仅开检验单（lab/exam/prescription/disposal 可组合，可带数量如 lab,exam:10）
php tools/bin/seed.php --scene="dept=lab"
# 仅重置药品与库存（药品最小单位库存 / 警戒库存 / 护士执行标识）
php tools/bin/seed.php --module=drug
# 基础字典模块：clinic/dept/user/screen/drug/lab/exam/disposal/package/template 可多选（逗号/空格分隔）
php tools/bin/seed.php --module="lab exam disposal"
```

本机无系统 php 时统一加前缀：`~/.local/bin/frankenphp php-cli tools/bin/seed.php ...`。
造数场景统一经 `VisitFlowEngine` 状态机引擎与 `VisitSeeder`/`QueueSeeder` 数据工厂组合调度，
前置 `PreflightChecker` 依赖探测（ICD-10 诊断库/检查/检验/药品库存/处置项目缺失即终止）。

### 生产部署（Nginx）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ ^/(app|data)/ { deny all; }
}
```

完整示例见 `docs/nginx.conf.example`。

### 🔐 Session 存储架构与高并发调优

会话管理器（`app/core/Session.php`）支持三种存储驱动，默认零依赖开箱即用，通过环境变量切换并自动优雅降级：

**方案一：Files 原生文件驱动（默认零依赖）**
- 适合中小诊所单机部署，零配置开箱即用；Session 文件写入 `data/session/`。
- 单机性能优化推荐：Linux 下用 `tmpfs` 虚拟内存盘挂载 Session 目录，消除物理磁盘 I/O 损耗（见 `docs/nginx.conf.example` 注释）：
  ```bash
  mkdir -p /tmp/php-session
  mount -t tmpfs -o size=64m,mode=0777 tmpfs /tmp/php-session
  # 并设置 SESSION 目录指向该内存盘
  ```

**方案二：Redis 内存驱动模式**
- 设定环境配置即可启用：
  ```bash
  SESSION_DRIVER=redis
  REDIS_HOST=127.0.0.1
  REDIS_PORT=6379
  REDIS_AUTH=yourpassword        # 可选
  REDIS_PREFIX=clinic_sess:
  REDIS_TIMEOUT=2.0
  ```
- 需安装 PHP `redis` 扩展；多机负载均衡/集群部署推荐此方案，会话可跨节点共享。

**方案三：Memcached 内存驱动模式**
- 设定环境配置即可启用（支持逗号分隔多节点分布式）：
  ```bash
  SESSION_DRIVER=memcached
  MEMCACHED_SERVERS=127.0.0.1:11211,10.0.0.2:11211
  MEMCACHED_PREFIX=clinic_sess:
  ```
- 需安装 PHP `memcached` 扩展；适用于纯内存键值缓存集群。

**自动降级**：配置了 redis/memcached 但对应 PHP 扩展未安装或连接失败时，系统记录 `error_log` 告警并自动平滑降级为 `files` 存储，绝不白屏或崩溃。

**并发锁机制**：系统在高频轮询（叫号大屏每 3 秒、危急值提醒、站内消息铃铛、医生端队列与心跳、医技工作台、药房/收银台待办）的只读接口鉴权完成后调用 `Session::closeReadOnly()` 立即释放 Session 独占锁，消除多请求并发排队等待（TTFB 保持低位）。

## 📖 使用流程（快速体验）

1. 访问首页 → 安装页设置管理员密码、医院名称 → 完成安装。
2. 用 `admin` 登录 → 【科室管理】添加科室 → 【用户管理】创建各角色账号（医生勾选关联科室）→ 添加检验/检查/药品/处置项目并在【审核中心】通过审核。
3. 挂号收费处挂号 → 缴费 → 凭条打印。
4. 医生工作站接诊 → 书写病历（主诉/现病史/诊断必填并保存）→ 开检验/检查/处置/处方（缴费后各科室可见）→ 知情同意书（选模板→填写→保存→打印，新建默认追加在下方）→ 需要时可发起**科室间会诊**（选科室→会诊单→发送，自动弹出会诊申请单打印预览；可同时向多个科室发起，同科室需待完毕后再发）。
    同一次挂号可由多位医生接诊（续写），开单项目跟随医生归档，删除/毁方仅限开单本人；诊毕后病历全链路快照封存（文书/诊断/开单/会诊/证明均不可删改，归档仅可查看与补开诊断证明）。
5. 检验科/影像科登记 → 录入结果 → 报告生成；药房发药；护士站执行处置与生命体征。
6. 诊毕 → 选择离院方式（自主离院/住院/转院/死亡/其他）→ 运营分析可查询转归。

> 首次登录系统会提醒修改默认密码；全站时区默认取创建管理员时的浏览器时区，管理员可在【系统设置】中修改。

## 🔌 切换数据库（SQLite / MySQL / PostgreSQL）

系统默认使用 SQLite（零配置即装即用），生产环境可一键切换 MySQL/MariaDB 或 PostgreSQL（统一主库，不再拆分多库）：

1. 修改 `app/config/bootstrap.php`：`DB_DRIVER` 改为 `'mysql'` 或 `'pgsql'`，填写对应连接常量（`MYSQL_HOST / MYSQL_PORT / MYSQL_DB_NAME / MYSQL_USER / MYSQL_PASS` 或 `PGSQL_HOST / PGSQL_PORT / PGSQL_DB_NAME / PGSQL_USER / PGSQL_PASS`）。
2. `DatabaseManager` 自动执行建表与增量迁移（方言自动转换：`AUTOINCREMENT→AUTO_INCREMENT/SERIAL`、`INSERT OR IGNORE→INSERT IGNORE/ON CONFLICT DO NOTHING`、`INSERT OR REPLACE→REPLACE INTO/ON CONFLICT`、`datetime('now','localtime')→NOW()`；业务 SQL 中剩余 SQLite 专有写法已按驱动分支适配）。
3. 业务查询代码（`DB::q/one/val/exec/insert`）无需改动。

## 🔌 HIS 预留接口

系统内置只读 HIS 对接接口（`/api/his`），为未来扩展住院 HIS、医保、BI 等系统提供数据支持。

在【接口管理】（/admin/integration）中配置「HIS 接口密钥」（留空则接口关闭；系统代码/同步模式等亦在该页维护）。页面要点：

- **HIS API 地址为自动生成、无需人工填写**：即本系统对外提供服务的接口地址（当前访问地址 + `/api/his`），页面实时展示并附带密钥、一键复制；未填密钥时显示提示占位。
- **接口连通性测试**：右侧面板内置【▶ 开始测试】，实际请求 `/api/his` 的 `ping` 自检，分别验证「X-HIS-Key 请求头」与「GET 参数 api_key」两种认证方式并展示返回 JSON。
- **系统代码**：由 HIS 侧分配、用于在 HIS 方标识本系统的编码（预留字段，认证仅依赖密钥；`ping` 返回中回显）。

外部 HIS 系统需能访问该地址并携带密钥调用：

```bash
# 推荐：请求头方式（密钥不进 URL / 访问日志）
curl -H "X-HIS-Key: 你的密钥" "http://your-domain/api/his?action=patient_get&id_card=110101199001011234"
# 兼容方式：GET 参数（会进入访问日志，请自行评估风险）
curl "http://your-domain/api/his?action=patient_get&id_card=110101199001011234&api_key=你的密钥"
```

| action | 参数 | 说明 |
| --- | --- | --- |
| `ping` | 无 | 连通性自检，返回系统标识、系统代码与服务器时间 |
| `patient_get` | `id_card` 或 `patient_no` | 查询患者档案 |
| `visit_list` | `patient_no` | 该患者全部就诊记录 |
| `visit_status` | `flow_no` | 查询某次就诊状态 |
| `order_list` | `visit_id` | 某次就诊的开单明细 |
| `evidence_verify` | `record_id` 或 `cert_no` | 存证校验（病历/证明的哈希指纹与凭据验真） |

## 🔗 URL 混淆密钥

系统对就诊、申请单、报告等患者级实体 ID 做全链路混淆加密，防止 URL 撞库遍历他人医疗数据。

> 说明：全站采用 AJAX 局部刷新后，页面切换已不依赖 URL 中的实体 ID（如 `visit_id`），
> 访问链接中不再出现 `.../doctor/emr?visit_id=CSDUJCYGhFyM_LGRzEu3LA` 这类明文混淆串；
> 但实体 ID 混淆/防撞库机制**依然保留并在后台全链路生效**——接口入参统一解码校验、输入侧 `did()` 解密，明文数字 ID 一律按「记录不存在」拒绝，杜绝外部遍历。

- 密钥由系统首次使用时自动生成，管理员可在【系统设置 → URL 安全混淆密钥】中查看、复制或一键重置；
- **重置后所有旧链接立即失效**，系统功能不受影响；
- 输入侧统一 `did()` 解码，明文数字 ID 一律按「记录不存在」拒绝。

## 🔒 安全说明

- CSRF 令牌校验所有 POST 请求；PDO 预处理语句防 SQL 注入；`password_hash/verify` 密码哈希。
- **登录验证码与防爆破锁定**（见上方「登录安全」）：验证码 Session 存储比对即销毁（防重放）、登录失败 IP+会话频控（30 分钟窗口）、预检接口防枚举限流、密码连续错误达阈值自动锁定（管理员解锁闭环 + 审计日志）。
- **业务实体 ID 全链路混淆加密**（见上方「URL 混淆密钥」），防 URL 撞库遍历他人医疗数据。
- 输出统一 `e()` 转义防 XSS；Session Cookie HttpOnly + SameSite；登录重置会话 ID。
- 角色级页面/接口权限（无关角色无法直接访问其他科室功能）；非管理员管理页面只读，新增走审核。
- 上传类型/大小校验 + 随机文件名；LOGO base64 内联显示，封禁 `/uploads/logo/` 直链。
- `data/` 与 `app/` 位于 Web 根目录（public）之外，不可直接访问。
- 管理员首次登录提示修改默认密码。
- **并发安全**：就诊序号/申请单号/会诊单号/证明号数据库唯一索引 + 撞号重试；缴费/退费/执行等状态迁移一律事务内条件更新（`WHERE status=...`），退费资格判定与状态迁移同事务，杜绝重复缴费/重复退费/半退状态。

## 🧩 开发约定

- **单文件小、职责单一**：PHP / JS / CSS 文件按功能拆分，管理端接口已拆分到 `app/api/parts/`，项目/药品表单统一收敛到 `app/includes/forms.php`；病历主控 `emr.js` 已拆分出 `emr_cert` / `emr_consult` / `emr_diag` 等子模块（经 `Clinic.emr._ctx` 共享上下文桥接，内部调用与公共 API 语义不变）。
- **公共数据统一存放**：性别、民族、职业、职称、频次、途径等字典统一维护在 `app/config/options_data.php`。
- **样式按主题拆分**：明亮 / 夜间 / 自动模式分别维护。
- **数据库统一主库 + 迁移规范化**：业务数据收敛于唯一主库 clinic_main，新增模块时在 `app/config/schema/main.php` 中补表定义与版本迁移，`DatabaseManager` 自动建库、增量迁移并做运行时关键列自愈（SQLite/MySQL/PostgreSQL 三驱动幂等）。
- **接口与页面分离**：业务逻辑写入 `app/api/`，页面通过 AJAX 局部刷新调用。

## 🗄️ 数据库中心（系统设置）

管理员在【系统设置 → 数据库中心】可管理数据库相关能力：

- **当前数据库连接**：驱动类型/连接延迟/表数量/总行数/库大小 + 配置库（config.db）状态。
- **数据表浏览器**：点击任意表查看字段属性与分页行数据（只读），支持 CSV 导出；SQLite / MySQL / PostgreSQL 均支持。
- **数据库迁移工具**：SQLite / MySQL / PostgreSQL 三驱动任意双向全量迁移（分批 Chunk 500 行、外键临时关闭、自增序列校准）。迁移以后台任务执行（刷新页面不中断），期间全站锁定并显示进度条；管理员可取消（当前表完成后停止，主库回退原库）；成功后询问是否将主库切换为目标数据库。迁移目标驱动自动屏蔽当前驱动（同格式迁移无意义）。
- **直接切换主库**：目标库须已有完整数据（users 表非空），切换强制清除全部用户会话并锁定。
- **多数据库备份**：配置备份库（SQLite / MySQL / PostgreSQL），支持立即备份、定时自动备份（如每晚 2 点，页面调度后台执行、当日去重）、以及双向实时同步（RAID1 式双写——每次写入实时镜像到备份库，仅同驱动可靠，失败自动降级不影响主库体验）。备份与双向为两个独立功能（二选一子 Tab）：备份=手动/定时全量同步；双向=写入实时镜像。备份面板提供【查看日志】模态框（操作成功/失败详情，支持清空与导出 .log 文件）。

> 个人信息：侧边栏【个人信息】进入个人主页（GitHub 风格，左侧资料与主题/打印/密码设置，右侧资料编辑），【修改密码】在个人信息页内弹出模态框完成。

> 迁移/切换期间全站锁定：所有用户界面显示半透明遮罩 + 进度条，可点【重新登录】退出；
> 管理员额外显示【取消迁移】按钮；迁移成功后询问是否切换主库。

