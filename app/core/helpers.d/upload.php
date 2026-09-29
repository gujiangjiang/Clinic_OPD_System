<?php
/**
 * ============================================================
 * helpers.d/upload.php — 上传文件引用输出
 * ============================================================
 * 说明：uploads/ 相对路径的安全 URL / base64 Data URI 输出。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/* ============================================================
 * 上传文件引用（uploads/ 相对路径的安全输出）
 * 说明：Upload::save 返回相对 public 的路径（如 uploads/logo/x.png）。
 * 若直接输出到 src/href，浏览器按当前页面路径解析：
 * /login 页解析为 /uploads/... 正常，而 /admin/dashboard 页会解析成
 * /admin/uploads/... 导致 404。统一经 img_data() 输出 base64 Data URI：
 * ============================================================ */

/**
 * 图片转 base64 Data URI 内联显示（不暴露文件 URL）
 * 适用：医院 LOGO 等需要隐藏真实路径的图片；favicon 同样适用。
 * 安全：realpath 规范化后必须仍位于 public 目录内（防目录穿越）；
 * 文件不存在/非有效图片时返回 ''，调用方据此决定是否渲染 <img>。
 */
function img_data($path) {
    $path = ltrim((string)$path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    $base = realpath(APP_ROOT . '/public');
    $file = realpath(APP_ROOT . '/public/' . $path);
    if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    $info = @getimagesize($file);
    if (!$info || empty($info['mime'])) {
        return '';
    }
    $bin = @file_get_contents($file);
    if ($bin === false || strlen($bin) > 2097152) {
        return '';
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
}


/**
 * 未设置医院 LOGO 时的默认 LOGO 图标（<img>）。
 * 说明：复用浏览器标签页 / PWA 图标路由 /pwa-icon.png——该路由有 LOGO 时输出医院
 * LOGO、无 LOGO 时输出「透明底 + 白圆 + 红十字」默认图，与标签页/PWA 图标完全一致，
 * 比内联 SVG 医院图形更接近成品图标。
 * GD 扩展缺失（该路由降级为 1x1 透明图）时回退内联 SVG 医院图标，避免占位空白。
 * @param string $class <img> 追加样式类（空串则不输出 class）
 * @return string
 */
function default_logo_img($class = '') {
    if (!function_exists('imagecreatetruecolor')) {
        return render_icon('nav:hospital', array('size' => 32, 'class' => $class));
    }
    return '<img src="/pwa-icon.png?v=' . e(APP_VERSION) . '" alt="LOGO"' .
        (trim((string)$class) !== '' ? ' class="' . e($class) . '"' : '') . '>';
}
