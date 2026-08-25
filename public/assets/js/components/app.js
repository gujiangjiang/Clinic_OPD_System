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
        // 精确匹配或按路径段前缀匹配（避免 /admin/drugs 误匹配 /admin/drugsettings）
        if (href && (path === href || path.indexOf(href + '/') === 0)) {
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

/* ==================== 三级树折叠/展开 ====================
 * 折叠按钮：<button class="tree-toggle" data-toggle="容器id">−</button>
 * 容器：id 对应的 .send-grp-children（默认可内联 display:none 折叠）
 */
window.treeToggle = function (btn) {
    var target = document.getElementById(btn.getAttribute('data-toggle'));
    if (!target) return;
    var show = target.style.display === 'none';
    target.style.display = show ? '' : 'none';
    btn.textContent = show ? '−' : '+';
};

/* ==================== 三级树搜索定位 ====================
 * 用法：Clinic.treeSearch({ input, res, tree, itemSel })
 *   input/res/tree 均可为 id 或元素；itemSel 为三级项选择器（默认 .send-user）。
 * 输入关键词 → 短列表展示匹配项 → 点击后滚动定位到树中对应项并高亮闪烁。
 */
Clinic.treeSearch = function (cfg) {
    var input = typeof cfg.input === 'string' ? document.getElementById(cfg.input) : cfg.input;
    var res = typeof cfg.res === 'string' ? document.getElementById(cfg.res) : cfg.res;
    var tree = typeof cfg.tree === 'string' ? document.querySelector(cfg.tree) : cfg.tree;
    if (!input || !res || !tree) return;
    var timer = null;
    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        if (timer) clearTimeout(timer);
        if (q === '') { res.innerHTML = ''; res.style.display = 'none'; return; }
        timer = setTimeout(function () {
            var hits = [];
            tree.querySelectorAll(cfg.itemSel || '.send-user').forEach(function (el) {
                var lab = el.closest('label') || el;   // 定位/高亮/文案以整行为准（兼容 checkbox 选择器）
                var txt = lab.textContent.trim();
                if (txt.toLowerCase().indexOf(q) !== -1) hits.push({ label: lab, text: txt });
            });
            res.innerHTML = hits.length
                ? hits.map(function (h, i) {
                    return '<div class="tree-search-item" data-i="' + i + '">' +
                        h.text.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
                }).join('')
                : '<div class="fs-12 text-muted" style="padding:6px 10px">无匹配项</div>';
            res.style.display = '';
            res.querySelectorAll('.tree-search-item').forEach(function (el, i) {
                el.addEventListener('click', function () {
                    res.style.display = 'none';
                    input.value = '';
                    var lab = hits[i].label;
                    // 自动展开所有折叠的祖先容器（并同步其 −/+ 按钮）
                    var anc = lab.parentElement;
                    while (anc && anc !== tree) {
                        if (anc.classList.contains('send-grp-children') && anc.style.display === 'none') {
                            anc.style.display = '';
                            var tbtn = tree.querySelector('.tree-toggle[data-toggle="' + anc.id + '"]');
                            if (tbtn) tbtn.textContent = '−';
                        }
                        anc = anc.parentElement;
                    }
                    tree.querySelectorAll('.tree-flash').forEach(function (x) { x.classList.remove('tree-flash'); });
                    lab.classList.add('tree-flash');
                    lab.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });
        }, 200);
    });
};

/* 页面加载完成后初始化 */
document.addEventListener('DOMContentLoaded', function () {
    Clinic.init();
});
