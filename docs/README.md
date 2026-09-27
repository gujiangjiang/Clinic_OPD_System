# 模拟 Web PACS 影像浏览器

> 一个**完全独立**的轻量级 PHP 网站，用于 DICOM / PACS 接口联调测试。
> 拥有自己的代码库、数据库、账号与文档体系，与门诊一体化主系统零耦合。

## 简介

本组件用于在**没有真实 PACS 硬件**的环境下，验证 DICOM / PACS 接口的检索、
调阅与影像展示链路。所有患者、检查、医院名称等数据均通过 PACS 接口获取；
未配置远程接口时，由内置模拟 PACS 服务返回「已开单、已缴费、已登记并完成检查」
的确定性仿真数据。

## 功能

- 独立登录（用户名 / 密码，自带 SQLite 账号库）。
- **研究检索**：按姓名 / 患者号 / 检查号 / 门诊号 / 检查项目检索（数据来自 PACS 接口）。
- **影像阅片器**：
  - 窗宽窗位（WW/WL）拖拽调节 + 预设（软组织窗 / 肺窗 / 骨窗 / 默认窗）；
  - 左侧序列栏（常驻，可由工具栏一键显隐）、多序列切换；
  - 滚轮连续翻帧（CT/MR Cine）、以指针为中心缩放、平移；
  - 顺时针 / 逆时针旋转 90°、水平 / 垂直镜像、正负片反色；
  - 线段测距（mm）、三点测角（°）、矩形 / 椭圆 ROI（mm² + 平均灰度）；
  - 四角医学水印 OSD（不随平移缩放位移）。
- **管理设置**：站点 / 医院信息、DICOM/PACS 接口配置、接口连通性测试、
  账号管理、检索日志。
- 无真实图像时由算法确定性生成仿真切片（同一检查花纹恒定）。

## 目录结构

```
tools/pacs_viewer/
├── index.php                 # 目录默认入口（/tools/pacs_viewer/ 命中）
├── public/                   # Web 根（标准站点结构）
│   ├── index.php             #   唯一前端控制器（?r= 路由）
│   └── assets/
│       ├── css/              #   base / auth / search / viewer / admin
│       └── js/
│           ├── api.js        #   外部接口请求封装
│           ├── search.js     #   检索页交互
│           ├── admin.js      #   管理页交互
│           ├── viewer.js     #   阅片器主控制器
│           └── modules/      #   render(虚拟影像) / osd(水印) / sidebar(序列栏)
│                             #   / toolbar(工具栏) / measurements(测量)
├── app/                      # 后端
│   ├── bootstrap.php         #   引导（路径自适应 / 会话 / 助手）
│   ├── Database.php          #   自带 SQLite（建库建表播种）
│   ├── Auth.php              #   独立登录认证
│   ├── Settings.php          #   管理设置读写
│   ├── Pacs/                 #   PacsClient(远程接口) + DemoPacs(内置模拟)
│   ├── Services/             #   StudyService(检查数据聚合)
│   ├── Controllers/          #   认证 / 检索 / 阅片 / 管理 / JSON 接口
│   └── Repositories/         #   账号 / 检索日志
├── views/                    # 页面模板（auth / search / viewer / admin / error）
├── docs/                     # 本组件文档（README / CHANGELOG / HELP）
└── data/                     # 运行时：pacs_viewer.db + session（自动生成，不提交）
```

## 启动

本机无系统 PHP，统一使用 FrankenPHP。入口已做部署路径自适应：

```bash
# 方式 A：独立站点（Web 根指向本组件的 public）
~/.local/bin/frankenphp php-server --root tools/pacs_viewer/public --listen 0.0.0.0:8090
# 浏览器访问 http://localhost:8090/

# 方式 B：挂载于项目根下（得到 /tools/pacs_viewer/ 路径）
~/.local/bin/frankenphp php-server --root /path/to/Clinic_OPD_System --listen 0.0.0.0:8080
# 浏览器访问 http://localhost:8080/tools/pacs_viewer/
```

首次访问自动创建 `data/pacs_viewer.db` 并播种账号与设置。

## 默认账号

| 用户名 | 密码 | 角色 |
| --- | --- | --- |
| `admin` | `admin123` | 管理员（可进入管理设置） |
| `doctor` | `doctor123` | 普通用户 |

## PACS 接口约定

在【管理设置 → DICOM / PACS 接口】选择「远程 PACS / DICOMWeb 接口」后，
检索与调阅数据全部来自所配置地址：

```
GET {endpoint}?action=search&q=关键词&key=APIKEY
    → {"code":200,"data":{"list":[{study_uid,patient_id,name,gender,age,
        outpatient_no,accession_no,modality,description,study_date,
        institution,station_name,series_count}, ...]}}

GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
    → {"code":200,"data":{patient:{...}, study:{...}, series:[...]}}

GET {endpoint}?action=ping&key=APIKEY
    → {"code":200,"data":{name,version}}
```

管理页【测试接口】按钮即调用 `ping`。留空 / 选择内置模式时使用模拟数据。

## 更多文档

- [更新日志 CHANGELOG](./CHANGELOG.md)
- [使用帮助 HELP](./HELP.md)
