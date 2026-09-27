# 📗 使用帮助（HELP）

模拟 Web PACS 影像浏览器 · 独立 PHP 网站

## 一、快速开始

1. **启动服务**（本机使用 FrankenPHP，Web 根指向 `public/`）：

   ```bash
   ~/.local/bin/frankenphp php-server --root public --listen 0.0.0.0:8090
   ```

2. **打开登录页**：`http://localhost:8090/`。
3. **登录**：默认 `admin / admin123`（管理员）或 `doctor / doctor123`（普通）。
4. **检索**：输入姓名 / 患者号 / 检查号 / 门诊号 / 检查项目，回车或点【检索】。
5. **调阅**：点击结果卡片进入阅片器。

> 首次访问会在 `data/` 自动生成 `pacs_viewer.db` 与 `session/`，均为运行时数据，
> 不纳入版本管理。

## 二、管理设置（DICOM / PACS 接口配置）

管理员登录后进入【管理设置】，分为四个页签：

### 基础设置
- `站点名称`、`医院名称`（接口未返回机构名时的兜底展示）；
- `默认窗宽 WW` / `默认窗位 WL`。

### DICOM / PACS 接口
| 字段 | 说明 |
| --- | --- |
| 查询模式 | `内置模拟数据`（本地演示）或 `远程 PACS / DICOMWeb 接口` |
| PACS 接口地址 | 远程接口基址，如 `http://192.168.1.100:8042/dicom-web/gateway` |
| 接口密钥 | 可选，作为 `key` 参数随请求发送 |
| 超时（秒） | 远程请求超时 |
| 本系统 AETitle | 本工具侧 AETitle（如 `CLINIC_OPD`） |
| 目标 PACS AETitle | 远程 PACS 侧 AETitle |
| PACS 主机 / DICOM 端口 | DICOM 元数据（如 `192.168.1.100` / `104`） |

点击【测试接口】调用远程 `ping`，成功显示接口名称 / 版本 / 模式。

### 账号管理
新增账号（普通 / 管理员）、启停账号、重置密码。不能停用当前登录账号。

### 检索日志
记录每次检索的账号、关键词、结果数与 IP，可一键清空。

## 三、远程 PACS 接口约定

```
GET {endpoint}?action=search&q=关键词&key=APIKEY
    → {"code":200,"msg":"ok","data":{"list":[
        {"study_uid":"...","patient_id":"...","name":"...","gender":"男",
         "age":"45岁","outpatient_no":"...","accession_no":"...",
         "modality":"CT","description":"胸部CT平扫","study_date":"2026-09-27 10:00:00",
         "institution":"...","station_name":"...","series_count":3}, ...]}}

GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
    → {"code":200,"data":{
        "patient":{...,"patient_id","name","gender","age","outpatient_no"},
        "study":{"accession_no","study_uid","modality","description","study_date",
                 "institution","station_name","slice_thickness"},
        "series":[{"series_id","description","orientation","slice_count",
                   "is_mock","slice_thickness","pixel_spacing","seed","images":[]}]}}

GET {endpoint}?action=ping&key=APIKEY
    → {"code":200,"data":{"name":"...","version":"..."}}
```

- `series[].is_mock=true` 时由前端算法生成仿真切片；`images` 为空数组。
- `series[].images` 若给出图片 URL，前端将加载真实图像。
- 接口返回 `code!=200` 时前端展示其 `msg`。

## 四、键鼠快捷交互速查表

| 操作 | 效果 |
| --- | --- |
| 滚轮直接滚动（CT/MR 多帧） | 断层切片逐帧平滑翻页（Cine） |
| 按住鼠标右键拖拽 | 无级调节窗宽 WW（水平）/ 窗位 WL（垂直） |
| 按住鼠标中键拖拽 | 自由平移画布 |
| `Ctrl + 滚轮` 或【缩放工具】 | 以鼠标指针为中心无级放大 / 缩小 |
| 左键依次点击两点 / 三点 | 测距（mm）/ 测角（°） |
| 左键拖拽框选 | 矩形 / 椭圆 ROI（面积 mm² + 平均灰度值） |
| 【预设窗】 | 软组织窗 400/40 · 肺窗 1500/-600 · 骨窗 2000/350 · 默认窗 2500/250 |
| 【左旋 / 右旋 / 镜像H / 镜像V / 反色】 | 几何变换与正负片 |
| 【序列栏】 | 显示 / 隐藏左侧序列栏 |
| 【清屏】/【适应窗口】/【1:1 原图】 | 清除标注 / 复位视图 |

## 五、部署与集成

- **独立部署**：Web 根指向 `public/`；Nginx 示例（`root …/public; index index.php;`）：

  ```nginx
  server {
      listen 80;
      server_name pacs.local;
      root /path/to/pacs-viewer/public;
      index index.php;
      location / { try_files $uri $uri/ /index.php?$query_string; }
      location ~ \.php$ {
          include fastcgi_params;
          fastcgi_pass 127.0.0.1:9000;
          fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      }
  }
  ```

- **集成到门诊一体化主项目**：主项目以 `git subtree` 将本仓库挂载在
  `tools/pacs_viewer/`，此时可通过 `http://<主项目>/tools/pacs_viewer/` 访问
  （入口路径自适应，无需改代码）。更新同步命令见根目录 `README.md`。

## 六、常见问题

- **检索报「无法连接 PACS 接口」**：检查接口地址与网络可达性；本地演示请把
  查询模式切回「内置模拟数据」。
- **登录后空白**：确认 `data/` 目录可写（用于建库与会话）。
- **端口冲突**：更换 `--listen` 端口即可。
- **忘记管理员密码**：删除 `data/pacs_viewer.db` 会重置为默认账号与设置
  （仅本项目数据，不影响任何宿主系统）。
