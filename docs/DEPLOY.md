# 部署指南（宝塔面板 / Nginx / Apache）

本系统为「`public/` 为 Web 根 + 单入口 `public/index.php`」架构：

- **Web 服务器根目录必须指向项目的 `public/`**（`app/`、`data/` 位于其外，天然不可访问）；
- 采用**路径式路由**（`/install`、`/login`、`/admin/...` 等），Nginx 必须把所有
  不存在的路径**兜底转发**到 `public/index.php`，否则 `/install` 等请求会被当静态文件找 → 404；
- 未完成安装时全站强制跳转 `/install`（安装向导 6 步）；安装完成后 `/install` 自动失效跳回首页。

完整 Nginx 参考配置（含 Session tmpfs / Redis 高性能部署建议）见 `docs/nginx.conf.example`，
使用帮助见 `docs/HELP.md`「生产部署」。

## 一、Nginx 站点配置

```nginx
server {
    listen 80;
    server_name clinic.example.com;

    root /path/to/Clinic_OPD_System/public;   # 必须指向 public 目录
    index index.php;

    charset utf-8;
    client_max_body_size 10m;                 # 医院 LOGO / 用户照片上传

    # 主路由：所有路径兜底到唯一入口
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM（按实际监听地址修改）
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    # 禁止直链医院 LOGO（页面内以 base64 内联显示，可选加固）
    location ~ ^/uploads/logo/ {
        deny all;
    }
}
```

## 二、宝塔面板（BT Panel）部署步骤

1. **创建站点**：网站 → 添加站点，PHP 版本选择 **7.4 / 8.0 / 8.2**
   （代码严格 PHP 7.x 兼容，CI 覆盖 PHP 7.2~8.5；需开启 `pdo_sqlite` + `sqlite3`，
   宝塔默认已开启）。域名 / 端口按实际填写。
   - **禁用函数放行 `popen`（后台任务需要）**：宝塔 PHP 默认将 `popen` 列入
     「禁用函数」，定时自动备份 / 病历超时归档 / 出向同步等后台任务将无法派生
     （系统会优雅降级并记录日志，页面不受影响，但任务不会执行）。
     如需后台任务：软件商店 → PHP 设置 → 禁用函数 → 移除 `popen` → 保存
     （面板会自动重载 FPM）。
2. **站点根目录**：两种方式任选其一——
   - 手动编辑站点配置，把 `root` 指向 `.../public`（推荐，与示例一致）；或
   - 网站目录保持项目根，用宝塔「**运行目录**」设置为 `/public`，
     这样面板的 `open_basedir` 会自动覆盖整个项目目录。
3. **伪静态**（关键）：网站 → 设置 → 伪静态，填入：

   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```

   也可直接写入 `/www/server/panel/vhost/rewrite/<站点名>.conf`。
4. **注释 404 错误页**：宝塔默认配置里的 `error_page 404 /404.html;` 指向
   `public/404.html`——该文件不存在，会在错误日志刷
   `open() ".../public/404.html" failed`。进入 网站 → 设置 → 配置文件，
   把 `error_page 404 /404.html;` 注释掉（应用自身会输出错误页）。
5. **关闭防跨站攻击（open_basedir）**：网站 → 设置 → 网站目录 →
   关闭「防跨站攻击」。PHP 需要读写 `public/` **上一级**的 `app/`、`data/`
   （SQLite 配置库与业务库、会话、日志），open_basedir 仅限 `public` 会导致
   500 / 白屏。如不想关闭，请确保其路径包含项目根目录。
6. **目录权限**：宝塔 PHP 以 `www` 用户运行，确保项目目录属主与写权限：

   ```bash
   chown -R www:www /www/wwwroot/Clinic_OPD_System
   # data/（数据库/会话/日志）与 public/uploads/（LOGO/照片）需可写
   ```

7. **上传大小**：如 LOGO / 用户照片上传失败，确认 `client_max_body_size 10m;`
   （Nginx 配置）与 PHP `upload_max_filesize` / `post_max_size` 一致。
8. **访问安装向导**：浏览器打开 `http://<地址>/`（未安装自动跳转 `/install`），
   按向导完成 6 步安装（数据库可选 SQLite / MySQL / PostgreSQL）。

### 可选：Session / 性能

- 默认 `files` 会话驱动写入 `data/session/`，单机开箱即用；
- 单机高并发推荐 tmpfs 内存盘挂载 Session 目录；
- 多机集群可切换 Redis / Memcached 会话驱动（环境变量 `SESSION_DRIVER`），
  详见 `docs/nginx.conf.example` 顶部注释与 `docs/HELP.md`。

## 三、Apache 配置（宝塔 Apache 环境同理）

站点根目录指向 `public/`，伪静态填入：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ /index.php [L,QSA]
```

或使用 httpd 2.4 的 `FallbackResource /index.php`（放在站点 Directory 中）。
确认站点 `AllowOverride All`（宝塔默认开启）。

## 四、常见问题排查

| 现象 | 原因 | 处理 |
| --- | --- | --- |
| 访问 `/install` 等路径 404，错误日志出现 `open() ".../public/install" failed` | 缺少主路由兜底 | 按上文添加 `location / try_files` 伪静态 |
| 错误日志出现 `open() ".../public/404.html" failed` | 宝塔默认 404 页指向不存在的文件 | 注释 `error_page 404 /404.html;` |
| 500 / 白屏，日志出现 `open_basedir restriction in effect` | 防跨站攻击限制在 `public` | 关闭「防跨站攻击」或将其路径改为项目根 |
| 安装向导可打开但初始化失败 | `data/` 不可写 | `chown -R www:www` 项目目录 |
| 后台任务（自动备份 / 病历归档 / 出向同步）不执行，`data/logs/app.log` 提示 `popen() 不可用` | 宝塔 PHP 禁用函数含 `popen` | 软件商店 → PHP 设置 → 禁用函数移除 `popen` 保存；系统本身会优雅降级不报错 |
| LOGO / 照片上传失败 | 上传限制过小 | 调整 `client_max_body_size` 与 PHP 上传参数 |
| 大屏 / 叫号页并发卡顿 | Session 独占锁排队 | 确认已启用 `Session::closeReadOnly()`（内置）；高并发按上文优化 Session 存储 |
