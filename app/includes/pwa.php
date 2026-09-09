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
            array('src' => '/pwa-icon.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
            array('src' => '/pwa-icon.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'),
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
    // 默认图标：GD 生成 512x512（主色 #2563eb 圆角 + 白色医疗十字）
    $size = 512;
    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor($size, $size);
        $bg = imagecolorallocate($img, 37, 99, 235);
        imagefilledrectangle($img, 0, 0, $size, $size, $bg);
        // 圆角遮罩（近似：四个角画背景色圆形，营造圆角感）
        $rad = 64;
        for ($i = 0; $i < 4; $i++) {
            $cx = ($i % 2 === 0) ? $rad : $size - $rad;
            $cy = ($i < 2) ? $rad : $size - $rad;
            for ($y = 0; $y <= $rad; $y++) {
                for ($x = 0; $x <= $rad; $x++) {
                    if (($x - $rad) * ($x - $rad) + ($y - $rad) * ($y - $rad) > $rad * $rad) {
                        $px = $i % 2 === 0 ? $x : $size - 1 - $x;
                        $py = $i < 2 ? $y : $size - 1 - $y;
                        imagesetpixel($img, $px, $py, $bg);
                    }
                }
            }
        }
        // 白色医疗十字（中央）
        $white = imagecolorallocate($img, 255, 255, 255);
        $barW = 128; $barH = 208;
        imagefilledrectangle($img, (int)(($size - $barW) / 2), (int)(($size - $barH) / 2), (int)(($size + $barW) / 2), (int)(($size + $barH) / 2), $white);
        imagefilledrectangle($img, (int)(($size - $barH) / 2), (int)(($size - $barW) / 2), (int)(($size + $barH) / 2), (int)(($size + $barW) / 2), $white);
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
