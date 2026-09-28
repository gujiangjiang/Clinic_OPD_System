<?php
/**
 * ============================================================
 * icons/IconHelper.php — 图标库核心门面与调度引擎
 * ============================================================
 * 说明：
 * 1. 统一注册各分类图标（Nav/Clinical/Emr/Queue/Action/Alert）。
 * 2. render($name, $attrs) 输出内联 SVG：viewBox="0 0 24 24"、
 *    stroke="currentColor"、fill="none"、stroke-width="2"、
 *    stroke-linecap/linejoin="round"。
 * 3. 属性支持：size（宽高，默认 16）、class（追加样式类）、
 *    color（内联 color 覆盖）。
 * 4. allSvg() 输出 name → SVG 全量映射（供前端 JS 注入使用）。
 * 5. 注册全局 render_icon() 便捷函数（function_exists 防御）。
 * ============================================================ */
require_once __DIR__ . '/NavIcons.php';
require_once __DIR__ . '/ClinicalIcons.php';
require_once __DIR__ . '/EmrIcons.php';
require_once __DIR__ . '/QueueIcons.php';
require_once __DIR__ . '/ActionIcons.php';
require_once __DIR__ . '/AlertIcons.php';

class IconHelper {

    /** 分类注册表（唯一数据源） */
    private static $classes = array('NavIcons', 'ClinicalIcons', 'EmrIcons', 'QueueIcons', 'ActionIcons', 'AlertIcons');

    /** 图标注册缓存：name(含前缀) => 内部元素字符串 */
    private static $registry = null;

    /** 默认 SVG 属性（遵循统一矢量规范） */
    private static $defAttrs = array(
        'viewBox'       => '0 0 24 24',
        'fill'          => 'none',
        'stroke'        => 'currentColor',
        'stroke-width'  => '2',
        'stroke-linecap'  => 'round',
        'stroke-linejoin' => 'round',
        'aria-hidden'   => 'true',
        'focusable'     => 'false',
    );

    /** 构建图标注册表（惰性加载） */
    private static function registry() {
        if (self::$registry !== null) return self::$registry;
        self::$registry = array();
        foreach (self::$classes as $cls) {
            $icons = call_user_func(array($cls, 'icons'));
            if (is_array($icons)) {
                foreach ($icons as $k => $inner) {
                    self::$registry[(string)$k] = (string)$inner;
                }
            }
        }
        return self::$registry;
    }

    /**
     * 按名称查找图标定义（支持带前缀 nav:hospital 或纯名 hospital）。
     * @param string $name 图标名
     * @return array|null array(规范名, 内部元素字符串)
     */
    public static function get($name) {
        $name = (string)$name;
        if ($name === '') return null;
        $reg = self::registry();
        if (isset($reg[$name])) return array($name, $reg[$name]);
        // 纯名回退：遍历含前缀定义做短名匹配（首段前缀匹配优先）
        $short = strpos($name, ':') !== false ? substr($name, strpos($name, ':') + 1) : $name;
        foreach ($reg as $k => $inner) {
            $ks = substr($k, strpos($k, ':') + 1);
            if ($ks === $short) return array($k, $inner);
        }
        return null;
    }

    /** 图标是否存在 */
    public static function has($name) {
        return self::get($name) !== null;
    }

    /**
     * 渲染内联 SVG 图标。
     * @param string $name  图标名（如 clinical:temperature / nav:hospital）
     * @param array  $attrs 支持 size（数值/字符串）、class（追加类）、color（内联色）
     * @return string SVG 标记；图标不存在返回空串
     */
    public static function render($name, $attrs = array()) {
        $def = self::get($name);
        if (!$def) return '';
        $attrs = is_array($attrs) ? $attrs : array();
        // 尺寸：数值按 px 处理
        $size = isset($attrs['size']) ? $attrs['size'] : 16;
        $size = is_numeric($size) ? (int)$size . 'px' : (string)$size;
        // 样式类：默认类 + 自定义类
        $class = 'opd-svg-icon';
        if (isset($attrs['class']) && trim((string)$attrs['class']) !== '') {
            $class .= ' ' . trim((string)$attrs['class']);
        }
        // 颜色：内联 style 覆盖
        $style = '';
        if (isset($attrs['color']) && trim((string)$attrs['color']) !== '') {
            $style = ' style="color:' . e(trim((string)$attrs['color'])) . '"';
        }
        $out = '<svg class="' . e($class) . '" width="' . e($size) . '" height="' . e($size) . '"';
        foreach (self::$defAttrs as $ak => $av) {
            $out .= ' ' . $ak . '="' . e($av) . '"';
        }
        $out .= $style . '>' . $def[1] . '</svg>';
        return $out;
    }

    /**
     * 全部图标 → SVG 标记映射（前端 JS 注入：window.OPD_ICON_SVGS）。
     * @param int $size 默认渲染尺寸
     * @return array name => svg
     */
    public static function allSvg($size = 16) {
        $out = array();
        foreach (self::registry() as $k => $inner) {
            $out[$k] = self::render($k, array('size' => $size));
        }
        return $out;
    }

    /** 图标总数 */
    public static function count() {
        return count(self::registry());
    }
}

/* ---------- 全局便捷函数（防御性定义） ---------- */
if (!function_exists('render_icon')) {
    /**
     * 渲染图标（全局便捷函数）。
     * @param string $name  图标名
     * @param array  $attrs 属性（size/class/color）
     * @return string
     */
    function render_icon($name, $attrs = array()) {
        return IconHelper::render($name, $attrs);
    }
}