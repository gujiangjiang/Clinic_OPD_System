# 🏥 简易门诊一体化系统（Clinic OPD System）

一套基于 **PHP 7.x + SQLite + 原生 JS/CSS** 的自包含门诊一体化信息系统，**无 Composer、无第三方框架**。

![版本](https://img.shields.io/badge/版本-v4.0.23-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite%2F预留MySQL-003B57) ![部署](https://img.shields.io/badge/部署-Nginx-009639) ![代码](https://img.shields.io/badge/代码-全中文注释-orange)

覆盖 **挂号收费处、护士站、医生工作站、影像科、检验科、药房、管理员** 等多角色完整业务闭环：
挂号 → 缴费 → 接诊 → 电子病历 → 开单（检验/检查/处置/处方）→ 执行 → 报告 → 发药 → 诊毕（含离院转归）→ 运营分析。

支持 **明亮 / 夜间 / 自动** 三种主题、**侧边栏展开/缩小两态切换**、**站内消息互发与打印提醒**、**统一打印中心**。

> 技术要点：严格 PHP 7.x 兼容（未使用任何 PHP 8 新特性）；Nginx 单入口部署；SQLite 分散式数据库（预留 MySQL 切换接口）；全中文注释，按模块拆分的目录结构，便于维护与二次开发。

---

## ✨ 功能特性

| 模块 | 功能 |
| --- | --- |
| 🎫 挂号收费处 | 身份证 18 位校验、自动计算年龄、既往登记自动填充、快速挂号无名氏绿色通道、实时号源显示、挂号缴费凭条打印、挂号管理（查询/补打/修改患者信息）、缴费退费管理 |
| 🩺 医生工作站 | 多科室切换、所见即所得电子病历（纸质病历版式，所见即所得）、生命体征编辑（与护士站同步）、ICD‑10 诊断联动（搜索→选诊→部位/备注/疑似）、病历模板管理（个人/科室/全院，审核流）、无限续写病历节点（首诊+多段续写，链式拼接、只读/编辑自动切换）、续写必填校验、开检验/检查/处置/处方（需先完善病历，保存后立即生效）、静脉输液子处方、转科、诊断证明、多医生接诊（1:N 续写，前序只读，段落签名互不篡改）、跨医生诊断引用查重、医生加号、就诊历史（左右两栏：左列表搜索+右只读纸面病历）、诊室叫号屏幕 |
| 💉 护士站 | 患者搜索、处置执行、生命体征录入（双向同步）、护理记录、待执行医嘱 |
| 🧪 检验科 | 检验登记、结果录入（正常范围 + 危急值）、报告生成与打印、申请撤回；检验项目/组合管理（只读，新增走审核） |
| 🩻 影像科 | 检查登记、影像所见与结论、报告打印、申请撤回；检查项目管理（只读，新增走审核） |
| 💊 药房 | 发药队列、库存管理（入库/出库/流水/低库存预警）、药品出入库；药品信息/设置管理（只读，新增走审核） |
| 🖨️ 开单与打印 | 开单项目按开单医生归档；删除/毁方仅限开单本人（后端硬校验）；打印病历为连续文书（页眉归首诊、续写段虚线承接、各段签名、页脚时间固定）；诊断证明开具即固化病历摘要快照，不随续写变化 |
| ⚙️ 管理员 | 首次安装、医院信息/LOGO/时区、科室/用户管理、检验/检查/药品/处置/诊断管理、审核中心（一键通过/驳回重提/预览提交内容/站内消息通知）、组合管理（多对多关联表）、药品设置、检验/检查/药品分类管理、统一打印中心、医院运营分析（KPI 总览/收入趋势/科室医生统计/自定义维度/转归查询）、叫号大屏管理、HIS 预留接口 |

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
> 登录支持 **用户名或工号**；用户名必须以英文字母开头。

## 🛠 技术栈

| 类别 | 技术 |
| --- | --- |
| 后端 | PHP 7.x（PDO 预处理防注入、password_hash 密码哈希） |
| 数据库 | SQLite（分散式：每模块独立 .db，统一 DatabaseManager 建库/迁移）· 预留 MySQL 接口 |
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
│   │                          #           validation / datetime / order / editor / emr / queuepanel /
│   │                          #           historypanel / depttree / patient / ui / toast / app
│   └── uploads/               # 上传文件：logo/、user/{角色}/——运行时生成，不提交
├── app/                       # 业务代码（Web 无法访问）
│   ├── config/
│   │   ├── bootstrap.php      # 启动引导（常量、Session、时区、类加载）
│   │   ├── options_data.php   # 公共字典（统一数据源）
│   │   └── schema/            # 分散式数据库表结构 + 自动迁移 + 种子数据（001~012）
│   ├── core/                  # 核心类
│   │   └── DatabaseManager.php Auth.php Session.php CSRF.php Upload.php Router.php helpers.php
│   ├── api/                   # AJAX 接口（按功能拆分，含角色权限校验）
│   │   ├── _init.php          # 接口公共入口（CSRF + 登录 + 角色校验）
│   │   ├── parts/             # 管理端接口按功能拆分（settings/dept/user/item/drug/disp/audit/call）
│   │   ├── auth.php install.php message.php icd10.php patient.php print.php his.php
│   │   ├── admin.php cashier.php doctor.php record.php order.php
│   │   ├── template.php transfer.php nurse.php lab.php imaging.php pharmacy.php
│   ├── includes/              # 公共模块
│   │   ├── layout.php         # 统一布局（侧边栏/顶栏/主题/消息铃铛/CSRF/favicon）
│   │   ├── forms.php          # 共享表单（检验/检查项目、药品）
│   │   └── print_templates.php# 统一打印模板
│   └── views/                 # 页面视图（按角色/模块分子目录）
├── data/                      # 运行时数据目录（Web 无法访问，首次访问自动创建）
│   ├── db/                    # 分散式 SQLite 数据库
│   └── session/               # Session 文件
├── tools/                     # 工具脚本
│   ├── seed_demo_data.php     # 演示数据生成器（近30天136次就诊/277份病历/250医嘱单/205体征/6证明）
│   └── php-lint.php           # PHP 语法检查（tokenizer，无需系统 php）
├── nginx.conf.example         # Nginx 配置示例
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

> 语法检查可运行 `npm run lint`（内部用 `tools/php-lint.php` 通过 tokenizer 校验全部 PHP 文件，无需系统 php）；
> `npm run dev` / `npm run start` 默认端口 8000，可用 `PORT` 环境变量覆盖。

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

完整示例见 `nginx.conf.example`。

## 📖 使用流程（快速体验）

1. 访问首页 → 安装页设置管理员密码、医院名称 → 完成安装。
2. 用 `admin` 登录 → 【科室管理】添加科室 → 【用户管理】创建各角色账号（医生勾选关联科室）→ 添加检验/检查/药品/处置项目并在【审核中心】通过审核。
3. 挂号收费处挂号 → 缴费 → 凭条打印。
4. 医生工作站接诊 → 书写病历（主诉/现病史/诊断必填并保存）→ 开检验/检查/处置/处方（缴费后各科室可见）。
   同一次挂号可由多位医生接诊（续写），开单项目跟随医生归档，删除/毁方仅限开单本人。
5. 检验科/影像科登记 → 录入结果 → 报告生成；药房发药；护士站执行处置与生命体征。
6. 诊毕 → 选择离院方式（自主离院/住院/转院/死亡/其他）→ 运营分析可查询转归。

> 首次登录系统会提醒修改默认密码；全站时区默认取创建管理员时的浏览器时区，管理员可在【系统设置】中修改。

## 🔌 切换 MySQL（预留接口）

1. 修改 `app/config/bootstrap.php`：`DB_DRIVER` 改为 `'mysql'`，填写 `MYSQL_HOST/PORT/DB_PREFIX/USER/PASS`。
2. 预先创建各分散库：`his_core / his_user / his_dept / his_patient / his_order / his_drug / his_medical / his_nurse / his_lab / his_disp / his_icd10`。
3. 将 `app/config/schema/*.php` 建表语句中的 `AUTOINCREMENT` 改为 `AUTO_INCREMENT`。
4. 业务查询代码（`DB::q/one/val/exec/insert`）无需改动。

## 🔌 HIS 预留接口

系统内置只读 HIS 对接接口（`/api/his`），为未来扩展住院 HIS、医保、BI 等系统提供数据支持。

在【系统设置】中配置「HIS 预留接口密钥」（留空则接口关闭）。外部系统携带密钥调用：

```bash
curl "http://your-domain/api/his?api_key=你的密钥&action=patient_get&id_card=110101199001011234"
curl -H "X-HIS-Key: 你的密钥" "http://your-domain/api/his?action=visit_status&flow_no=2503110001"
```

| action | 参数 | 说明 |
| --- | --- | --- |
| `patient_get` | `id_card` 或 `patient_no` | 查询患者档案 |
| `visit_list` | `patient_no` | 该患者全部就诊记录 |
| `visit_status` | `flow_no` | 查询某次就诊状态 |
| `order_list` | `visit_id` | 某次就诊的开单明细 |

## 🔗 URL 混淆密钥

系统对就诊、申请单、报告等患者级实体 ID 做全链路混淆加密，
链接中不再出现可遍历的自增数字，例如：
`/doctor/emr?visit_id=CSDUJCYGhFyM_LGRzEu3LA`

- 密钥由系统首次使用时自动生成，管理员可在【系统设置 → URL 安全混淆密钥】中查看、复制或一键重置；
- **重置后所有旧链接立即失效**，系统功能不受影响；
- 输入侧统一 `did()` 解码，明文数字 ID 一律按「记录不存在」拒绝。

## 🔒 安全说明

- CSRF 令牌校验所有 POST 请求；PDO 预处理语句防 SQL 注入；`password_hash/verify` 密码哈希。
- **业务实体 ID 全链路混淆加密**（见上方「URL 混淆密钥」），防 URL 撞库遍历他人医疗数据。
- 输出统一 `e()` 转义防 XSS；Session Cookie HttpOnly + SameSite；登录重置会话 ID。
- 角色级页面/接口权限（无关角色无法直接访问其他科室功能）；非管理员管理页面只读，新增走审核。
- 上传类型/大小校验 + 随机文件名；LOGO base64 内联显示，封禁 `/uploads/logo/` 直链。
- `data/` 与 `app/` 位于 Web 根目录（public）之外，不可直接访问。
- 管理员首次登录提示修改默认密码。

## 🧩 开发约定

- **单文件小、职责单一**：PHP / JS / CSS 文件按功能拆分，管理端接口已拆分到 `app/api/parts/`，项目/药品表单统一收敛到 `app/includes/forms.php`。
- **公共数据统一存放**：性别、民族、职业、职称、频次、途径等字典统一维护在 `app/config/options_data.php`。
- **样式按主题拆分**：明亮 / 夜间 / 自动模式分别维护。
- **数据库分散 + 统一管理**：新增模块时在 `app/config/schema/` 中新建迁移文件，`DatabaseManager` 自动建库与增量迁移。
- **接口与页面分离**：业务逻辑写入 `app/api/`，页面通过 AJAX 局部刷新调用。

## 📜 更新日志

详见 [CHANGELOG.md](./CHANGELOG.md)。

## 📄 许可

本项目仅供学习与内部使用。数据库、病历等医疗数据请遵守当地法律法规妥善保管。
