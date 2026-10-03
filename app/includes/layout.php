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

    /**
     * 资源版本参数：按文件修改时间戳做缓存失效（流式行内编辑器重构期间
     * 防浏览器旧缓存，文件缺失时回退 APP_VERSION）。
     * @param string $rel 相对 public/ 的路径，如 assets/css/components-emr.css
     */
    private static function assetVer($rel) {
        $p = dirname(__DIR__, 2) . '/public/' . $rel;
        $m = @filemtime($p);
        return $m ? $m : APP_VERSION;
    }

    /** 侧边栏菜单（按角色渲染） */
    private static function menu($role) {
        $items = array();
        if ($role === 'admin') {
            $items['首页'] = array(
                array('首页', render_icon('nav:home'), '/admin/dashboard'),
            );
            $items['业务管理'] = array(
                array('审核中心', render_icon('alert:success'), '/admin/review'),
                array('叫号管理', render_icon('nav:screen'), '/admin/callmanage'),
                array('科室管理', render_icon('nav:hospital'), '/admin/departments'),
                array('用户管理', render_icon('nav:group'), '/admin/users'),
            );
            $items['基础字典'] = array(
                array('检验管理', render_icon('nav:lab'), '/admin/labitems'),
                array('检查管理', render_icon('nav:imaging'), '/admin/examitems'),
                array('药品目录', render_icon('nav:pharmacy'), '/admin/drugs'),
                array('药品设置', render_icon('action:package'), '/admin/drugsettings'),
                array('处置项目', render_icon('clinical:plaster'), '/admin/disposal'),
                array('诊断字典', render_icon('emr:book'), '/admin/diagnosis'),
            );
            $items['模板与套餐'] = array(
                array('模板管理', render_icon('emr:record'), '/admin/templates'),
                array('套餐管理', render_icon('emr:disposal'), '/admin/packages'),
            );
            $items['统计与查询'] = array(
                array('运营分析', render_icon('nav:chart'), '/admin/analytics'),
                array('查询中心', render_icon('action:search'), '/admin/querycenter'),
                array('打印中心', render_icon('action:print'), '/admin/printcenter'),
            );
            $items['系统设置'] = array(
                array('接口管理', render_icon('nav:plug'), '/admin/integration'),
                array('系统设置', render_icon('nav:settings'), '/admin/settings'),
            );
        } elseif ($role === 'cashier') {
            $items['挂号收费'] = array(
                array('首页', render_icon('nav:home'), '/cashier/home'),
                array('挂号收费', render_icon('emr:ticket'), '/cashier/register'),
                array('挂号管理', render_icon('emr:record'), '/cashier/regmanage'),
                array('缴费管理', render_icon('nav:card'), '/cashier/paymanage'),
            );
        } elseif ($role === 'doctor') {
            $items['医生工作站'] = array(
                array('首页', render_icon('nav:home'), '/doctor/home'),
                array('医生工作站', render_icon('clinical:stethoscope'), '/doctor/emr'),
                array('危急值管理', render_icon('alert:critical'), '/doctor/critical'),
                array('模板管理', render_icon('emr:record'), '/doctor/templates'),
                array('套餐管理', render_icon('emr:disposal'), '/doctor/packages'),
            );
        } elseif ($role === 'nurse') {
            $items['护士站'] = array(
                array('首页', render_icon('nav:home'), '/nurse/home'),
                array('护士工作站', render_icon('clinical:injection'), '/nurse/dashboard'),
                array('护理模板', render_icon('emr:record'), '/nurse/templates'),
            );
        } elseif ($role === 'lab') {
            $items['检验科'] = array(
                array('首页', render_icon('nav:home'), '/lab/home'),
                array('检验科工作台', render_icon('nav:lab'), '/lab/dashboard'),
                array('危急值管理', render_icon('alert:critical'), '/lab/critical'),
            );
            $items['管理'] = array(
                array('检验管理', render_icon('nav:lab'), '/admin/labitems'),
            );
        } elseif ($role === 'imaging') {
            $items['影像科'] = array(
                array('首页', render_icon('nav:home'), '/imaging/home'),
                array('影像科工作台', render_icon('nav:imaging'), '/imaging/dashboard'),
                array('危急值管理', render_icon('alert:critical'), '/imaging/critical'),
                array('影像模板', render_icon('emr:record'), '/imaging/templates'),
                array('查询中心', render_icon('action:search'), '/admin/querycenter'),
            );
            $items['管理'] = array(
                array('检查管理', render_icon('nav:imaging'), '/admin/examitems'),
            );
        } elseif ($role === 'pharmacy') {
            $items['药房'] = array(
                array('首页', render_icon('nav:home'), '/pharmacy/home'),
                array('药房工作台', render_icon('nav:pharmacy'), '/pharmacy/dashboard'),
            );
            $items['管理'] = array(
                array('药品目录', render_icon('nav:pharmacy'), '/admin/drugs'),
                array('药品设置', render_icon('action:package'), '/admin/drugsettings'),
            );
        }
        $items['通用'] = array(
            array('站内消息', render_icon('emr:consult'), '/messages'),
            array('个人信息', render_icon('nav:user'), '/profile'),   // 含密码修改（个人信息页内模态框）
        );
        $html = '';
        foreach ($items as $group => $list) {
            $html .= '<div class="nav-group-title">' . e($group) . '</div>';
            foreach ($list as $it) {
                // title：侧边栏缩小（仅图标）模式下的悬停名称提示
                // 站内消息项附加未读数徽章（展开靠右、缩小图标角标）
                $msgBadge = ($it[2] === '/messages') ? '<span class="badge badge-danger nav-msg-badge" data-nav-msg-badge style="display:none"></span>' : '';
                $html .= '<a class="nav-item" data-href="' . e($it[2]) . '" href="' . e($it[2]) . '" title="' . e($it[0]) . '">' .
                    '<span class="nav-ico">' . $it[1] . '</span><span class="nav-label">' . e($it[0]) . '</span>' . $msgBadge . '</a>';
            }
        }
        return $html;
    }

    /**
     * 医生工作站（新）顶栏工具组 HTML：叫号大屏绑定 + 工具箱
     * 完整页与 SPA 局部补丁（Router partial 输出）共用同一份，保证 DOM 一致：
     * 外层带 data-topbar-doc-tools 标记，nav.js 依据当前页动态注入/移除顶栏
     */
    public static function docToolsBar() {
        return '<div data-topbar-doc-tools style="display:inline-flex;align-items:center;gap:12px">' .
            '<div style="position:relative">' .
                '<button type="button" class="btn btn-outline btn-sm" id="docCallBtn" title="叫号大屏绑定" onclick="Clinic.docTools.toggleRoomList()">' . render_icon('action:announce') . ' <span id="docCallName">叫号</span></button>' .
                '<div id="docRoomList" style="display:none;position:absolute;top:100%;right:0;min-width:300px;max-height:340px;overflow-y:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px;z-index:100;box-shadow:0 8px 24px var(--shadow)"></div>' .
            '</div>' .
            '<div style="position:relative">' .
                '<button type="button" class="btn btn-outline btn-sm" id="docToolboxBtn" title="工具箱" onclick="Clinic.docTools.toggleToolbox()">' . render_icon('nav:toolbox') . ' 工具箱 ▾</button>' .
                '<div id="docToolbox" style="display:none;position:absolute;top:100%;right:0;min-width:170px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:6px;z-index:100;box-shadow:0 8px 24px var(--shadow)">' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.docTools.openAddSlot()">＋ 加号</div>' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.docTools.openDeptSwitch()">' . render_icon('nav:hospital') . ' 切换科室</div>' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.docTools.openPatientSearch()">' . render_icon('action:search') . ' 患者查询</div>' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.nav.go(\'/doctor/templates\')">' . render_icon('emr:record') . ' 模板管理</div>' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.nav.go(\'/doctor/packages\')">' . render_icon('emr:disposal') . ' 套餐管理</div>' .
                '</div>' .
            '</div>' .
        '</div>';
    }

    /** 医生工作站标题（标题 + 当前科室胶囊），SPA 局部导航由 nav.js 同步增删 */
    public static function docWorkTitle($label) {
        return '<div class="topbar-title doc-work-title">' . e($label) . '<span class="doc-work-dept" id="docWorkDept">加载科室…</span></div>';
    }

    /** 科室工作台顶栏工具组 HTML：叫号大屏绑定悬浮窗（参考医生工作站）+ 工具箱（患者查询/返回首页）
     * 完整页与 SPA 局部补丁（Router partial 输出）共用同一份，保证 DOM 一致：
     * 外层带 data-topbar-dept-tools 标记，nav.js 依据当前页动态注入/移除顶栏 */
    public static function deptToolsBar() {
        return '<div data-topbar-dept-tools style="display:inline-flex;align-items:center;gap:12px">' .
            '<div style="position:relative">' .
                '<button type="button" class="btn btn-outline btn-sm" id="dwCallBtn" title="叫号大屏绑定" onclick="Clinic.deptwork.toggleCallPop()">' . render_icon('action:announce') . ' <span id="dwCallName">叫号</span></button>' .
                '<div id="dwRoomList" style="display:none;position:absolute;top:100%;right:0;min-width:300px;max-height:340px;overflow-y:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px;z-index:100;box-shadow:0 8px 24px var(--shadow)"></div>' .
            '</div>' .
            '<div style="position:relative">' .
                '<button type="button" class="btn btn-outline btn-sm" id="dwToolboxBtn" title="工具箱" onclick="Clinic.deptwork.toggleToolbox()">' . render_icon('nav:toolbox') . ' 工具箱 ▾</button>' .
                '<div id="dwToolbox" style="display:none;position:absolute;top:100%;right:0;min-width:170px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:6px;z-index:100;box-shadow:0 8px 24px var(--shadow)">' .
                    '<div class="dd-item" style="cursor:pointer" onclick="Clinic.deptwork.openPatientSearch()">' . render_icon('action:search') . ' 患者查询</div>' .
                '</div>' .
            '</div>' .
        '</div>';
    }

    /** 独立页面（登录/安装/403/404） */
    public static function authPage($content, $hideBrand = false) {
        $hosp = setting('hospital_name', '');
        $hosp2 = setting('hospital_name2', '');
        // LOGO 以 base64 Data URI 内联显示：不暴露文件 URL，且不受页面层级影响；
        // 未设置时显示默认 LOGO（复用 /pwa-icon.png 默认图标，与浏览器标签页图标一致）
        $logoData = img_data(setting('logo', ''));
        $logoImg = $logoData !== ''
            ? '<img src="' . e($logoData) . '" alt="LOGO" class="auth-logo">'
            : '<span class="brand-default-logo">' . default_logo_img() . '</span>';
        // 品牌区：LOGO + 医院名称（第一名称大字/第二名称小字，两行左右两端对齐）
        $brandNames = '';
        if ($hosp !== '') $brandNames .= '<div class="brand-name">' . e($hosp) . '</div>';
        if ($hosp2 !== '') $brandNames .= '<div class="brand-name2">' . e($hosp2) . '</div>';
        // 首次安装页不显示品牌区/默认图标（尚未配置医院信息，且安装框居中呈现）
        $brandHtml = (!$hideBrand && ($logoImg !== '' || $brandNames !== ''))
            ? '<div class="auth-brand">' . $logoImg . '<div class="brand-names">' . $brandNames . '</div></div>'
            : '';
        // 浏览器标签页图标：统一 /pwa-icon.png（有 LOGO 输出 LOGO，无则默认透明底
        // 圆形红十字图标），与 PWA 图标一致；?v= 版本参数让图标更新后缓存自动失效
        $favicon = '<link rel="icon" href="/pwa-icon.png?v=' . APP_VERSION . '">';
        // PWA 桌面应用：清单（应用名=医院名称）+ 图标 + 独立窗口
        $pwaHead = '<link rel="manifest" href="/manifest.webmanifest">' .
            '<meta name="theme-color" content="#2563eb">' .
            '<meta name="mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-title" content="' . e($hosp !== '' ? $hosp : '门诊一体化系统') . '">' .
            '<link rel="apple-touch-icon" href="/pwa-icon.png?v=' . APP_VERSION . '">';
        $theme = Auth::theme();
        $html = '<!DOCTYPE html><html lang="zh-CN"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . e($hosp !== '' ? $hosp . ' - 门诊一体化系统' : '门诊一体化系统') . '</title>
            ' . $favicon . $pwaHead . '
            <link rel="stylesheet" href="/assets/css/base.css?v=' . self::assetVer('assets/css/base.css') . '">
            <link rel="stylesheet" href="/assets/css/components.css?v=' . self::assetVer('assets/css/components.css') . '">
            <link rel="stylesheet" href="/assets/css/components-emr.css?v=' . self::assetVer('assets/css/components-emr.css') . '">
            <link rel="stylesheet" href="/assets/css/modal.css?v=' . self::assetVer('assets/css/modal.css') . '">
            <link rel="stylesheet" href="/assets/css/auth.css?v=' . self::assetVer('assets/css/auth.css') . '">
            <link rel="stylesheet" href="/assets/css/dark.css?v=' . self::assetVer('assets/css/dark.css') . '">
        </head>
        <body class="auth-body" data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e(setting('hospital_name2', '')) . '" data-paywechat="' . e(setting('pay_wechat_enabled', '0')) . '" data-payalipay="' . e(setting('pay_alipay_enabled', '0')) . '" data-paybank="' . e(setting('pay_bankcard_enabled', '0')) . '" data-paymedicare="' . e(setting('pay_medicare_enabled', '0')) . '">
            ' . $brandHtml . '
            ' . $content . '
            <script src="/assets/js/components/ajax.js?v=' . self::assetVer('assets/js/components/ajax.js') . '"></script>
            <script src="/assets/js/components/conntest.js?v=' . self::assetVer('assets/js/components/conntest.js') . '"></script>
            <script src="/assets/js/components/toast.js?v=' . self::assetVer('assets/js/components/toast.js') . '"></script>
            <script src="/assets/js/components/theme.js?v=' . self::assetVer('assets/js/components/theme.js') . '"></script>
            <script src="/assets/js/components/dropdown.js?v=' . self::assetVer('assets/js/components/dropdown.js') . '"></script>
            <script src="/assets/js/components/authsync.js?v=' . self::assetVer('assets/js/components/authsync.js') . '"></script>
            <script src="/assets/js/components/validation.js?v=' . self::assetVer('assets/js/components/validation.js') . '"></script>
            <script>
            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function () {
                    navigator.serviceWorker.register("/sw.js").catch(function () {});
                });
            }
            </script>
        </body></html>';
        return $html;
    }

    /**
     * 系统框架页
     * @param string $content   视图内容
     * @param string $title     页面标题
     * @param bool   $forceMini 强制缩小侧边栏（病历书写页为书写区让出空间，忽略用户偏好）
     * @param bool   $needEmr   是否需要 EMR 栈组件（医生工作站/模板/审核预览）
     * @param bool   $docTools  医生工作站（新）顶栏工具（工具箱/叫号/科室切换）
     * @param bool   $needDeptWork 是否需要科室工作台组件（护士站/检验/影像/药房工作台）
     */
    public static function appPage($content, $title, $forceMini = false, $needEmr = false, $docTools = false, $needDeptWork = false) {
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
        // 浏览器标签页图标：统一 /pwa-icon.png（有 LOGO 输出 LOGO，无则默认医疗十字图标），与 PWA 图标一致；
        // ?v= 版本参数让图标内容更新后浏览器缓存自动失效
        $favicon = '<link rel="icon" href="/pwa-icon.png?v=' . APP_VERSION . '">';
        // 未设置 LOGO 时显示默认 LOGO（复用 /pwa-icon.png 默认图标），避免侧边栏 mini 模式下顶部空白
        $brandImg = $logoData !== ''
            ? '<img src="' . e($logoData) . '" alt="LOGO">'
            : '<span class="brand-default-logo">' . default_logo_img() . '</span>';
        // 页脚版权：固定格式自动生成【© 年份 医院名称 版权所有】，无需手动配置
        $footer = '© ' . date('Y') . ' ' . ($hosp !== '' ? $hosp : '门诊一体化信息系统') . ' 版权所有';
        $theme = $u['theme'] ? $u['theme'] : 'auto';
        // 医生工作站（新）顶栏工具开关：仅医生角色生效（同脚本注入条件）
        $docTools = $docTools && $u['role'] === 'doctor';
        // 侧边栏偏好：expand 展开 / mini 缩小（仅图标），跟随用户保存；
        // 病历书写页强制 mini（$forceMini），不持久化用户选择
        $sidebar = $forceMini ? 'mini' : Auth::sidebar();
        $appClass = $sidebar === 'mini' ? 'app sidebar-mini' : 'app';
        $pageTitle = $title !== '' ? $hosp . ' - ' . $title : $hosp;
        // 头像以 base64 Data URI 内联显示：不暴露上传文件真实 URL（防服务器路径泄露）；
        // 且不受页面层级影响（二级路径页不会解析成 /admin/uploads/... 404）。
        $avatar = !empty($u['photo']) && ($__ava = img_data($u['photo'])) !== ''
            ? '<img src="' . e($__ava) . '" alt="头像">'
            : render_icon('nav:user');

        // 右上角悬浮窗数据：工号 + 职称（session 不包含，需查库；医务人员才有职称）
        // print_auto 一并查库取实时值：打印预览「自动打印」偏好的服务端初始态
        // photo 也查库并同步会话：头像审核通过后 users.photo 已更新，
        // 但登录会话快照仍是旧值，须在此校准，保证页面右上角头像即时显示新头像。
        $uFull = UserRepository::one('SELECT emp_no, name, role, title, print_auto, photo, current_dept_id FROM users WHERE id=?', array((int)$u['id']));
        if ($uFull && $uFull['photo'] !== $u['photo']) {
            Auth::updateSession('photo', $uFull['photo']);
            $u['photo'] = $uFull['photo'];
            $avatar = !empty($u['photo']) && ($__ava = img_data($u['photo'])) !== ''
                ? '<img src="' . e($__ava) . '" alt="头像">'
                : render_icon('nav:user');
        }
        $uDeptId = $uFull && isset($uFull['current_dept_id']) ? (int)$uFull['current_dept_id'] : (isset($u['current_dept_id']) ? (int)$u['current_dept_id'] : 0);
        $uEmpNo = $uFull && $uFull['emp_no'] !== '' ? $uFull['emp_no'] : '—';
        $uTitle = $uFull && $uFull['title'] !== '' ? $uFull['title'] : '';
        $uHasTitle = in_array($u['role'], array('doctor', 'nurse', 'lab', 'imaging', 'pharmacy'), true);
        $uRoleName = Auth::roleName($u['role']);
        // EMR 专用组件脚本（仅医生工作站/模板管理/审核预览需要，按页裁剪降低全站脚本体积）
        // 流式行内编辑器重构期：emreditor/emr_template/emr_segments 按文件修改时间戳防缓存
        $emrScripts = '';
        if ($needEmr) {
            $emrScripts = implode("\n", array_map(function ($f) {
                $ver = in_array($f, array('emreditor', 'emr_template', 'emr_segments'), true)
                    ? self::assetVer('assets/js/components/' . $f . '.js')
                    : APP_VERSION;
                return '<script src="/assets/js/components/' . $f . '.js?v=' . $ver . '"></script>';
            }, array(
                'queuepanel_core', 'order', 'emreditor', 'emr_ctxmenu', 'eventbus', 'emr', 'emr_diag', 'emr_cert', 'emr_consult', 'emr_rules', 'emr_format',
                'emr_template', 'emr_fee', 'emr_patient', 'emr_orders', 'emr_segments', 'emr_consent', 'vitals', 'queuepanel',
            )));
        }
        // 医生工作站（新）顶栏工具：工具箱 / 叫号大屏绑定 / 科室切换（仅医生角色）
        if ($docTools) {
            $emrScripts .= "\n" . '<script src="/assets/js/components/doctor_tools.js?v=' . self::assetVer('assets/js/components/doctor_tools.js') . '"></script>';
        }
        // 医生角色全局：诊室大屏绑定心跳保活（跨页面持续，离开工作站/刷新不自动解绑）
        // 医技四科室（护士/检验/影像/药房）：大屏绑定心跳同样跨页面保活（room_heartbeat
        // 按 data-role 自动路由到 /api/deptwork）
        if (in_array($u['role'], array('doctor', 'nurse', 'lab', 'imaging', 'pharmacy'), true)) {
            $emrScripts .= "\n" . '<script src="/assets/js/components/room_heartbeat.js?v=' . self::assetVer('assets/js/components/room_heartbeat.js') . '"></script>';
        }
        // 科室工作台（护士站/检验/影像/药房）共用组件 + 候诊面板核心 + 生命体征悬浮窗组件
        if ($needDeptWork) {
            $emrScripts .= "\n" . '<script src="/assets/js/components/queuepanel_core.js?v=' . self::assetVer('assets/js/components/queuepanel_core.js') . '"></script>';
            $emrScripts .= "\n" . '<script src="/assets/js/components/deptwork.js?v=' . self::assetVer('assets/js/components/deptwork.js') . '"></script>';
            $emrScripts .= "\n" . '<script src="/assets/js/components/vitals.js?v=' . self::assetVer('assets/js/components/vitals.js') . '"></script>';
            // 影像科专属：历史报告调阅组件（检验科加载无害，仅影像科视图调用）
            $emrScripts .= "\n" . '<script src="/assets/js/components/pacshistory.js?v=' . self::assetVer('assets/js/components/pacshistory.js') . '"></script>';
        }
        // 管理端项目列表（检验/检查/药品/处置/模板/套餐/审核/分析）共用组件：
        // 全局加载（SPA 局部导航不重载 layout，条件加载会导致从非分页页
        // 导航到分页页时 Clinic.adminItems 未定义，列表报 pagedTable 错误）
        $emrScripts .= "\n" . '<script src="/assets/js/components/admin_items.js?v=' . self::assetVer('assets/js/components/admin_items.js') . '"></script>';
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
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="theme-color" content="#2563eb">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="' . e($hosp !== '' ? $hosp : '门诊一体化系统') . '">
            <link rel="apple-touch-icon" href="/pwa-icon.png?v=' . APP_VERSION . '">
            <link rel="stylesheet" href="/assets/css/base.css?v=' . self::assetVer('assets/css/base.css') . '">
            <link rel="stylesheet" href="/assets/css/components.css?v=' . self::assetVer('assets/css/components.css') . '">
            <link rel="stylesheet" href="/assets/css/components-emr.css?v=' . self::assetVer('assets/css/components-emr.css') . '">
            <link rel="stylesheet" href="/assets/css/modal.css?v=' . self::assetVer('assets/css/modal.css') . '">
            <link rel="stylesheet" href="/assets/css/layout.css?v=' . self::assetVer('assets/css/layout.css') . '">
            <link rel="stylesheet" href="/assets/css/pacs.css?v=' . self::assetVer('assets/css/pacs.css') . '">
            <link rel="stylesheet" href="/assets/css/dark.css?v=' . self::assetVer('assets/css/dark.css') . '">
            <link rel="stylesheet" href="/assets/css/print.css?v=' . self::assetVer('assets/css/print.css') . '">
        </head>
        <body data-csrf="' . e(CSRF::token()) . '" data-theme-pref="' . e($theme) . '" data-theme="light"
            data-sidebar-pref="' . e($sidebar) . '"' . ($forceMini ? ' data-sidebar-force="1"' : '') . '
            data-role="' . e($u['role']) . '" data-uid="' . (int)$u['id'] . '" data-name="' . e($u['name']) . '" data-dept="' . (int)$uDeptId . '" data-sid="' . session_id() . '" data-print-auto="' . (!empty($uFull['print_auto']) ? '1' : '0') . '"
            data-hosp="' . e($hosp) . '" data-hosp2="' . e($hosp2) . '" data-paywechat="' . e(setting('pay_wechat_enabled', '0')) . '" data-payalipay="' . e(setting('pay_alipay_enabled', '0')) . '" data-paybank="' . e(setting('pay_bankcard_enabled', '0')) . '" data-paymedicare="' . e(setting('pay_medicare_enabled', '0')) . '" data-ver="' . e(APP_VERSION) . '">
            <!-- 关键：公共 JS 库必须在视图内容之前加载！
                 视图内联脚本（如 loadDeptList() / loadUserList()）在页面解析时立即执行，
                 若 Clinic 库尚未加载，Clinic.get() 会抛 TypeError，
                 导致列表区域永远停留在加载转圈状态（历史 bug）。
因此脚本放在内容区之前，保证内联脚本执行时 Clinic 已就绪。 -->
            <!-- 核心通用组件（所有页面加载） -->
            <script src="/assets/js/components/ajax.js?v=' . self::assetVer('assets/js/components/ajax.js') . '"></script>
            <script src="/assets/js/components/modal.js?v=' . self::assetVer('assets/js/components/modal.js') . '"></script>
            <script src="/assets/js/components/deptpicker.js?v=' . self::assetVer('assets/js/components/deptpicker.js') . '"></script>
            <script src="/assets/js/components/depttree.js?v=' . self::assetVer('assets/js/components/depttree.js') . '"></script>
            <script src="/assets/js/components/toast.js?v=' . self::assetVer('assets/js/components/toast.js') . '"></script>
            <script src="/assets/js/components/feepop.js?v=' . self::assetVer('assets/js/components/feepop.js') . '"></script>
            <script src="/assets/js/components/push.js?v=' . self::assetVer('assets/js/components/push.js') . '"></script>
            <script src="/assets/js/components/smart_poller.js?v=' . self::assetVer('assets/js/components/smart_poller.js') . '"></script>
            <script src="/assets/js/components/infinite.js?v=' . self::assetVer('assets/js/components/infinite.js') . '"></script>
            <script src="/assets/js/components/print.js?v=' . self::assetVer('assets/js/components/print.js') . '"></script>
            <script src="/assets/js/components/theme.js?v=' . self::assetVer('assets/js/components/theme.js') . '"></script>
            <script src="/assets/js/components/dropdown.js?v=' . self::assetVer('assets/js/components/dropdown.js') . '"></script>
            <script src="/assets/js/components/notify.js?v=' . self::assetVer('assets/js/components/notify.js') . '"></script>
            <script src="/assets/js/components/import.js?v=' . self::assetVer('assets/js/components/import.js') . '"></script>
            <script src="/assets/js/components/selector.js?v=' . self::assetVer('assets/js/components/selector.js') . '"></script>
            <script src="/assets/js/components/validation.js?v=' . self::assetVer('assets/js/components/validation.js') . '"></script>
            <script src="/assets/js/components/datetime.js?v=' . self::assetVer('assets/js/components/datetime.js') . '"></script>
            <script src="/assets/js/components/datepicker.js?v=' . self::assetVer('assets/js/components/datepicker.js') . '"></script>
            <script src="/assets/js/components/historypanel.js?v=' . self::assetVer('assets/js/components/historypanel.js') . '"></script>
            <script src="/assets/js/components/patient.js?v=' . self::assetVer('assets/js/components/patient.js') . '"></script>
            <script src="/assets/js/components/ui.js?v=' . self::assetVer('assets/js/components/ui.js') . '"></script>
            <script src="/assets/js/components/naming.js?v=' . self::assetVer('assets/js/components/naming.js') . '"></script>
            <script src="/assets/js/components/conntest.js?v=' . self::assetVer('assets/js/components/conntest.js') . '"></script>
            <script src="/assets/js/components/drugform.js?v=' . self::assetVer('assets/js/components/drugform.js') . '"></script>
            <script src="/assets/js/components/chart.js?v=' . self::assetVer('assets/js/components/chart.js') . '"></script>
            <script src="/assets/js/components/critical.js?v=' . self::assetVer('assets/js/components/critical.js') . '"></script>
            <script src="/assets/js/components/authsync.js?v=' . self::assetVer('assets/js/components/authsync.js') . '"></script>
            <script src="/assets/js/components/app.js?v=' . self::assetVer('assets/js/components/app.js') . '"></script>
            <script src="/assets/js/components/nav.js?v=' . self::assetVer('assets/js/components/nav.js') . '"></script>
            <script>window.OPD_ICON_SVGS=' . json_encode(IconHelper::allSvg()) . ';</script>
            <script src="/assets/js/components/icons.js?v=' . self::assetVer('assets/js/components/icons.js') . '"></script>
            <script>
            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function () {
                    navigator.serviceWorker.register("/sw.js").catch(function () {});
                });
            }
            </script>
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
                            <button type="button" class="btn btn-outline btn-sm" data-sidebar-toggle style="padding:4px 10px">' . render_icon('nav:menu') . '</button>
                            ' . ($docTools
                                ? self::docWorkTitle($title !== '' ? $title : $hosp)
                                : '<div class="topbar-title">' . e($title !== '' ? $title : $hosp) . '</div>') . '
                        </div>
                        <div class="topbar-right">
                            ' . ($docTools ? self::docToolsBar() : '') . '
                            ' . ($needDeptWork ? self::deptToolsBar() : '') . '
                            <button type="button" class="btn btn-outline btn-sm" data-theme-btn title="切换主题">
                                <span class="theme-label">' . ($theme === 'auto' ? '自动模式' : ($theme === 'dark' ? '夜间模式' : '明亮模式')) . '</span>
                            </button>
                            <div style="position:relative">
                                <button type="button" class="btn btn-outline btn-sm" data-msg-bell title="站内消息">' . render_icon('emr:consult') . '
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
