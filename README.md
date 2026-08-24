# 🏥 简易门诊一体化系统（Clinic OPD System）

一套基于 **PHP 7.x + SQLite + 原生 JS/CSS** 的自包含门诊一体化信息系统，**无 Composer、无第三方框架**。

![版本](https://img.shields.io/badge/版本-v2.6.6-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite%2F预留MySQL-003B57) ![部署](https://img.shields.io/badge/部署-Nginx-009639) ![代码](https://img.shields.io/badge/代码-全中文注释-orange)

覆盖 **挂号收费处、护士站、医生工作站、影像科、检验科、药房** 等多角色业务闭环：
挂号 → 缴费 → 接诊 → 电子病历 → 开单（检验/检查/处置/处方）→ 执行 → 报告 → 发药 → 诊毕。
支持 **明亮 / 夜间 / 自动** 三种主题（跟随用户保存）、**侧边栏展开 / 缩小两态切换**（缩小仅保留图标窄条，偏好跟随用户保存，病历书写页强制缩小以提供书写空间）、**站内消息 + 打印提醒**（区分患者 / 系统消息，患者消息显示姓名并可点击直达对应病历）、**统一打印中心**（挂号 / 缴费凭条为竖向小票格式，条形码由纯 PHP 生成 Code 128 SVG，零第三方依赖）。

> 技术要点：严格 PHP 7.x 兼容（未使用任何 PHP 8 新特性）；Nginx 单入口部署；SQLite 分散式数据库（预留 MySQL 切换接口）；全中文注释，按模块拆分的目录结构，便于维护与二次开发。

---

## ✨ 功能特性

| 模块 | 功能 |
| --- | --- |
| 🎫 挂号收费处 | 身份证 18 位校验、自动计算并锁定出生日期/性别（年龄按 EMR 全年龄段规范格式化：X小时/X天/X月/X岁Y月/X岁）、既往登记自动填充、姓名/性别/出生日期必填（出生日期日历组件选择）、快速挂号无名氏绿色通道（自动命名/年龄估算推算生日/仅限0元科室）、通用科室选择弹窗（急诊/门诊分 Tab，实时显示剩余号源与挂号费）、今日号源静态总览、挂号缴费、凭条打印、挂号管理（按天查询/补打/患者信息修改）、缴费退费管理 |
| 🩺 医生工作站 | 多科室切换、所见即所得电子病历（纸质病历版式：医院抬头 / 标题栏 / 患者信息两栏 / 病历内容 / 签名，打印与屏幕一致）、生命体征紧凑显示 + 弹窗编辑（与护士站同步）、ICD-10 诊断联动、病历模板、开检验/检查/处置/处方（需先完善并保存病历，保存后立即生效）、静脉输液子处方、转科一键引用、诊断证明（含就诊历史内查看/补开）、医生加号（仅限号科室）、就诊历史（查看病历为预览/打印页，未保存时提示）、诊室叫号屏幕（跟随医生端选择） |
| 👨‍⚕️ 多医生接诊 | 同一次挂号支持多位医生各自独立文书（1:N）：首诊 `initial` / 续写 `progress` 按时间正序接续，前序病历只读展示、谁书写谁签名；页眉（患者信息/初复诊）公共可交互，仅病历主体按文书隔离；主诊断各文书独立（取本医生诊断第 1 项，互不篡改）；跨医生诊断引用查重（引用后可改部位/备注/疑似）；既往史/过敏史全局同步（任何医生保存后跨就诊实时生效）；续写文书顶部必填「病历续写」并支持「病史同上」快捷填入 |
| 💉 护士站 | 患者搜索（ID/身份证/流水号）、护士站处置执行、生命体征录入（与医生站双向同步）、护理记录、当日医嘱查看、待执行医嘱（护士站执行处方） |
| 🧪 检验科 | 检验登记、结果录入（正常范围 + 危急值提示）、报告生成与打印、申请撤回、提交新增检验项目（需审核） |
| 🩻 影像科 | 检查登记、影像所见 + 结论、报告打印、申请撤回、提交新增检查项目（需审核） |
| 💊 药房 | 发药队列（待发药/发药完成）、库存管理（入库/出库 + 库存流水 + 低库存预警）、新增药品/分类（需审核） |
| 🖨️ 开单与打印 | 开单项目跟随开单医生归档（病历正文仅呈现本人开具的检验/检查/处置/处方）；删除/毁方仅限开单医生本人（后端硬拦截）；打印病历为一份连续文书：页眉归首诊，续写段以虚线 + 「日期时间 · 续写病历 · 科室」承接，各段医生签名紧跟正文右下角，页脚记录时间固定为首诊首次保存时间；诊断证明开具即固化病历摘要快照（主诉/现病史/初步诊断），查看与补打不随后续续写变化 |
| ⚙️ 管理员 | 首次安装、医院信息/LOGO/favicon/时区/页脚、科室管理、用户管理、检验检查项目、药品信息与药品设置、处置项目、诊断管理（ICD10）、审核中心（一键全部通过 / 驳回理由 / 提交者可回填重提，管理员添加的项目免审核）、统一打印中心、医院运营分析（KPI 总览 / 收入与人次趋势点线图 / 科室与医生业绩统计 / 自定义维度指标统计）、叫号大屏管理（语音/脱敏/温馨提示轮播配置）、HIS 预留接口 |

## 👥 系统角色

| 角色 | 说明 |
| --- | --- |
| `admin` 管理员 | 系统最高权限，首次安装时创建（用户名固定 `admin`），管理医院信息、科室、用户、项目、药品、审核等 |
| `cashier` 挂号收费处 | 挂号、缴费、退费、挂号管理、凭条补打 |
| `doctor` 医生 | 接诊、电子病历、开检验/检查/处置/处方、转科、诊断证明、加号 |
| `nurse` 护士 | 护士站处置执行、生命体征录入、护理记录 |
| `lab` 检验科 | 检验登记、结果录入、报告打印 |
| `imaging` 影像科 | 检查登记、报告书写、报告打印 |
| `pharmacy` 药房 | 处方发药、药品库存管理 |

> 各角色登录后仅能访问自己的工作台与接口，无关角色无法通过直接输入 URL 访问其他科室的数据和功能。
> 登录支持 **用户名或工号**；用户名必须以英文字母开头（不允许纯数字或数字开头），避免与工号混淆。

## 🛠 技术栈

| 类别 | 技术 |
| --- | --- |
| 后端 | PHP 7.x（PDO 预处理防注入、password_hash 密码哈希） |
| 数据库 | SQLite（分散式：每模块独立 .db，统一 DatabaseManager 建库/迁移）· 预留 MySQL 接口 |
| 前端 | 原生 HTML + CSS + JavaScript（AJAX 局部刷新 + 模态对话框，无框架） |
| 主题 | base.css（明亮）/ dark.css（夜间）/ 自动模式，按用户保存 |
| 部署 | Nginx（单入口转发 `public/index.php`），`data/`、`app/` 位于 Web 根之外 |

## 📁 目录结构

```
├── public/                    # Web 唯一入口目录（Nginx root 指向这里）
│   ├── index.php              # 单入口：页面路由 + /api/{接口} 分发
│   ├── assets/
│   │   ├── css/               # 样式拆分：base / components / modal / layout / dark / print / auth / landing
│   │   └── js/components/     # 组件拆分：ajax / modal / print / theme / notify / selector /
│   │                          #           validation / datetime / order / editor / emr / patient / ui / toast / app
│   └── uploads/               # 上传文件：logo/（医院LOGO，禁止直链，页面内 base64 内联）、user/{角色}/（用户照片）——运行时生成，不提交
├── app/                       # 业务代码（Web 无法访问）
│   ├── config/
│   │   ├── bootstrap.php      # 启动引导（常量、Session、时区、类加载）
│   │   ├── options_data.php   # 公共字典：性别/民族/职业/职称/频次/途径…（统一数据源）
│   │   └── schema/            # 分散式数据库表结构 + 自动迁移 + 种子数据（001~011）
│   ├── core/                  # 核心类
│   │   └── DatabaseManager.php# 统一数据库管理：建库/建表/迁移/MySQL 预留接口
│   │       Auth.php  Session.php  CSRF.php  Upload.php  Router.php  helpers.php
│   ├── api/                   # AJAX 接口（按功能拆分，含角色权限校验）
│   │   ├── _init.php          # 接口公共入口（CSRF + 登录 + 角色校验）
│   │   ├── parts/             # 管理端接口按功能拆分（settings/dept/user/item/drug/disp/audit）
│   │   ├── auth.php  install.php  message.php  icd10.php  patient.php  print.php  his.php
│   │   ├── admin.php  cashier.php  doctor.php  record.php  order.php
│   │   ├── template.php  transfer.php  nurse.php  lab.php  imaging.php  pharmacy.php
│   ├── includes/              # 公共模块
│   │   ├── layout.php         # 统一布局（侧边栏/顶栏/主题/消息铃铛/CSRF/favicon）
│   │   ├── forms.php          # 共享表单（检验/检查项目、药品，管理端与各科室复用）
│   │   └── print_templates.php# 统一打印模板（凭条/缴费/申请单/处方/报告/病历/证明）
│   └── views/                 # 页面视图（按角色/模块分子目录，含医生叫号屏 doctor/call.php、诊断管理 admin/diagnosis.php）
├── data/                      # 运行时数据目录（Web 无法访问，首次访问自动创建）
│   ├── db/                    # 分散式 SQLite 数据库（core/user/dept/patient/order/drug/medical/nurse/lab/disp/icd10）
│   └── session/               # Session 文件
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

> 借助 FrankenPHP 时，语法检查可运行 `npm run lint`（内部用 `tools/php-lint.php`
> 通过 tokenizer 校验全部 PHP 文件，无需系统 php）；`npm run dev` / `npm run start`
> 默认端口 8000，可用 `PORT` 环境变量覆盖。

### 生产部署（Nginx）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project/public;          # Web 根目录指向 public
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;   # 所有请求转发单入口
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;        # PHP-FPM（7.x）
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 禁止访问业务目录（app/data 位于 public 之外，天然安全；此处双保险）
    location ~ ^/(app|data)/ { deny all; }
}
```

完整示例见 `nginx.conf.example`。

## 📖 使用流程（快速体验）

1. 访问首页 → 进入安装页：设置管理员密码（用户名固定 `admin`）、医院名称（时区自动取浏览器时区）→ 完成安装。
2. 使用 `admin` 登录 → 【科室管理】添加科室（门诊需设上下午号源）→ 【用户管理】创建医生/护士/检验/影像/药房账号
   （医生勾选关联科室）→ 添加检验/检查/药品/处置项目并在【审核中心】通过审核。
3. 挂号收费处挂号 → 缴费 → 自动打印挂号凭条。
4. 医生工作站接诊 → 书写病历（主诉/现病史/初步诊断必填并保存）→ 开检验/检查/处置/处方（缴费后各科室可见，病历未保存时前端与后端双重拦截）。
   同一次挂号可由多位医生接诊：后接医生在上方只读查阅前序病历、在下方续写自己的文书（诊断与主诊断互相隔离），开单项目跟随医生归档，删除/毁方仅限开单本人。
5. 检验科/影像科登记 → 录入结果 → 报告自动生成并打印；药房发药；护士站执行处置与生命体征。

> 首次登录系统会提醒修改默认密码；全站时区默认取创建管理员时的浏览器时区，管理员可在【系统设置】中修改。

## 🔌 切换 MySQL（预留接口）

1. 修改 `app/config/bootstrap.php`：`DB_DRIVER` 改为 `'mysql'`，填写 `MYSQL_HOST/PORT/DB_PREFIX/USER/PASS`。
2. 预先创建各分散库：`his_core / his_user / his_dept / his_patient / his_order / his_drug / his_medical / his_nurse / his_lab / his_disp / his_icd10`。
3. 将 `app/config/schema/*.php` 建表语句中的 `AUTOINCREMENT` 改为 `AUTO_INCREMENT`
   （其余 SQL 与查询代码保持兼容；迁移机制在 MySQL 下使用 `ALTER TABLE` 增量执行）。
4. 业务查询代码（`DB::q/one/val/exec/insert`）无需改动。

## 🔌 HIS 预留接口（需求23）

系统内置只读 HIS 对接接口（`/api/his`），为未来扩展住院 HIS、医保、BI 等系统提供数据支持：

1. 在【系统设置】中配置「HIS 预留接口密钥」（留空则接口关闭）。
2. 外部系统携带密钥调用（二选一）：

```bash
# GET 参数方式
curl "http://your-domain/api/his?api_key=你的密钥&action=patient_get&id_card=110101199001011234"

# 请求头方式
curl -H "X-HIS-Key: 你的密钥" "http://your-domain/api/his?action=visit_status&flow_no=2503110001"
```

| action | 参数 | 说明 |
| --- | --- | --- |
| `patient_get` | `id_card` 或 `patient_no` | 查询患者档案 |
| `visit_list` | `patient_no` | 该患者全部就诊记录 |
| `visit_status` | `flow_no` | 查询某次就诊状态（含当前科室/序号/状态） |
| `order_list` | `visit_id` | 某次就诊的开单明细（检验/检查/处置/处方） |

> 接口均为只读、统一 JSON 返回格式 `{ ok, msg, data }`，不依赖登录会话。

## 🔗 URL 混淆密钥（防链接撞库）

系统对就诊、申请单、报告、缴费等患者级实体 ID 做全链路混淆加密，
链接中不再出现可遍历的自增数字，例如：
`/doctor/emr?visit_id=CSDUJCYGhFyM_LGRzEu3LA`

- 密钥由系统首次使用时自动生成，管理员可在【系统设置 → URL 安全混淆密钥】
  中查看、复制或**一键重置**；
- **重置后所有旧链接立即失效**（含打印凭据、分享的病历入口），
  系统功能不受影响，新链接按新密钥即时生成；
- 输入侧统一 `did()` 解码，明文数字 ID 一律按「记录不存在」拒绝，不可降级绕过；
- 科室/字典类管理参数与 HIS 外部接口（api_key 认证）不在此范围内。

## 🔒 安全说明

- CSRF 令牌校验所有 POST 请求；PDO 预处理语句防 SQL 注入；`password_hash/verify` 密码哈希。
- **业务实体 ID 全链路混淆加密**（见上方「URL 混淆密钥」），防 URL 撞库遍历他人医疗数据。
- 输出统一 `e()` 转义防 XSS；Session Cookie HttpOnly + SameSite；登录重置会话 ID。
- 角色级页面/接口权限（无关角色无法直接访问其他科室功能）；上传类型/大小校验 + 随机文件名。
- 医院 LOGO 以 base64 Data URI 内联显示（不暴露文件 URL），并封禁 `/uploads/logo/` 直链访问；
  上传文件统一以根绝对路径引用，避免多级路径页面解析错误。
- `data/` 与 `app/` 位于 Web 根目录（public）之外，不可直接访问。
- 管理员首次登录提示修改默认密码；站点时区默认取创建管理员时的浏览器时区。

## 🧩 开发约定（维护指南）

- **单文件小、职责单一**：PHP / JS / CSS 文件按功能拆分，能拆则拆，方便单独更新某个文件而不影响整体。
  （v1.1.0 起管理端接口已拆分到 `app/api/parts/`，项目/药品表单统一收敛到 `app/includes/forms.php` 供各科室复用）
- **公共数据统一存放**：性别、民族、职业、职称、频次、途径等字典统一维护在 `app/config/options_data.php`，
  页面按需调用，避免重复代码；ICD10 数据量巨大，独立存放于 `icd10` 数据库。
- **样式按主题拆分**：明亮 / 夜间 / 自动模式分别维护（`base.css` / `dark.css` 等），不混在一个文件里。
- **数据库分散 + 统一管理**：新增模块时在 `app/config/schema/` 中新建迁移文件（按序号递增），
  `DatabaseManager` 会自动建库并执行增量迁移，兼容旧库升级。
- **接口与页面分离**：业务逻辑写入 `app/api/` 对应文件，页面通过 AJAX 局部刷新调用，不做整页刷新。

## 📜 更新日志

详见 [CHANGELOG.md](./CHANGELOG.md)。

## 📄 许可

本项目仅供学习与内部使用。数据库、病历等医疗数据请遵守当地法律法规妥善保管。
