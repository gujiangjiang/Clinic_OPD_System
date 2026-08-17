# 🏥 简易门诊一体化系统（Clinic OPD System）

一套基于 **PHP 7.x + SQLite + 原生 JS/CSS** 的自包含门诊一体化信息系统，**无 Composer、无第三方框架**。

![版本](https://img.shields.io/badge/版本-v1.0.0-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite%2F预留MySQL-003B57) ![部署](https://img.shields.io/badge/部署-Nginx-009639) ![代码](https://img.shields.io/badge/代码-全中文注释-orange)

覆盖 **挂号收费处、护士站、医生工作站、影像科、检验科、药房** 等多角色业务闭环：
挂号 → 缴费 → 接诊 → 电子病历 → 开单（检验/检查/处置/处方）→ 执行 → 报告 → 发药 → 诊毕。
支持 **明亮 / 夜间 / 自动** 三种主题（跟随用户保存）、**站内消息 + 打印提醒**、**统一打印中心**。

> 技术要点：严格 PHP 7.x 兼容（未使用任何 PHP 8 新特性）；Nginx 单入口部署；SQLite 分散式数据库（预留 MySQL 切换接口）；全中文注释，按模块拆分的目录结构，便于维护与二次开发。

---

## ✨ 功能特性

| 模块 | 功能 |
| --- | --- |
| 🎫 挂号收费处 | 身份证 18 位校验、自动计算并锁定出生日期/年龄/性别、既往登记自动填充、号源实时展示、挂号缴费、凭条打印、挂号管理（按天查询/补打/患者信息修改）、缴费退费管理 |
| 🩺 医生工作站 | 多科室切换、WYSIWYG 电子病历、ICD-10 诊断联动、病历模板、开检验/检查/处置/处方、静脉输液子处方、转科一键引用、诊断证明、医生加号、就诊历史 |
| 💉 护士站 | 护士站处置执行、生命体征录入（与医生站双向同步）、护理记录 |
| 🧪 检验科 | 检验登记、结果录入（正常范围 + 危急值提示）、报告生成与打印、申请撤回 |
| 🩻 影像科 | 检查登记、影像所见 + 结论、报告打印、申请撤回 |
| 💊 药房 | 发药队列、库存管理（入库/出库 + 库存流水 + 低库存预警） |
| ⚙️ 管理员 | 首次安装、医院信息/LOGO/favicon/时区/页脚、科室管理、用户管理、检验检查项目、药品信息与药品设置、处置项目、审核中心、统一打印中心 |

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
│   └── uploads/               # 上传文件：logo/（医院LOGO）、user/{角色}/（用户照片）——运行时生成，不提交
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
│   │   ├── auth.php  install.php  message.php  icd10.php  patient.php  print.php
│   │   ├── admin.php  cashier.php  doctor.php  record.php  order.php
│   │   ├── template.php  transfer.php  nurse.php  lab.php  imaging.php  pharmacy.php
│   ├── includes/              # 公共模块
│   │   ├── layout.php         # 统一布局（侧边栏/顶栏/主题/消息铃铛/CSRF/favicon）
│   │   └── print_templates.php# 统一打印模板（凭条/缴费/申请单/处方/报告/病历/证明）
│   └── views/                 # 页面视图（按角色/模块分子目录）
├── data/                      # 运行时数据目录（Web 无法访问，首次访问自动创建）
│   ├── db/                    # 分散式 SQLite 数据库（core/user/dept/patient/order/drug/medical/nurse/lab/disp/icd10）
│   └── session/               # Session 文件
├── nginx.conf.example         # Nginx 配置示例
└── router.php                 # 本地开发路由（php -S）
```

## 🚀 快速开始

### 环境要求

- PHP ≥ 7.0（建议 7.2 ~ 7.4，未使用 PHP 8 新特性）
- SQLite 扩展（PHP 默认内置）
- 可选：Nginx + PHP-FPM（生产环境）

### 本地开发预览

```bash
# 项目根目录执行（public 为唯一 Web 根目录，router.php 负责静态资源与路由）
php -S 0.0.0.0:8080 router.php
```

浏览器访问 `http://localhost:8080`，首次访问自动进入安装页。

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
4. 医生工作站接诊 → 书写病历 → 开检验/检查/处置/处方（缴费后各科室可见）。
5. 检验科/影像科登记 → 录入结果 → 报告自动生成并打印；药房发药；护士站执行处置与生命体征。

> 首次登录系统会提醒修改默认密码；全站时区默认取创建管理员时的浏览器时区，管理员可在【系统设置】中修改。

## 🔌 切换 MySQL（预留接口）

1. 修改 `app/config/bootstrap.php`：`DB_DRIVER` 改为 `'mysql'`，填写 `MYSQL_HOST/PORT/DB_PREFIX/USER/PASS`。
2. 预先创建各分散库：`his_core / his_user / his_dept / his_patient / his_order / his_drug / his_medical / his_nurse / his_lab / his_disp / his_icd10`。
3. 将 `app/config/schema/*.php` 建表语句中的 `AUTOINCREMENT` 改为 `AUTO_INCREMENT`
   （其余 SQL 与查询代码保持兼容；迁移机制在 MySQL 下使用 `ALTER TABLE` 增量执行）。
4. 业务查询代码（`DB::q/one/val/exec/insert`）无需改动。

## 🔒 安全说明

- CSRF 令牌校验所有 POST 请求；PDO 预处理语句防 SQL 注入；`password_hash/verify` 密码哈希。
- 输出统一 `e()` 转义防 XSS；Session Cookie HttpOnly + SameSite；登录重置会话 ID。
- 角色级页面/接口权限（无关角色无法直接访问其他科室功能）；上传类型/大小校验 + 随机文件名。
- `data/` 与 `app/` 位于 Web 根目录（public）之外，不可直接访问。
- 管理员首次登录提示修改默认密码；站点时区默认取创建管理员时的浏览器时区。

## 🧩 开发约定（维护指南）

- **单文件小、职责单一**：PHP / JS / CSS 文件按功能拆分，能拆则拆，方便单独更新某个文件而不影响整体。
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
