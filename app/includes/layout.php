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
            $items['首页'] = array(
                array('首页', '🏠', '/admin/dashboard'),
            );
            $items['医院管理'] = array(
                array('科室管理', '🏥', '/admin/departments'),
                array('用户管理', '👥', '/admin/users'),
            );
            $items['基础数据'] = array(
                array('检验管理', '🧪', '/admin/labitems'),
                array('检查管理', '🩻', '/admin/examitems'),
                array('药品信息', '💊', '/admin/drugs'),
                array('药品设置', '📦', '/admin/drugsettings'),
                array('处置项目', '🩹', '/admin/disposal'),
                array('诊断管理', '📖', '/admin/diagnosis'),
                array('模板管理', '📋', '/admin/templates'),
            );
            $items['运营管理'] = array(
                array('审核中心', '✅', '/admin/review'),
                array('运营分析', '📊', '/admin/analytics'),
                array('打印中心', '🖨️', '/admin/printcenter'),
                array('叫号管理', '🖥️', '/admin/callmanage'),
            );
            $items['系统'] = array(
                array('系统设置', '⚙️', '/admin/settings'),
            );
        } elseif ($role === 'cashier') {
            $items['挂号收费'] = array(
                array('首页', '🏠', '/cashier/home'),
                array('挂号收费', '🎫', '/cashier/register'),
                array('挂号管理', '📋', '/cashier/regmanage'),
                array('缴费与退费', '💳', '/cashier/paymanage'),
            );
        } elseif ($role === 'doctor') {
            $items['医生工作站'] = array(
                array('首页', '🏠', '/doctor/home'),
                array('医生工作站', '🩺', '/doctor/emr'),
                array('旧工作站', '🖥️', '/doctor/dashboard'),
                array('模板管理', '📋', '/doctor/templates'),
            );
        } elseif ($role === 'nurse') {
            $items['护士站'] = array(
                array('首页', '🏠', '/nurse/home'),
                array('护士工作站', '💉', '/nurse/dashboard'),
            );
        } elseif ($role === 'lab') {
            $items['检验科'] = array(
                array('首页', '🏠', '/lab/home'),
                array('检验科工作台', '🧪', '/lab/dashboard'),
            );
            $items['管理'] = array(
                array('检验管理', '🧪', '/admin/labitems'),
            );
        } elseif ($role === 'imaging') {
            $items['影像科'] = array(
                array('首页', '🏠', '/imaging/home'),
                array('影像科工作台', '🩻', '/imaging/dashboard'),
            );
            $items['管理'] = array(
                array('检查管理', '🩻', '/admin/examitems'),
            );
        } elseif ($role === 'pharmacy') {
            $items['药房'] = array(
                array('首页', '🏠', '/pharmacy/home'),
                array('药房工作台', '💊', '/pharmacy/dashboard'),
            );
            $items['管理'] = array(
                array('药品信息', '💊', '/admin/drugs'),
                array('药品设置', '📦', '/admin/drugsettings'),
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
                // title：侧边栏缩小（仅图标）模式下的悬停名称提示
                $html .= '<a class="nav-item" data-href="' . e($it[2]) . '" href="' . e($it[2]) . '" title="' . e($it[0]) . '">' .
                    '<span class="nav-ico">' . $it[1] . '</span><span>' . e($it[0]) . '</span></a>';
            }
        }
        return $html;
    }

    /** 独立页面（登录/安装/403/404） */
    public static function authPage($content) {
        $hosp = setting('hospital_name', '');
        $hosp2 = setting('hospital_name2', '');
        // LOGO 以 base64 Data URI 内联显示：不暴露文件 URL，且不受页面层级影响；
        // 未设置时显示默认 LOGO（与系统主布局一致的 🏥 占位）
        $logoData = img_data(setting('logo', ''));
        $logoImg = $logoData !== ''
            ? '<img src="' . e($logoData) . '" alt="LOGO" class="auth-logo">'
            : '<span class="brand-default-logo">🏥</span>';
        // 品牌区：LOGO + 医院名称（第一名称大字/第二名称小字，两行左右两端对齐）
        $brandNames = '';
        if ($hosp !== '') $brandNames .= '<div class="brand-name">' . e($hosp) . '</div>';
        if ($hosp2 !== '') $brandNames .= '<div class="brand-name2">' . e($hosp2) . '</div>';
        $brandHtml = ($logoImg !== '' || $brandNames !== '')
            ? '<div class="auth-brand">' . $logoImg . '<div class="brand-names">' . $brandNames . '</div></div>'
            : '';
        $favicon = $logoData !== '' ? '<link rel="icon" href="' . e($logoData) . '">' : '';
        $theme = Auth::theme();
        $html = '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . e($hosp !== '' ? $hosp . ' - 门诊一体化系统' : '门诊一体化系统') . '</title>
            ' . $favicon . '
            <link rel="stylesheet" href="/assets/css/base.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/components.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/components-emr.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/modal.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/auth.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/dark.css?v=' . APP_VERSION . '">
        </head>
        <body class="auth-body" data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e(setting('hospital_name2', '')) . '">
            ' . $brandHtml . '
            ' . $content . '
            <script src="/assets/js/components/ajax.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/toast.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/theme.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/validation.js?v=' . APP_VERSION . '"></script>
        </body></html>';
        return $html;
    }

    /**
     * 系统框架页
     * @param string $content   视图内容
     * @param string $title     页面标题
     * @param bool   $forceMini 强制缩小侧边栏（病历书写页为书写区让出空间，忽略用户偏好）
     * @param bool   $needEmr   是否需要 EMR 栈组件（医生工作站/模板/审核预览）
     */
    public static function appPage($content, $title, $forceMini = false, $needEmr = false) {
        $u = Auth::user();
        if (!$u) {
            header('Location: /login');
            exit;
        }
        $hosp = setting('hospital_name', '门诊一体化系统');
        $hosp2 = setting('hospital_name2', '');
        // LOGO 以 base64 Data URI 内联显示：不暴露文件 URL，且不受页面层级影响
        // （修复：原相对路径在 /admin/* 等二级路径页被解析为 /admin/uploads/... 导致 404）
        $logoData = img_data(setting('logo', ''));
        $favicon = $logoData !== '' ? '<link rel="icon" href="' . e($logoData) . '">' : '';
        // 未设置 LOGO 时显示默认简易 LOGO（🏥），避免侧边栏 mini 模式下顶部空白
        $brandImg = $logoData !== ''
            ? '<img src="' . e($logoData) . '" alt="LOGO">'
            : '<span class="brand-default-logo">🏥</span>';
        // 页脚版权：固定格式自动生成【© 年份 医院名称 版权所有】，无需手动配置
        $footer = '© ' . date('Y') . ' ' . ($hosp !== '' ? $hosp : '门诊一体化信息系统') . ' 版权所有';
        $theme = $u['theme'] ? $u['theme'] : 'auto';
        // 侧边栏偏好：expand 展开 / mini 缩小（仅图标），跟随用户保存；
        // 病历书写页强制 mini（$forceMini），不持久化用户选择
        $sidebar = $forceMini ? 'mini' : Auth::sidebar();
        $appClass = $sidebar === 'mini' ? 'app sidebar-mini' : 'app';
        $pageTitle = $title !== '' ? $hosp . ' - ' . $title : $hosp;
        // 头像以 base64 Data URI 内联显示：不暴露上传文件真实 URL（防服务器路径泄露）；
        // 且不受页面层级影响（二级路径页不会解析成 /admin/uploads/... 404）。
        $avatar = !empty($u['photo']) && ($__ava = img_data($u['photo'])) !== ''
            ? '<img src="' . e($__ava) . '" alt="头像">'
            : '👤';

        // 右上角悬浮窗数据：工号 + 职称（session 不包含，需查库；医务人员才有职称）
        // print_auto 一并查库取实时值：打印预览「自动打印」偏好的服务端初始态
        // photo 也查库并同步会话：头像审核通过后 users.photo 已更新，
        // 但登录会话快照仍是旧值，须在此校准，保证页面右上角头像即时显示新头像。
        $uFull = DB::one('SELECT emp_no, name, role, title, print_auto, photo, current_dept_id FROM users WHERE id=?', array((int)$u['id']));
        if ($uFull && $uFull['photo'] !== $u['photo']) {
            Auth::updateSession('photo', $uFull['photo']);
            $u['photo'] = $uFull['photo'];
            $avatar = !empty($u['photo']) && ($__ava = img_data($u['photo'])) !== ''
                ? '<img src="' . e($__ava) . '" alt="头像">'
                : '👤';
        }
        $uDeptId = $uFull && isset($uFull['current_dept_id']) ? (int)$uFull['current_dept_id'] : (isset($u['current_dept_id']) ? (int)$u['current_dept_id'] : 0);
        $uEmpNo = $uFull && $uFull['emp_no'] !== '' ? $uFull['emp_no'] : '—';
        $uTitle = $uFull && $uFull['title'] !== '' ? $uFull['title'] : '';
        $uHasTitle = in_array($u['role'], array('doctor', 'nurse', 'lab', 'imaging', 'pharmacy'), true);
        $uRoleName = Auth::roleName($u['role']);
        // EMR 专用组件脚本（仅医生工作站/模板管理/审核预览需要，按页裁剪降低全站脚本体积）
        $emrScripts = '';
        if ($needEmr) {
            $emrScripts = implode("\n", array_map(function ($f) {
                return '<script src="/assets/js/components/' . $f . '.js?v=' . APP_VERSION . '"></script>';
            }, array(
                'order', 'editor', 'emreditor', 'eventbus', 'emr', 'emr_rules', 'emr_format',
                'emr_template', 'emr_fee', 'emr_patient', 'emr_orders', 'emr_segments', 'emr_consent', 'queuepanel',
            )));
        }
        $uPop = '<div class="user-pop">' .
            '<div class="user-pop-head">' .
            '<span class="avatar" style="width:38px;height:38px;font-size:15px">' . $avatar . '</span>' .
            '<div class="user-pop-id"><div class="user-pop-name">' . e($u['name']) . '</div>' .
            '<div class="user-pop-role">' . e($uRoleName) . ($uHasTitle && $uTitle !== '' ? ' · ' . e($uTitle) : '') . '</div></div></div>' .
            '<div class="user-pop-row"><span>工号</span><span>' . e($uEmpNo) . '</span></div>' .
            '<div class="user-pop-row"><span>姓名</span><span>' . e($u['name']) . '</span></div>' .
            '<div class="user-pop-row"><span>角色</span><span>' . e($uRoleName) . '</span></div>' .
            ($uHasTitle ? '<div class="user-pop-row"><span>职称</span><span>' . e($uTitle !== '' ? $uTitle : '未设置') . '</span></div>' : '') .
            '<div class="user-pop-foot"><a href="/profile">个人中心 ›</a></div></div>';

        // 管理员首次登录改密码提醒：改由站内消息通知（登录时写入，点击跳转 /password），
        // 不再于页面顶部弹出横幅

        return '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . e($pageTitle) . '</title>
            ' . $favicon . '
            <link rel="stylesheet" href="/assets/css/base.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/components.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/components-emr.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/modal.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/layout.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/dark.css?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/print.css?v=' . APP_VERSION . '">
        </head>
        <body data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-sidebar-pref="' . e($sidebar) . '"' . ($forceMini ? ' data-sidebar-force="1"' : '') . '
            data-role="' . e($u['role']) . '" data-uid="' . (int)$u['id'] . '" data-name="' . e($u['name']) . '" data-dept="' . (int)$uDeptId . '" data-sid="' . session_id() . '" data-print-auto="' . (!empty($uFull['print_auto']) ? '1' : '0') . '"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e($hosp2) . '">
            <!-- 关键：公共 JS 库必须在视图内容之前加载！
                 视图内联脚本（如 loadDeptList() / loadUserList()）在页面解析时立即执行，
                 若 Clinic 库尚未加载，Clinic.get() 会抛 TypeError，
                 导致列表区域永远停留在加载转圈状态（历史 bug）。
因此脚本放在内容区之前，保证内联脚本执行时 Clinic 已就绪。 -->
            <!-- 核心通用组件（所有页面加载） -->
            <script src="/assets/js/components/ajax.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/modal.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/deptpicker.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/depttree.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/toast.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/print.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/theme.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/notify.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/import.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/selector.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/validation.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/datetime.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/datepicker.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/historypanel.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/patient.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/ui.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/chart.js?v=' . APP_VERSION . '"></script>
            <script src="/assets/js/components/app.js?v=' . APP_VERSION . '"></script>
            ' . $emrScripts . '
            <div class="' . $appClass . '">
                <!-- ===== 侧边栏 ===== -->
                <aside class="sidebar">
                    <div class="sidebar-brand">
                        ' . $brandImg . '
                        <div class="brand-names">
                            <div class="brand-name">' . e($hosp) . '</div>' .
                            ($hosp2 !== '' ? '<div class="brand-name2">' . e($hosp2) . '</div>' : '') . '
                        </div>
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
                            <div class="user-wrap">
                                <a class="flex gap-8" style="align-items:center;color:var(--text)" href="/profile">
                                    <span class="avatar" style="width:34px;height:34px;font-size:14px">' . $avatar . '</span>
                                    <span class="fs-13 fw-600">' . e($u['name']) . '</span>
                                    <span class="fs-12 text-muted">' . e($uRoleName) . '</span>
                                </a>
                                ' . $uPop . '
                            </div>
                            <button type="button" class="btn btn-outline btn-sm" data-logout title="退出登录">退出</button>
                        </div>
                    </header>
                    <main class="content">
                        ' . $content . '
                    </main>
                </div>
            </div>
        </body></html>';
    }
}
