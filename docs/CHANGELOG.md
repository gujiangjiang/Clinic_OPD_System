# 更新日志（CHANGELOG）

本项目的所有重要变更都会记录在此文件中，便于回溯每个版本的改动。

> 格式说明（参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的简化版）：
> - **新增**（Added）：新增的功能
> - **修复**（Fixed）：修复的缺陷
> - **变更**（Changed）：行为 / 界面的调整
> - **移除**（Removed）：删除的功能
> - **安全**（Security）：安全相关修复
>
> 版本号遵循 `主版本.次版本.修订号`。本组件为独立项目，版本号与门诊一体化
> 主系统**完全隔离**，从 `0.1.0` 起独立计算。

---

## [0.1.0] - 2026-09-27

> 首个版本：从门诊一体化项目 `tools/` 中独立出来的模拟 Web PACS 影像浏览器。

### 新增
- **独立站点骨架**：标准 PHP 网站结构（`public/` 为 Web 根，`app/` 后端、
  `views/` 模板、`assets/` 前端资源、`data/` 运行时数据、`docs/` 文档），
  入口支持「独立站点根」与「挂载于项目 `tools/` 下」两种部署路径自适应。
- **自带数据库与账号体系**：`data/pacs_viewer.db`（SQLite）首次访问自动建库，
  内置 `users` / `settings` / `query_log` 三表；独立登录（`password_hash` 校验、
  会话隔离、CSRF 防护）。
- **PACS 接口客户端**：`app/Pacs/PacsClient.php` 支持远程 `search` / `study` /
  `ping` 三个动作（`{action,q,uid,key}`），`app/Pacs/DemoPacs.php` 提供内置
  确定性模拟数据（已开单 / 已缴费 / 已登记 / 已完成检查）。
- **研究检索**：按姓名 / 患者号 / 检查号 / 门诊号 / 检查项目检索，结果卡片
  展示患者、检查与设备信息，点击进入阅片；检索动作写入日志。
- **阅片器**：模块化前端（`render` 虚拟影像生成 / `osd` 四角水印 /
  `sidebar` 序列栏 / `toolbar` 工具栏 / `measurements` 测量）——窗宽窗位与预设、
  多序列切换、滚轮连续翻帧、指针中心缩放、平移、旋转 / 镜像 / 反色、
  线段测距、三点测角、矩形 / 椭圆 ROI（面积 + 平均灰度）、清屏、适应窗口、1:1。
- **管理设置**：站点 / 医院信息、DICOM/PACS 接口配置（查询模式、接口地址、
  密钥、AETitle、超时、默认窗宽窗位）、接口连通性测试、账号管理（新增 / 启停 /
  重置密码）、检索日志查看与清空。
- **帮助文档**：本组件自带 `README` / `CHANGELOG` / `HELP` 三份文档。

### 安全
- 登录 `password_hash` / `password_verify`；会话名独立（`PACSVIEWSID`），
  会话文件存放于组件自有 `data/session/`；全部写操作校验 CSRF 令牌。
