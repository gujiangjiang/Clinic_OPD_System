<?php
/**
 * ============================================================
 * layout.php v1.0.0 — 统一页面布局
 * ============================================================
 * 说明：
 * 1. authPage()：登录/安装等独立沉浸式页面
 * 2. appPage()：系统框架（左侧导航 + 顶部栏 + 内容区）
 *    顶部栏：主题切换（明亮/夜间/自动）、站内消息铃铛、用户信息
 *    侧边栏：按角色渲染菜单（无关角色看不到其他科室入口）
 * 3. 向页面注入：CSRF 令牌、主题偏好、医院名称（打印用）、favicon
 * ============================================================ */
class Layout {

    /** 侧边栏菜单（按角色渲染） */
    private static function menu($role) {
        $items = array();
        if ($role === 'admin') {
            $items['管理'] = array(
                array('工作台', '🏠', '/admin/dashboard'),
                array('系统设置', '⚙️', '/admin/settings'),
                array('科室管理', '🏥', '/admin/departments'),
                array('用户管理', '👥', '/admin/users'),
                array('检验检查项目', '🧪', '/admin/items'),
                array('药品信息', '💊', '/admin/drugs'),
                array('药品设置', '📦', '/admin/drugsettings'),
                array('处置项目', '🩹', '/admin/disposal'),
                array('诊断管理', '📖', '/admin/diagnosis'),
                array('审核中心', '✅', '/admin/review'),
                array('打印中心', '🖨️', '/admin/printcenter'),
            );
        } elseif ($role === 'cashier') {
            $items['挂号收费'] = array(
                array('挂号收费', '🎫', '/cashier/register'),
                array('挂号管理', '📋', '/cashier/regmanage'),
                array('缴费与退费', '💳', '/cashier/paymanage'),
            );
        } elseif ($role === 'doctor') {
            $items['医生工作站'] = array(
                array('医生工作站', '🩺', '/doctor/dashboard'),
                array('叫号屏幕', '📺', '/doctor/call'),
            );
        } elseif ($role === 'nurse') {
            $items['护士站'] = array(
                array('护士工作站', '💉', '/nurse/dashboard'),
            );
        } elseif ($role === 'lab') {
            $items['检验科'] = array(
                array('检验科工作台', '🧪', '/lab/dashboard'),
            );
        } elseif ($role === 'imaging') {
            $items['影像科'] = array(
                array('影像科工作台', '🩻', '/imaging/dashboard'),
            );
        } elseif ($role === 'pharmacy') {
            $items['药房'] = array(
                array('药房工作台', '💊', '/pharmacy/dashboard'),
            );
        }
        $items['通用'] = array(
            array('站内消息', '💬', '/messages'),
            array('个人信息', '👤', '/profile'),
            array('修改密码', '🔑', '/password'),
        );
        $html = '';
        foreach ($items as $group => $list) {
            $html .= '<div class="nav-group-title">' . e($group) . '</div>';
            foreach ($list as $it) {
                $html .= '<a class="nav-item" data-href="' . e($it[2]) . '" href="' . e($it[2]) . '">' .
                    '<span class="nav-ico">' . $it[1] . '</span><span>' . e($it[0]) . '</span></a>';
            }
        }
        return $html;
    }

    /** 独立页面（登录/安装/403/404） */
    public static function authPage($content) {
        $hosp = setting('hospital_name', '');
        $logo = setting('logo', '');
        $logoImg = $logo !== '' ? '<img src="' . e($logo) . '" alt="LOGO" class="auth-logo">' : '';
        $favicon = $logo !== '' ? '<link rel="icon" href="' . e($logo) . '">' : '';
        $theme = Auth::theme();
        $html = '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . e($hosp !== '' ? $hosp . ' - 门诊一体化系统' : '门诊一体化系统') . '</title>
            ' . $favicon . '
            <link rel="stylesheet" href="/assets/css/base.css">
            <link rel="stylesheet" href="/assets/css/components.css">
            <link rel="stylesheet" href="/assets/css/modal.css">
            <link rel="stylesheet" href="/assets/css/auth.css">
            <link rel="stylesheet" href="/assets/css/dark.css">
        </head>
        <body class="auth-body" data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e(setting('hospital_name2', '')) . '">
            ' . $logoImg . '
            ' . $content . '
            <script src="/assets/js/components/ajax.js"></script>
            <script src="/assets/js/components/toast.js"></script>
            <script src="/assets/js/components/theme.js"></script>
            <script src="/assets/js/components/validation.js"></script>
        </body></html>';
        return $html;
    }

    /** 系统框架页 */
    public static function appPage($content, $title) {
        $u = Auth::user();
        if (!$u) {
            header('Location: /login');
            exit;
        }
        $hosp = setting('hospital_name', '门诊一体化系统');
        $hosp2 = setting('hospital_name2', '');
        $logo = setting('logo', '');
        $favicon = $logo !== '' ? '<link rel="icon" href="' . e($logo) . '">' : '';
        $brandImg = $logo !== '' ? '<img src="' . e($logo) . '" alt="LOGO">' : '';
        // 页脚版权：固定格式自动生成【© 年份 医院名称 版权所有】，无需手动配置
        $footer = '© ' . date('Y') . ' ' . ($hosp !== '' ? $hosp : '门诊一体化信息系统') . ' 版权所有';
        $theme = $u['theme'] ? $u['theme'] : 'auto';
        $pageTitle = $title !== '' ? $hosp . ' - ' . $title : $hosp;
        $avatar = !empty($u['photo']) ? '<img src="' . e($u['photo']) . '" alt="头像">' : '👤';

        // 管理员首次进入提醒修改密码
        $pwdTip = '';
        if ($u['role'] === 'admin' && (int)DB::val('user', 'SELECT pwd_changed FROM users WHERE id=?', array($u['id'])) === 0) {
            $pwdTip = '<div class="mb-12" style="background:var(--warning-soft);border-radius:8px;padding:10px 14px;font-size:13px">
                🔒 为保障系统安全，建议您尽快 <a href="/password" style="color:var(--warning);font-weight:600">修改管理员密码</a>（可忽略）</div>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . e($pageTitle) . '</title>
            ' . $favicon . '
            <link rel="stylesheet" href="/assets/css/base.css">
            <link rel="stylesheet" href="/assets/css/components.css">
            <link rel="stylesheet" href="/assets/css/modal.css">
            <link rel="stylesheet" href="/assets/css/layout.css">
            <link rel="stylesheet" href="/assets/css/dark.css">
            <link rel="stylesheet" href="/assets/css/print.css">
        </head>
        <body data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e($hosp2) . '">
            <!-- 关键：公共 JS 库必须在视图内容之前加载！
                 视图内联脚本（如 loadDeptList() / loadUserList()）在页面解析时立即执行，
                 若 Clinic 库尚未加载，Clinic.get() 会抛 TypeError，
                 导致列表区域永远停留在加载转圈状态（历史 bug）。
                 因此脚本放在内容区之前，保证内联脚本执行时 Clinic 已就绪。 -->
            <script src="/assets/js/components/ajax.js"></script>
            <script src="/assets/js/components/modal.js"></script>
            <script src="/assets/js/components/toast.js"></script>
            <script src="/assets/js/components/print.js"></script>
            <script src="/assets/js/components/theme.js"></script>
            <script src="/assets/js/components/notify.js"></script>
            <script src="/assets/js/components/selector.js"></script>
            <script src="/assets/js/components/validation.js"></script>
            <script src="/assets/js/components/datetime.js"></script>
            <script src="/assets/js/components/order.js"></script>
            <script src="/assets/js/components/editor.js"></script>
            <script src="/assets/js/components/emr.js"></script>
            <script src="/assets/js/components/patient.js"></script>
            <script src="/assets/js/components/ui.js"></script>
            <script src="/assets/js/components/app.js"></script>
            <div class="app">
                <!-- ===== 侧边栏 ===== -->
                <aside class="sidebar">
                    <div class="sidebar-brand">
                        ' . $brandImg . '
                        <div class="brand-name">' . e($hosp) . '</div>
                    </div>
                    <nav class="sidebar-nav">' . self::menu($u['role']) . '</nav>
                    <div class="sidebar-footer">' . e($footer) . '</div>
                </aside>

                <!-- ===== 主区域 ===== -->
                <div class="main">
                    <header class="topbar">
                        <div class="flex gap-12" style="align-items:center">
                            <button type="button" class="btn btn-outline btn-sm" data-sidebar-toggle style="padding:4px 10px">☰</button>
                            <div class="topbar-title">' . e($title !== '' ? $title : $hosp) . '</div>
                        </div>
                        <div class="topbar-right">
                            <button type="button" class="btn btn-outline btn-sm" data-theme-btn title="切换主题">
                                <span class="theme-label">' . ($theme === 'auto' ? '自动模式' : ($theme === 'dark' ? '夜间模式' : '明亮模式')) . '</span>
                            </button>
                            <div style="position:relative">
                                <button type="button" class="btn btn-outline btn-sm" data-msg-bell title="站内消息">💬
                                    <span class="badge badge-danger" data-msg-badge style="display:none;margin-left:2px;padding:0 6px"></span>
                                </button>
                            </div>
                            <a class="flex gap-8" style="align-items:center;color:var(--text)" href="/profile">
                                <span class="avatar" style="width:34px;height:34px;font-size:14px">' . $avatar . '</span>
                                <span class="fs-13 fw-600">' . e($u['name']) . '</span>
                                <span class="fs-12 text-muted">' . e(Auth::roleName($u['role'])) . '</span>
                            </a>
                            <button type="button" class="btn btn-outline btn-sm" data-logout title="退出登录">退出</button>
                        </div>
                    </header>
                    <main class="content">
                        ' . $pwdTip . '
                        ' . $content . '
                    </main>
                </div>
            </div>
        </body></html>';
    }
}
