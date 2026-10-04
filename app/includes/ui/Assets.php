<?php
/**
 * ============================================================
 * Assets.php — 前端资源集中登记表（唯一数据源）
 * ============================================================
 * 说明：全站公共 CSS/JS、各页面额外资源与 Service Worker 预缓存清单统一
 * 登记于此，layout.php 据此生成 <link>/<script> 标签。
 *
 * 约定（见 AGENTS.md）：新增任何前端模块（css/js）必须在 Assets 中登记，
 * 禁止在 layout.php / 各视图中硬编码资源路径，确保：
 *   1. 资源版本参数统一（默认按文件 mtime 防缓存）；
 *   2. Service Worker 预缓存清单与页面实际加载保持一致；
 *   3. 页面按需加载，避免全站脚本体积膨胀。
 * ============================================================ */
class Assets {

    /** 全站公共 CSS（按级联顺序） */
    const CSS_CORE = array(
        'assets/css/base.css',
        'assets/css/components.css',
        'assets/css/components-emr.css',
        'assets/css/modal.css',
        'assets/css/layout.css',
        'assets/css/pacs.css',
        'assets/css/dark.css',
        'assets/css/print.css',
    );

    /** 沉浸式页面（登录/安装/403/404）CSS */
    const CSS_AUTH = array(
        'assets/css/base.css',
        'assets/css/components.css',
        'assets/css/components-emr.css',
        'assets/css/modal.css',
        'assets/css/auth.css',
        'assets/css/dark.css',
    );

    /** 全站公共 JS（按依赖顺序，须在视图内联脚本之前加载） */
    const JS_CORE = array(
        'assets/js/components/ajax.js',
        'assets/js/components/modal.js',
        'assets/js/components/deptpicker.js',
        'assets/js/components/depttree.js',
        'assets/js/components/toast.js',
        'assets/js/components/feepop.js',
        'assets/js/components/push.js',
        'assets/js/components/smart_poller.js',
        'assets/js/components/infinite.js',
        'assets/js/components/print.js',
        'assets/js/components/theme.js',
        'assets/js/components/dropdown.js',
        'assets/js/components/notify.js',
        'assets/js/components/import.js',
        'assets/js/components/selector.js',
        'assets/js/components/validation.js',
        'assets/js/components/datetime.js',
        'assets/js/components/datepicker.js',
        'assets/js/components/historypanel.js',
        'assets/js/components/patient.js',
        'assets/js/components/ui.js',
        'assets/js/components/naming.js',
        'assets/js/components/conntest.js',
        'assets/js/components/drugform.js',
        'assets/js/components/chart.js',
        'assets/js/components/critical.js',
        'assets/js/components/authsync.js',
        'assets/js/components/app.js',
        'assets/js/components/nav.js',
    );

    /** 沉浸式页面 JS */
    const JS_AUTH = array(
        'assets/js/components/ajax.js',
        'assets/js/components/conntest.js',
        'assets/js/components/toast.js',
        'assets/js/components/theme.js',
        'assets/js/components/dropdown.js',
        'assets/js/components/authsync.js',
        'assets/js/components/validation.js',
    );

    /** 电子病历栈组件（EMR 相关页面按需加载） */
    const JS_EMR = array(
        'queuepanel_core', 'order', 'emreditor', 'emr_ctxmenu', 'eventbus', 'emr', 'emr_diag', 'emr_cert', 'emr_consult', 'emr_rules', 'emr_format',
        'emr_template', 'emr_fee', 'emr_patient', 'emr_orders', 'emr_segments', 'emr_consent', 'vitals', 'queuepanel',
    );

    /** EMR 栈中需按文件 mtime 防缓存的组件（重构期高频改动） */
    const JS_EMR_MTIME = array('emreditor', 'emr_template', 'emr_segments');

    /** 医生工作站顶栏工具 */
    const JS_DOC_TOOLS = array('doctor_tools');

    /** 科室工作台（护士/检验/影像/药房）共用组件 */
    const JS_DEPT_WORK = array('queuepanel_core', 'deptwork', 'vitals', 'pacshistory');

    /** 诊室大屏绑定心跳（跨页面保活） */
    const JS_ROOM_HEARTBEAT = array('room_heartbeat');

    /** 管理端项目列表公共组件 */
    const JS_ADMIN_ITEMS = array('admin_items');

    /** 按文件修改时间戳生成版本参数（缺失回退 APP_VERSION） */
    public static function mtimeVer($rel) {
        $p = dirname(__DIR__, 2) . '/public/' . ltrim($rel, '/');
        $m = @filemtime($p);
        return $m ? $m : APP_VERSION;
    }

    /** 单个资源 URL（带版本参数） */
    public static function url($rel, $mtime = true) {
        return '/' . ltrim($rel, '/') . '?v=' . ($mtime ? self::mtimeVer($rel) : APP_VERSION);
    }

    /** CSS <link> 标签串 */
    public static function cssTags($list) {
        $out = '';
        foreach ((array)$list as $rel) {
            $out .= '<link rel="stylesheet" href="' . self::url($rel) . '">' . "\n";
        }
        return rtrim($out, "\n");
    }

    /** JS <script> 标签串；$mtime=false 使用 APP_VERSION 版本参数 */
    public static function jsTags($list, $mtime = true) {
        $out = '';
        foreach ((array)$list as $rel) {
            $out .= self::jsTag(self::componentPath($rel), $mtime);
        }
        return rtrim($out, "\n");
    }

    /** 单个组件 <script>（$rel 为完整相对路径） */
    public static function jsTag($rel, $mtime = true) {
        return '<script src="' . self::url($rel, $mtime) . '"></script>';
    }

    /** 组件名 → 完整路径（无路径时按 components/ 目录解析） */
    public static function componentPath($name) {
        return strpos($name, '/') !== false ? $name : 'assets/js/components/' . $name . '.js';
    }

    /** EMR 栈脚本标签串（含按 mtime/APP_VERSION 混合版本策略） */
    public static function emrTags() {
        $out = '';
        foreach (self::JS_EMR as $name) {
            $rel = self::componentPath($name);
            $mtime = in_array($name, self::JS_EMR_MTIME, true);
            $out .= self::jsTag($rel, $mtime);
        }
        return rtrim($out, "\n");
    }

    /**
     * Service Worker 预缓存清单：公共 CSS + 公共 JS + PWA 图标。
     * 与页面实际加载的公共资源保持一致，避免离线时缺资源。
     * @return string[] 带版本参数的绝对路径
     */
    public static function precache() {
        $urls = array();
        foreach (array_merge(self::CSS_CORE, self::CSS_AUTH) as $rel) {
            $urls[self::url($rel)] = true;
        }
        foreach (array_merge(self::JS_CORE, self::JS_AUTH) as $rel) {
            $urls[self::url($rel)] = true;
        }
        $urls['/pwa-icon.png?v=' . APP_VERSION] = true;
        return array_keys($urls);
    }
}
