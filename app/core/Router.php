<?php
/**
 * ============================================================
 * Router.php v1.0.0 — 页面路由与权限校验
 * ============================================================
 * 说明：
 * 1. 路径式路由（Nginx 将所有请求转发到 public/index.php）
 * 2. 三级门禁：
 *    ① 安装门：未创建管理员时全站强制跳转 /install
 *    ② 登录门：未登录跳转 /login（保留原地址 next 参数）
 *    ③ 角色门：无关角色无法通过直接输入网址访问其他科室页面
 * 3. 渲染时输出统一框架布局（顶栏 + 侧边栏 + 内容区）
 * ============================================================ */
class Router {

    /** 当前页面标题（视图内可调用 Router::title() 覆盖） */
    public static $title = '';

    /** 页面标题设置 */
    public static function title($t) {
        self::$title = $t;
    }

    /**
     * 路由表：path => array(view 视图文件, roles 允许角色)
     * roles 取值：'guest' 未登录 / 'user' 任意登录用户 / 具体角色名
     */
    public static $routes = array(
        '/install'           => array('install.php',            array('guest')),
        '/login'             => array('login.php',              array('guest')),
        '/logout'            => array('logout.php',             array('user')),
        '/messages'          => array('messages.php',           array('user')),
        '/password'          => array('password.php',           array('user')),
        '/profile'           => array('profile.php',            array('user')),
        // ===== 管理员 =====
        '/admin/dashboard'   => array('admin/dashboard.php',    array('admin')),
        '/admin/settings'    => array('admin/settings.php',     array('admin')),
        '/admin/departments' => array('admin/departments.php',  array('admin')),
        '/admin/users'       => array('admin/users.php',        array('admin')),
        // 检验 / 检查项目分开管理（检验支持组合检验；检查无成组逻辑）
        '/admin/items'       => array('admin/labitems.php',     array('admin', 'lab')),   // 旧链接兼容 → 检验项目管理
        '/admin/labitems'    => array('admin/labitems.php',     array('admin', 'lab')),
        '/admin/examitems'   => array('admin/examitems.php',    array('admin', 'imaging')),
        '/admin/drugs'       => array('admin/drugs.php',        array('admin', 'pharmacy')),
        '/admin/drugsettings'=> array('admin/drugsettings.php', array('admin', 'pharmacy')),
        '/admin/disposal'    => array('admin/disposal.php',     array('admin')),
        '/admin/diagnosis'   => array('admin/diagnosis.php',    array('admin')),
        '/admin/review'      => array('admin/review.php',       array('admin')),
        '/admin/printcenter' => array('admin/printcenter.php',  array('admin')),
        '/admin/callmanage'  => array('admin/callmanage.php',   array('admin')),
        '/admin/analytics'   => array('admin/analytics.php',    array('admin')),
        // ===== 模板管理（管理员/医生共用视图，按角色渲染） =====
        '/admin/templates'   => array('templates.php',          array('admin')),
        '/doctor/templates'  => array('templates.php',          array('doctor')),
        // ===== 挂号收费处 =====
        '/cashier/register'  => array('cashier/register.php',   array('cashier')),
        '/cashier/home'      => array('cashier/home.php',       array('cashier')),
        '/cashier/regmanage' => array('cashier/regmanage.php',  array('cashier')),
        '/cashier/paymanage' => array('cashier/paymanage.php',  array('cashier')),
        // ===== 医生工作站 =====
        '/doctor/home'       => array('doctor/home.php',        array('doctor')),
        '/doctor/emr'        => array('doctor/emr.php',         array('doctor')),
        '/doctor/call'       => array('doctor/call.php',        array('doctor')),
        // ===== 护士站 =====
        '/nurse/dashboard'   => array('nurse/dashboard.php',    array('nurse')),
        '/nurse/home'        => array('nurse/home.php',         array('nurse')),
        // ===== 检验科 =====
        '/lab/dashboard'     => array('lab/dashboard.php',      array('lab')),
        '/lab/home'          => array('lab/home.php',           array('lab')),
        // ===== 影像科 =====
        '/imaging/dashboard' => array('imaging/dashboard.php',  array('imaging')),
        '/imaging/home'      => array('imaging/home.php',       array('imaging')),
        // ===== 药房 =====
        '/pharmacy/dashboard'=> array('pharmacy/dashboard.php', array('pharmacy')),
        '/pharmacy/home'     => array('pharmacy/home.php',      array('pharmacy')),
    );

    /** 匹配路由（支持最长前缀） */
    private static function match($uri) {
        $best = null;
        $bestLen = -1;
        foreach (self::$routes as $path => $cfg) {
            if ($uri === $path || strpos($uri, $path . '/') === 0 || ($path !== '/' && $uri === rtrim($path, '/'))) {
                $len = strlen($path);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $cfg;
                }
            }
        }
        return $best;
    }

    /** 路由分发 */
    public static function dispatch($uri) {
        // 根路径：未安装→安装页；已登录→工作台；未登录→落地页（含登录 CTA）
        if ($uri === '/' || $uri === '') {
            if (!self::installed()) {
                header('Location: /install');
                exit;
            }
            if (Auth::check()) {
                header('Location: ' . Auth::home());
                exit;
            }
            self::render('landing.php');
            return;
        }

        $route = self::match($uri);
        if (!$route) {
            self::notFound();
            return;
        }

        // ===== ① 安装门：未创建管理员 → 仅允许访问安装页 =====
        $installed = self::installed();
        if (!$installed) {
            if ($uri !== '/install') {
                header('Location: /install');
                exit;
            }
            self::render('install.php');
            return;
        }
        if ($uri === '/install') {
            header('Location: /login');
            exit;
        }

        $u = Auth::user();

        // ===== ② 登录门 =====
        if (in_array('guest', $route[1])) {
            if ($u) {
                header('Location: ' . Auth::home());
                exit;
            }
        } else {
            if (!$u) {
                header('Location: /login?next=' . urlencode($uri));
                exit;
            }
            // 实时校验：管理员停用/删除用户后，既有会话立即失效（跳转登录页）
            if (!Auth::assertActive()) {
                header('Location: /login');
                exit;
            }
            // ===== ③ 角色门：无关角色直接访问他人页面 → 403 =====
            if (!in_array('user', $route[1], true) && !in_array($u['role'], $route[1], true) && $u['role'] !== 'admin') {
                self::forbidden();
                return;
            }
        }

        self::render($route[0]);
    }

    /** 是否已安装（是否存在管理员用户） */
    public static function installed() {
        try {
            return (int)DB::val('SELECT COUNT(*) FROM users') > 0;
        } catch (Exception $ex) {
            return false;
        }
    }

    /** 渲染视图（输出完整页面） */
    public static function render($view) {
        $viewFile = VIEW_PATH . '/' . $view;
        if (!is_file($viewFile)) {
            self::notFound();
            return;
        }
        require_once APP_ROOT . '/app/includes/layout.php';
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        $standalone = ($view === 'login.php' || $view === 'install.php' || $view === 'landing.php' || $view === 'doctor/call.php');
        // 需要 EMR 栈（emr.js + emr_* + order + queuepanel 等）的页面：
        // 医生工作站、模板管理、审核中心（模板预览）
        $needEmr = ($view === 'doctor/emr.php' || $view === 'templates.php' || $view === 'admin/review.php');
        if ($view === 'landing.php' || $view === 'doctor/call.php') {
            // 落地页 / 叫号屏自带完整 HTML，直接输出捕获内容即可
            echo $content;
            return;
        }
        if ($standalone) {
            echo Layout::authPage($content);
        } else {
            // 病历书写页强制缩小侧边栏，为书写区提供足够空间（忽略用户偏好）
            // 医生工作站（新）顶栏注入：工具箱下拉 / 叫号大屏绑定 / 科室切换
            $isDocWork = ($view === 'doctor/emr.php');
            echo Layout::appPage($content, self::$title, $isDocWork, $needEmr, $isDocWork);
        }
    }

    /** 404 页面 */
    public static function notFound() {
        http_response_code(404);
        require_once APP_ROOT . '/app/includes/layout.php';
        echo Layout::authPage('<div class="auth-card"><div class="auth-title">404</div><div class="auth-sub">页面不存在或已被移除</div><p class="text-center"><a href="/">返回首页</a></p></div>');
        exit;
    }

    /** 403 无权限页面 */
    public static function forbidden() {
        http_response_code(403);
        require_once APP_ROOT . '/app/includes/layout.php';
        echo Layout::authPage('<div class="auth-card"><div class="auth-title">403</div><div class="auth-sub">您没有权限访问该页面<br>请通过左侧菜单进入您的工作台</div><p class="text-center"><a href="' . Auth::home() . '">返回我的工作台</a></p></div>');
        exit;
    }
}
