/**
 * ============================================================
 * app.js v1.0.0 — 应用入口初始化
 * ============================================================
 * 说明：所有页面共用：
 * 1. 侧边栏展开/缩小切换（偏好跟随用户保存）
 * 2. 消息铃铛绑定
 * 3. 登出确认
 * 4. 公共数据属性（CSRF 注入见各页面）
 * 本文件是唯一全局入口，其余逻辑按部件拆分。
 * ============================================================ */

window.Clinic = window.Clinic || {};

/**
 * 全局初始化（在 DOMContentLoaded 后调用）
 */
Clinic.init = function () {
    bindSidebarToggle();
    bindLogout();
    initMessageBell();
    bindThemeSwitcher();
    bindNavActive();
};

/**
 * 侧边栏切换（展开 ⇄ 缩小）：
 * - 窄屏（<=900px）：抽屉式开关（.sidebar.open 滑入/滑出）
 * - 宽屏（>900px）：展开/缩小切换（.app.sidebar-mini，缩小仅保留图标），
 *   偏好保存到服务器（users.sidebar），下次登录任意设备均保持
 * - 病历书写页（body[data-sidebar-force]）强制缩小初始显示，
 *   可临时展开但不保存偏好
 */
function bindSidebarToggle() {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('.sidebar');
    const app = document.querySelector('.app');
    if (!toggle || !sidebar || !app) return;

    // 宽屏：按服务端注入的用户偏好恢复初始状态（无闪烁）
    const forced = document.body.getAttribute('data-sidebar-force') === '1';
    const pref = document.body.getAttribute('data-sidebar-pref') || 'expand';
    if (window.innerWidth > 900 && pref === 'mini') {
        app.classList.add('sidebar-mini');
    }

    toggle.addEventListener('click', function () {
        if (window.innerWidth <= 900) {
            // 窄屏：抽屉式开关
            sidebar.classList.toggle('open');
        } else {
            // 宽屏：展开 ⇄ 缩小（仅图标）
            const mini = !app.classList.contains('sidebar-mini');
            app.classList.toggle('sidebar-mini', mini);
            // 病历书写页仅临时切换，不覆盖用户偏好
            if (!forced) {
                Clinic.ajax('/api/auth', { action: 'sidebar', sidebar: mini ? 'mini' : 'expand' }, { loading: false });
            }
        }
    });
}

/**
 * 登出按钮绑定（带确认）
 */
function bindLogout() {
    const btn = document.querySelector('[data-logout]');
    if (!btn) return;
    btn.addEventListener('click', function () {
        Clinic.modal.confirm('确定要退出登录吗？', function () {
            window.location.href = '/api/auth?action=logout_page';
        });
    });
}

/**
 * 初始化消息铃铛（管理员/所有登录用户）
 */
function initMessageBell() {
    const bell = document.querySelector('[data-msg-bell]');
    if (!bell) return;
    Clinic.notify.init('[data-msg-badge]');
    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        Clinic.notify.openPanel();
    });
}

/**
 * 主题切换按钮（顶栏下拉）
 */
function bindThemeSwitcher() {
    const btn = document.querySelector('[data-theme-btn]');
    if (!btn) return;
    btn.addEventListener('click', function () {
        const cur = Clinic.theme.current();
        const next = cur === 'auto' ? 'light' : (cur === 'light' ? 'dark' : 'auto');
        const names = { auto: '自动模式', light: '明亮模式', dark: '夜间模式' };
        Clinic.theme.save(next);
        btn.setAttribute('data-title', names[next]);
        const label = btn.querySelector('.theme-label');
        if (label) label.textContent = names[next];
    });
}

/**
 * 导航高亮：根据当前路径标记 active
 */
function bindNavActive() {
    const path = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(function (el) {
        const href = el.getAttribute('data-href');
        if (href && path.indexOf(href) === 0) {
            el.classList.add('active');
        }
    });
}

/**
 * 页面内容加载完成的统一入口：
 * 供 AJAX 局部刷新后重新绑定组件
 */
Clinic.refresh = function (root) {
    const scope = root || document;
    // 为局部刷新后的内容重新绑定侧边栏之外的事件（由各页面自行处理）
};

/* 页面加载完成后初始化 */
document.addEventListener('DOMContentLoaded', function () {
    Clinic.init();
});
