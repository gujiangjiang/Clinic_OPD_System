<?php
/**
 * ============================================================
 * includes/pwa.php — PWA 清单与图标输出
 * ============================================================
 * 说明：由 public/index.php 对 /manifest.webmanifest 与 /pwa-icon.png
 * 直接路由到本文件（exit），不做页面布局包裹。
 * 1. manifest：应用名 = 医院名称（无则默认），图标指向 /pwa-icon.png
 *    （192/512 双规格，浏览器按需缩放），display=standalone 桌面安装。
 * 2. icon：优先输出医院 LOGO 文件（读取 settings.logo 相对路径，realpath
 *    防目录穿越）；无 LOGO 时用 GD 生成默认图标（主色圆角底 + 白色医疗十字），
 *    GD 缺失时降级输出 1x1 透明 PNG。
 * ============================================================ */

$__pwaUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/* ==================== 应用清单 ==================== */
if ($__pwaUri === '/manifest.webmanifest') {
    $hosp = setting('hospital_name', '门诊一体化系统');
    $short = $hosp !== '' ? $hosp : '门诊一体化系统';
    if (mb_strlen($short) > 12) {
        $short = mb_substr($short, 0, 12);
    }
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode(array(
        'name' => $hosp,
        'short_name' => $short,
        'description' => '门诊一体化信息系统（挂号收费 / 医生工作站 / 检验 / 影像 / 药房）',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#ffffff',
        'theme_color' => '#2563eb',
        'lang' => 'zh-CN',
        'icons' => array(
            // ?v= 版本参数：图标内容更新后浏览器/Service Worker 缓存自动失效
            array('src' => '/pwa-icon.png?v=' . APP_VERSION, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
            array('src' => '/pwa-icon.png?v=' . APP_VERSION, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'),
        ),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==================== 图标 ==================== */
if ($__pwaUri === '/pwa-icon.png') {
    // 优先输出医院 LOGO 文件（与 img_data 同源的路径安全校验）
    $logo = setting('logo', '');
    $base = realpath(APP_ROOT . '/public');
    if ($logo !== '') {
        $lp = ltrim((string)$logo, '/');
        if (strpos($lp, '..') === false) {
            $file = realpath(APP_ROOT . '/public/' . $lp);
            if ($base && $file && strpos($file, $base . DIRECTORY_SEPARATOR) === 0) {
                $info = @getimagesize($file);
                if ($info && !empty($info['mime']) && strpos($info['mime'], 'image/') === 0) {
                    header('Content-Type: ' . $info['mime']);
                    header('Cache-Control: public, max-age=86400');
                    header('Content-Length: ' . (string)filesize($file));
                    readfile($file);
                    exit;
                }
            }
        }
    }
    // 默认图标：透明底 + 白色圆形（浅灰描边）+ 红色医疗十字。
    // 2x（1024）绘制后重采样到 512：平滑圆形/十字边缘，圆外区域全透明。
    $size = 512;
    if (function_exists('imagecreatetruecolor')) {
        $big = imagecreatetruecolor(1024, 1024);
        // 全透明底：用「白色全透明」填充（而非黑色），避免重采样时圆形边缘出现黑晕
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefilledrectangle($big, 0, 0, 1023, 1023, imagecolorallocatealpha($big, 255, 255, 255, 127));
        imagealphablending($big, true);
        // 浅灰外环：浅色浏览器标签栏中勾勒圆形轮廓（深色背景下白圆本就清晰）
        imagefilledellipse($big, 512, 512, 940, 940, imagecolorallocate($big, 209, 213, 219));
        // 白色圆形底
        imagefilledellipse($big, 512, 512, 900, 900, imagecolorallocate($big, 255, 255, 255));
        // 红色医疗十字（居中）
        $red = imagecolorallocate($big, 220, 38, 38);
        $half = 280; $thick = 95;
        imagefilledrectangle($big, 512 - $half, 512 - $thick, 512 + $half, 512 + $thick, $red);
        imagefilledrectangle($big, 512 - $thick, 512 - $half, 512 + $thick, 512 + $half, $red);
        // 重采样到 512 输出（保留透明通道）
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagecopyresampled($img, $big, 0, 0, 0, 0, $size, $size, 1024, 1024);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        imagepng($img);
        exit;
    }
    // GD 缺失降级：1x1 透明 PNG
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    exit;
}

http_response_code(404);
echo 'Not Found';
exit;
