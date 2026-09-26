# 🏥 简易门诊一体化系统（Clinic OPD System）

一套基于 **PHP 7.x + SQLite + 原生 JS/CSS** 的自包含门诊一体化信息系统，**无 Composer、无第三方框架**。

![版本](https://img.shields.io/badge/版本-v8.35.0-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite%2FMySQL双驱动-003B57) ![部署](https://img.shields.io/badge/部署-Nginx-009639) ![代码](https://img.shields.io/badge/代码-全中文注释-orange)

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


> **基础设施与数据库中心（v8.20+）**：基础设施配置独立存放于 
> （驱动/连接凭证/缓存/App Key，Magic Header 校验 + 损坏自动备份降级）；驱动体系
> （数据库 SQLite/MySQL/PostgreSQL、缓存 File/APCu/Redis/Memcached）统一注册在
>  唯一数据源，安装向导与系统设置动态共用；数据库中心提供
> 连接状态/数据表浏览（CSV 导出）/后台迁移（全站锁定+进度条+管理员取消+成功确认
> 切换）/直接切换主库/多库备份（定时备份 + 双向实时同步）能力。
## ✨ 功能特性

> 按角色工作台与系统能力分组呈现，快速定位各模块核心功能。

### 🎫 挂号收费处
- **快速挂号**：身份证 18 位校验、自动计算年龄、既往登记自动填充、无名氏绿色通道、实时号源显示
- **缴费退费**：挂号缴费凭条打印、退费管理、补打凭条
- **挂号管理**：查询 / 补打 / 修改患者信息

### 🩺 医生工作站
- **所见即所得电子病历**：纸质病历版式；全部文本录入区为流式行内编辑器（长文按字符级自然折行、Tab 连续跳格并高亮当前焦点、除嘱托外回车自动跳下一字段且粘贴自动格式化去空行、时间字段限纯数字）；输入框自定义右键菜单（撤销/复制/剪切/粘贴/清空/全选）；嘱托可调用模板（覆盖/续写）
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
- 审核中心（一键通过/驳回重提/站内消息通知/申请时间日期范围筛选）、组合管理、药品设置、分类管理、统一打印中心（登记时间日期范围筛选）
- 医院运营分析（KPI 总览/收入趋势/科室医生统计/自定义维度/转归查询）、查询中心（危急值查询/影像引用查询）、叫号大屏管理
- **日期范围搜索与跨度钳制**：打印中心/审核中心/危急值/影像引用/患者查询/运营分析均支持日期范围筛选，跨度按业务差异化钳制（危急值 1 个月/打印中心 3 个月/影像引用 6 个月/审核中心与患者查询 1 年/运营分析 1 年），前后端双重防护（超限自动调整并提示）
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
│   │   └── seed.php           # 统一造数 CLI：--all / --scene=visit|call|dept_call|doctor=工号|dept=科室|类型 / --module=clinic|dept|user|screen|drug|lab|exam|disposal|package|template
│   ├── seeder/                # 单一职责数据工厂（Seeder 基类 / DeptSeeder / UserSeeder / DrugSeeder / LabSeeder（含检验组合）/ ExamSeeder / DisposalSeeder / PackageSeeder / TemplateSeeder / VisitSeeder（就诊链）/ QueueSeeder（叫号队列）/ VisitFlowEngine / PreflightChecker）
│   ├── lint/                  # php-lint.php（tokenizer 语法检查）/ ci-lint.php / jscheck.js
│   └── schema/                # 分散迁移与数据修复（inspect_schema.php / migrate_split_to_unified.php / fix_icd10_split.php / refill_drug_spec.php）
├── .github/workflows/         # GitHub Actions：PHP 7.2~8.5 语法兼容矩阵检查 + 检查报告
├── docs/                      # 文档归档
│   ├── CHANGELOG.md           # 系统变更日志
│   └── nginx.conf.example     # Nginx 配置示例
└── router.php                 # 本地开发路由（php -S）
```


## 📖 使用帮助

> 搭建环境、本地预览、初始化造数、生产部署、数据库切换/迁移/备份、
> Session 存储、HIS 接口、安全配置等操作指导，请查看
> [📗 使用帮助（HELP.md）](./docs/HELP.md)。

## 📜 更新日志

详见 [docs/CHANGELOG.md](./docs/CHANGELOG.md)。

## 📄 许可

本项目仅供学习与内部使用。数据库、病历等医疗数据请遵守当地法律法规妥善保管。
