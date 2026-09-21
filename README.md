# 🏥 简易门诊一体化系统（Clinic OPD System）

一套基于 **PHP 7.x + SQLite + 原生 JS/CSS** 的自包含门诊一体化信息系统，**无 Composer、无第三方框架**。

![版本](https://img.shields.io/badge/版本-v8.17.30-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite%2FMySQL双驱动-003B57) ![部署](https://img.shields.io/badge/部署-Nginx-009639) ![代码](https://img.shields.io/badge/代码-全中文注释-orange)

覆盖 **挂号收费处、护士站、医生工作站、影像科、检验科、药房、管理员** 等多角色完整业务闭环：
挂号 → 缴费 → 接诊 → 电子病历 → 开单（检验/检查/处置/处方）→ 执行 → 报告 → 发药 → 诊毕（含离院转归）→ 运营分析。

支持 **明亮 / 夜间 / 自动** 三种主题、**侧边栏展开/缩小两态切换**、**站内消息互发与打印提醒**、**统一打印中心**。

> **全站 AJAX 局部刷新导航（v7.7.0+）**：侧边栏菜单 / 站内链接 / 病历页切换患者均通过 AJAX
> 局部刷新完成（地址栏保持不变，不整页重载）；登录、安装、落地页、退出、叫号大屏等独立页仍整页加载。

> **医技科室工作台（v7.8.0+）**：护士站 / 检验科 / 影像科 / 药房工作台统一为与医生工作站
> 一致的「顶部患者信息横条 + 左侧候诊列表 + 主工作区」布局；公共骨架（`dept_workbench.php`）
> 与公共组件（`deptwork.js` + `/api/deptwork`）高度解耦，四角色视图仅保留个性化渲染。候诊列表
> 状态页签互斥单选 + 当日叠加、可见天数跟随开单医生权限；顶栏工具组（叫号排队悬浮窗 / 工具箱
> 患者查询 / ✕ 关闭）常驻固定顶栏。

> 技术要点：严格 PHP 7.x 兼容（未使用任何 PHP 8 新特性）；Nginx 单入口部署；统一业务主库（SQLite/MySQL/PostgreSQL 三驱动，业务 SQL 按方言适配）；关键业务单号/就诊序号唯一索引 + 撞号重试、事务内条件更新防并发竞态；全中文注释，按模块拆分的目录结构，便于维护与二次开发。

---

## ✨ 功能特性

> 按角色工作台与系统能力分组呈现，快速定位各模块核心功能。

### 🎫 挂号收费处
- **快速挂号**：身份证 18 位校验、自动计算年龄、既往登记自动填充、无名氏绿色通道、实时号源显示
- **缴费退费**：挂号缴费凭条打印、退费管理、补打凭条
- **挂号管理**：查询 / 补打 / 修改患者信息

### 🩺 医生工作站
- **所见即所得电子病历**：纸质病历版式；输入框自定义右键菜单（撤销/复制/剪切/粘贴/清空）；嘱托可调用模板（覆盖/续写）
- **多科室切换**：标题栏点击切换（仅多科室权限）；顶栏工具箱（加号 / 切换科室 / 患者查询 / 模板管理）
- **叫号联动**：大屏绑定（会话持久 + 心跳保活）、叫号悬浮窗（当前就诊/下一位/重呼/过号）、过号标记
- **诊断与病历**：ICD-10 诊断联动（搜索→选诊→部位/备注/疑似）、无限续写病历节点（首诊+多段续写，链式拼接）、跨医生诊断引用查重、多医生接诊（1:N，前序只读）
- **开单与文书**：开检验/检查/处置/处方（需先完善病历）、静脉输液子处方、知情同意书（模板→填写→保存→打印）、转科、诊断证明（开具即固化摘要快照）
- **危急值处理**：左右分栏弹窗（患者信息+危急值+完整结果，符合/不符合病情+处理措施），处理自动写入只读「危急值记录」续写文书
- **就诊历史**：左栏搜索列表 + 右栏只读纸面病历

### 💉 护士站
- **工作台布局**：候诊列表「待处置/完成/当日」，点击患者弹出护理记录单页
- **护理记录**：模板模态框添加（覆盖/续写）、只读病历摘要（主诉/现病史/查体/初步诊断 + 完整病历预览）
- **生命体征**：趋势图 + 悬浮窗录入（与医生站双向同步）
- **待办执行**：待处理处置 / 待执行医嘱（医嘱单号、处方号可点击预览）

### 🧪 检验科
- **工作台布局**：候诊列表「检验中/完成/当日」，申请单号可展开项目
- **结果录入**：项目右侧输入框、失焦自动草稿暂存（刷新不丢失）、提交生成横向 A5 检验报告单
- **危急值自动检测**：命中即弹通知流程
- **申请撤回**、检验项目/组合管理（只读，新增走审核）

### 🩻 影像科
- **工作台布局**：候诊列表「检查中/完成/当日」，整张申请单统一登记
- **报告书写**：「去写报告」模态框（影像所见 + 影像诊断，支持模板覆盖/续写），生成 A4 检查报告单（标题按分类动态化如 CT/DR）
- **危急值上报**：弹窗左下角手动报危急值（发布时一并发送）
- **双模式阅片**：经典双屏分屏 / 一体化阅片（深色读片视窗、窗宽窗位、DICOMweb 挂载、历史报告调阅）
- **申请撤回**、检查项目管理（只读，新增走审核）

### 💊 药房
- **工作台布局**：候诊列表「待发药/完成/当日」，处方号可展开药品
- **处方审方**：按处方号整体审方（开单→缴费→审方中→发药/拒绝），处方笺可点击预览
- **库存管理**：药品出入库、低库存预警；药品信息/设置管理（只读，新增走审核）

### 🖨️ 医技报告打印
- 检验报告单**横向 A5** 固定画布（结果行智能分列分页、报告单号自动缩放、检验备注）；检查报告单 **A4 纵向** 画布
- 报告抬头统一急诊病历版式，**快照固化**（申请科室/医生/临床诊断/时间定格，不受后续病历转科影响）

### 🚨 危急值
- **检验科自动检测**：可配置危急值上下限，数值型越界或文本型（如 HIV 阳性）命中即触发，默认通知开单医生
- **趋势纵列**：检验报告单自动显示 ↓/↑ 与红色「危」字
- **影像科手动上报**：写报告时手动录入项目加入预览，发布时一并发送
- **医生左右分栏处理**：符合/不符合病情 + 处理措施，自动写入只读续写文书（诊毕后亦可插入）
- **管理追溯**：医生/检验/影像侧边栏与管理员查询中心均可按时间范围+状态筛选查看处理时长

### 📱 PWA 桌面应用
- 安装为桌面应用（应用名 = 医院名称，图标 = LOGO，未设置时自动生成医疗十字图标）
- Web App Manifest + Service Worker（静态资源缓存优先 + 后台刷新，接口实时直连）

### 📋 模板系统
- 病历模板 / 知情同意书 / 病历嘱托模板 / 护理记录模板 / 影像报告模板
- 个人免审即用，科室/全院提交管理员审核；管理员可管理全部模板
- 报告书写 / 护理记录 / 嘱托字段模板选择统一为「左侧列表 → 右侧预览 → 覆盖/续写」

### 🖨️ 开单与打印
- 开单按医生归档，删除/毁方仅限开单本人（后端硬校验）；打印病历为连续文书（页眉归首诊、续写段虚线承接、各段签名）
- 知情同意/告知文书（标题完全自定义）A5 打印：告知内容可自定义、病情介绍按勾选节固化病历快照、正文连续流式分页、签名区落于末页

### 🖥️ 叫号大屏
- 医生诊室大屏：医生工作站推送 + 回库校验；只叫当天号源、可配置跨天叫号（急诊夜班）
- 多医生并发动态号源队列（被认领即自动离开其他医生号源）、竖屏/横屏自动排版、语音呼叫全名
- 医技四科室叫号：绑定诊室悬浮窗 + 叫号面板（当前处理中/下一位/候诊队列），竖屏/方屏/宽屏三种尺寸
- 提供 `php tools/bin/seed.php --scene=dept_call` 一键为四医技队列各加 N 位待办测试患者

### 🔐 登录安全
- **图形验证码（零依赖 GD）**：off / auto 智能开启 / force 强制三模式
- **防爆破自动锁定**：密码连续错误达阈值自动锁定并通知全体管理员（含来源 IP 与解锁直达链接）；管理员三态徽章 + 一键解锁
- 登录失败 IP+会话频控、验证码即销毁防重放、预检接口防枚举限流

### ⚙️ 管理员
- 首次安装、医院信息/LOGO/时区、科室/用户管理（验证码模式与锁定阈值配置、三态管理与一键解锁）
- 检验/检查/药品/处置/诊断管理（ICD-10 完整标准编码库四级分类树：章→节→类目→亚目，支持编码/名称/拼音检索与高亮定位）
- 审核中心（一键通过/驳回重提/站内消息通知）、组合管理、药品设置、分类管理、统一打印中心
- 医院运营分析（KPI 总览/收入趋势/科室医生统计/自定义维度/转归查询）、查询中心（危急值查询）、叫号大屏管理
- **接口管理**：HIS / 支付 / 医保 / DICOM-PACS / HL7 v2.x / FHIR R4 / 存证·电子签名 分 Tab 统一配置（PACS 含 AETitle、WADO-RS/DICOMweb 与 Web 阅片器 URL 模板 {study_uid} 变量替换）

### 诊毕转归与运营分析
- 诊毕时选择离院方式（自主离院/住院/转院/死亡/其他），非自主离院需填写补充信息（住院病区/接收医院/死亡原因/其他转归），前后端双重校验
- 运营分析含「转归查询」子标签，按类型筛选，支持搜索患者姓名/门诊号/身份证号

### 诊断交互（v2.8+）
- 病历中点击已有诊断 → 编辑悬浮窗（部位/备注/疑似）；首个诊断后显示「＋」快捷入口
- 右栏诊断聚合列表（本人顺序优先），支持跨医生全局排序（设为主诊断/上移/下移）
- 主诊断保护（徽标+不可删除+后端硬拦截）；引用诊断标注「引用」，删除仅删本人副本
- 添加诊断改为悬浮窗（搜索→选诊→填写→保存即时持久化）

### 权限管理（v2.11+）
- 检验科、影像科、药房可查看本职管理页面（只读）；新增/修改提交走管理员审核
- 管理页面列表操作按钮对非管理员隐藏；组合管理/分类管理/删除仅限管理员
- 审核通过后站内消息通知提交者

### 角色首页（v2.12+）
- 医生、护士、检验科、影像科、药房、收费处各有独立首页（KPI 卡片 + 近 7 天趋势图 + 快速入口）
- 如药房首页展示药品总数、今日发药数/金额、待发药处方、低库存药品（红色高亮）、近 7 天发药量趋势

## 👥 系统角色

| 角色 | 说明 | 首页 |
| --- | --- | --- |
| `admin` 管理员 | 系统最高权限，管理医院信息、科室、用户、项目、药品、审核、运营分析等 | 管理员工作台（全站运营概览） |
| `cashier` 挂号收费处 | 挂号、缴费、退费、挂号管理、凭条补打 | 收费处首页（今日挂号/缴费/退费 KPI） |
| `doctor` 医生 | 接诊、电子病历、开单、转科、诊断证明、加号 | 医生首页（今日接诊/开单金额 KPI） |
| `nurse` 护士 | 护士站处置执行、生命体征录入、护理记录 | 护士站首页（今日处置执行 KPI） |
| `lab` 检验科 | 检验登记、结果录入、报告打印；检验管理（只读） | 检验科首页（今日标本量/费用/待办 KPI） |
| `imaging` 影像科 | 检查登记、报告书写、报告打印；检查管理（只读） | 影像科首页（今日检查量/费用/待办 KPI） |
| `pharmacy` 药房 | 处方发药、药品库存管理；药品信息/设置（只读） | 药房首页（药品总数/发药/低库存 KPI） |

> 各角色登录后仅能访问自己的工作台与接口；非管理员可通过管理页面（只读）查看本职数据，新增/修改提交走审核。
> 登录支持 **用户名或工号**；用户名必须以英文字母开头；按全局配置可能要求图形验证码（off/auto/force 三模式，管理员可在【系统设置 → 安全设置】配置），密码连续错误达阈值账号将自动锁定，需管理员在【用户管理】解锁。

## 🛠 技术栈

| 类别 | 技术 |
| --- | --- |
| 后端 | PHP 7.x（PDO 预处理防注入、password_hash 密码哈希） |
| 数据库 | 统一主库 clinic_main · **SQLite/MySQL/PostgreSQL 三驱动一键切换**（`DB_DRIVER`，业务 SQL 按方言自动适配）· ICD-10 独立字典库 · 原生 ACID 事务 · 关键单号唯一索引 |
| 前端 | 原生 HTML + CSS + JavaScript（AJAX 局部刷新 + 模态对话框 + 悬浮面板，无框架） |
| 主题 | base.css（明亮）/ dark.css（夜间）/ 自动模式，按用户保存 |
| 部署 | Nginx（单入口转发 `public/index.php`），`data/`、`app/` 位于 Web 根之外 |

## 📁 目录结构

```
├── public/                    # Web 唯一入口目录（Nginx root 指向这里）
│   ├── index.php              # 单入口：页面路由 + /api/{接口} 分发
│   ├── assets/
│   │   ├── css/               # 样式拆分：base / components / modal / layout / dark / print / auth / landing
│   │   └── js/components/     # 组件拆分：ajax / modal / print / theme / notify / selector /
│   │                          #           validation / datetime / datepicker / order / drugform /
│   │                          #           emreditor / emr_ctxmenu / emr / emr_cert / emr_consent /
│   │                          #           emr_consult / emr_diag / emr_fee / emr_orders / emr_patient /
│   │                          #           emr_rules / emr_segments / emr_template / queuepanel /
│   │                          #           queuepanel_core / historypanel / depttree / deptpicker /
│   │                          #           patient / ui / toast / app / deptwork / doctor_tools /
│   │                          #           vitals / room_heartbeat / eventbus / import / admin_items /
│   │                          #           dropdown / call / chart / screen / critical
│   └── uploads/               # 上传文件：logo/、user/{角色}/——运行时生成，不提交
├── app/                       # 业务代码（Web 无法访问）
│   ├── config/
│   │   ├── bootstrap.php      # 启动引导（常量、Session、时区、类加载、DB_DRIVER 驱动配置）
│   │   ├── options_data.php   # 公共字典（统一数据源）
│   │   └── schema/            # 数据库表结构定义
│   │       ├── main.php       # 统一业务主库 schema（42 张表，SQLite/MySQL/PostgreSQL 三驱动兼容）
│   │       ├── icd10.php      # ICD-10 独立字典库 schema
│   │       └── legacy/        # 旧分散式 schema 归档（供迁移工具引用）
│   ├── core/                  # 核心类
│   │   ├── DatabaseManager.php（getMain/getIcd10 双连接 + 方言辅助 + 运行时列自愈）
│   │   │   Auth.php（登录/会话/角色）LoginSecurity.php（验证码 + IP 频控 + 防爆破）
│   │   │   Session.php CSRF.php Upload.php Router.php IdObfuscator.php（URL 混淆）
│   │   │   EmrContextResolver.php（病历上下文 SSOT）barcode.php（Code128 条形码）
│   │   │   DataExportImport.php emr_rules.php helpers.php（加载 helpers.d/*.php）
│   │   └── helpers.d/         # 辅助函数按域拆分（13 个文件：string/input/upload/idcard/pinyin/settings/work/oid/visit/trend/consult/authz/message）
│   ├── repositories/          # 数据访问层（Repository 数据仓库模式，业务与 SQL 解耦）
│   │   └── BaseRepository.php（通用 CRUD 助手）+ 各业务域仓库：
│   │       Icd10 / Patient / Queue / Cashier / Emr / Drug / Order /
│   │       User / Dept / Analytics / Consultation / Core
│   ├── api/                   # AJAX 接口（按功能拆分，含角色权限校验；不含原生 SQL，
│   │   │                      # 统一调用对应 Repository）
│   │   ├── _init.php          # 接口公共入口（CSRF + 登录 + 角色校验）
│   │   ├── parts/             # 接口按功能拆分（settings/dept/user/item/drug/disp/audit/call；
│   │   │   │                  # record_write/order_write/doctor_read 再按动作拆分到各自子目录）
│   │   ├── auth.php install.php message.php icd10.php patient.php print.php his.php
│   │   ├── admin.php cashier.php doctor.php record.php order.php
│   │   ├── template.php transfer.php nurse.php lab.php imaging.php pharmacy.php
│   │   ├── deptwork.php       # 医技科室工作台共用接口（候诊队列/患者工作台/排队悬浮窗）
│   ├── includes/              # 公共模块
│   │   ├── layout.php         # 统一布局（侧边栏/顶栏/主题/消息铃铛/CSRF/favicon）
│   │   ├── dept_workbench.php # 医技科室工作台公共骨架（护士站/检验/影像/药房）
│   │   ├── forms.php          # 共享表单（检验/检查项目、药品）
│   │   └── print_templates.php# 统一打印模板
│   └── views/                 # 页面视图（按角色/模块分子目录）
├── data/                      # 运行时数据目录（Web 无法访问，首次访问自动创建）
│   ├── db/                    # SQLite 数据库（clinic_main.db 统一主库 + icd10.db 完整标准编码库，纳入版本管理）
│   └── session/               # Session 文件
├── tools/                     # 工具脚本（模块化造数架构，统一 CLI 入口）
│   ├── bin/
│   │   └── seed.php           # 统一造数 CLI：--all / --scene=demo|call|dept_call|doctor2001 / --module=drug
│   ├── seeder/                # 单一职责数据工厂（Seeder 基类 / DrugSeeder 等）
│   ├── scenarios/             # 场景装配器（full / demo / call / dept_call / doctor2001 场景）
│   ├── lint/                  # php-lint.php（tokenizer 语法检查）/ ci-lint.php / jscheck.js
│   ├── schema/                # inspect_schema.php / migrate_split_to_unified.php
│   ├── seed_test_data.php     # 轻量级代理入口（委托 tools/bin/seed.php --all，兼容旧调用）
│   └── refill_drug_spec.php   # 药品规格结构化填充
├── .github/workflows/         # GitHub Actions：PHP 7.2~8.5 语法兼容矩阵检查 + 检查报告
├── docs/                      # 文档归档
│   ├── CHANGELOG.md           # 系统变更日志
│   └── nginx.conf.example     # Nginx 配置示例
└── router.php                 # 本地开发路由（php -S）
```

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

浏览器访问 `http://localhost:8080`，首次访问自动进入安装页。

> 语法检查可运行 `npm run lint`（内部用 `tools/lint/php-lint.php` 通过 tokenizer 校验全部 PHP 文件，无需系统 php）；
> `npm run dev` / `npm run start` 默认端口 8000，可用 `PORT` 环境变量覆盖。

### 🧪 快速初始化与测试造数（统一 CLI）

安装完成并启动后，可通过统一造数 CLI 一键生成测试/演示数据（模块化架构，历史脚本已转为代理入口）：

```bash
# 全量测试造数（默认）：科室/账号/检验/检查/处置/104 种药品/模板/套餐 + 近 15 天患者就诊全链路
php tools/bin/seed.php --all
# Demo 演示环境数据（近 30 天 136 次就诊 / 病历 / 医嘱 / 体征 / 证明）
php tools/bin/seed.php --scene=demo
# 叫号大屏专项：为指定科室生成当天已缴费患者
php tools/bin/seed.php --scene=call
# 医技四科室叫号专项：检验/检查/处方/护理处置各加 N 位待办患者
php tools/bin/seed.php --scene=dept_call
# 医生 2001（张伟）接诊专项
php tools/bin/seed.php --scene=doctor2001
# 仅重置药品与库存（104 种药品最小单位库存 / 警戒库存 / 护士执行标识）
php tools/bin/seed.php --module=drug
```

本机无系统 php 时统一加前缀：`~/.local/bin/frankenphp php-cli tools/bin/seed.php ...`。
历史脚本 `tools/seed_test_data.php` 保留为轻量级代理入口（内部委托 `--all`），推荐直接使用统一 CLI。

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

## 📜 更新日志

详见 [docs/CHANGELOG.md](./docs/CHANGELOG.md)。

## 📄 许可

本项目仅供学习与内部使用。数据库、病历等医疗数据请遵守当地法律法规妥善保管。
