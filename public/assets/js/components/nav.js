/**
 * ============================================================
 * nav.js v1.0.0 — SPA 局部刷新导航
 * ============================================================
 * 说明：点击侧边栏菜单 / 站内链接时，不再整页重载——
 * 通过 fetch 拉取目标页的 partial 内容（Router 局部渲染），
 * 替换 .content 内容区并重执行内联脚本，地址栏保持不变。
 * 技术要点：
 * 1. 内联脚本重执行：视图脚本依赖全局 Clinic.* 与页面 DOM，
 *    先注入内容再逐条执行脚本；执行期间捕获新注册的
 *    DOMContentLoaded 回调并统一触发（视图脚本多采用该写法）。
 * 2. 独立页（登录/安装/落地页/退出/叫号屏）与外链始终整页跳转。
 * 3. EMR 页：局部加载后自动调用 Clinic.emr.init() 重建病历状态；
 *    离开前用 Clinic.emr.isDirty() 拦截未保存修改。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.nav = {
    /** 当前局部导航路径（用于侧边栏高亮，不含 query） */
    current: '',
    /** 防重复加载锁 */
    _busy: false,

    /** 需整页加载的地址（独立页 / 外链 / 接口 / 文件下载） */
    isFullPage: function (href) {
        if (!href || href.charAt(0) !== '/') return true;
        return href === '/' ||
            href.indexOf('/login') === 0 ||
            href.indexOf('/install') === 0 ||
            href.indexOf('/logout') === 0 ||
            href.indexOf('/doctor/call') === 0 ||
            href.indexOf('/api/') === 0 ||
            href.indexOf('/assets/') === 0;
    },

    /** 统一跳转入口：独立页整页跳转；站内页局部刷新（含 EMR 脏数据拦截） */
    go: function (href) {
        if (this.isFullPage(href)) { location.href = href; return; }
        // EMR 未保存修改拦截：确认后继续（queuepanel 等已有前置校验的场景
        // 直接调用 load() 避免二次确认）
        if (window.Clinic && Clinic.emr && Clinic.emr.isDirty && Clinic.emr.isDirty()) {
            Clinic.modal.confirm('当前病历有未保存的修改，确定离开吗？', function () {
                Clinic.nav.load(href);
            });
            return;
        }
        this.load(href);
    },

    /** 拉取并安装目标页 partial 内容 */
    load: function (href) {
        if (this._busy) return;
        if (href === this.current + location.search) return;
        this._busy = true;
        Clinic.loading.show();
        var sep = href.indexOf('?') === -1 ? '?' : '&';
        var that = this;
        fetch(href + sep + '_partial=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Partial': '1' },
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                Clinic.loading.hide();
                that._busy = false;
                that.install(html);
                that.current = href.split('?')[0];
                that.markActive();
                if (window.Clinic && Clinic.refresh) Clinic.refresh(document.querySelector('.content'));
            })
            .catch(function () {
                Clinic.loading.hide();
                that._busy = false;
                Clinic.toast.error('页面加载失败，请重试');
            });
    },

    /** 安装局部内容：替换 .content + 重执行脚本 + 更新标题/高亮 */
    install: function (html) {
        // 临时容器解析：脚本不会自动执行，可安全提取
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var root = tmp.querySelector('.view-root');
        // 未登录 / 会话失效 / 独立页回退整页加载
        if (!root) {
            window.location.reload();
            return;
        }
        var title = root.getAttribute('data-page-title') || '';
        var scripts = root.querySelectorAll('script');

        var contentEl = document.querySelector('.content');
        if (!contentEl) { window.location.reload(); return; }
        // 逐个移动非 script 子节点（保留原始节点引用，确保事件/状态完整）
        contentEl.innerHTML = '';
        var nodes = Array.prototype.slice.call(root.childNodes);
        nodes.forEach(function (n) {
            if (n.nodeType === 1 && n.tagName === 'SCRIPT') return;
            contentEl.appendChild(n);
        });
        // 执行内联脚本（捕获其新注册的 DOMContentLoaded 回调）
        this.execScripts(scripts);

        // 更新页面标题与顶栏标题
        if (title) {
            document.title = (document.body.getAttribute('data-hosp') || '门诊一体化系统') + ' - ' + title;
            var tt = document.querySelector('.topbar-title');
            if (tt) {
                var first = tt.firstChild;
                if (first && first.nodeType === 3) {
                    first.textContent = title;
                } else {
                    tt.insertBefore(document.createTextNode(title), tt.firstChild);
                }
            }
        }
        window.scrollTo(0, 0);
        // EMR 病历页：重建病历工作台（emr.js 的 DOMContentLoaded 全局监听
        // 已在首屏执行过，此处主动调用 init 完成病历状态重建）
        if (document.getElementById('visitId') && window.Clinic && Clinic.emr && Clinic.emr.init) {
            Clinic.emr.init();
        }
    },

    /**
     * 重执行局部内容的内联脚本：
     * - 外链脚本（带 ?v= 版本号的组件库）已由 layout 全局加载，跳过；
     * - 通过动态 script 元素同步执行内联代码（全局作用域，var/function 进入 window）；
     * - 执行期间捕获视图内新注册的 document.addEventListener('DOMContentLoaded', fn)，
     *   全部执行完后统一触发——避免重复触发全局已注册的旧回调。
     */
    execScripts: function (scripts) {
        var captured = [];
        var origAdd = document.addEventListener.bind(document);
        document.addEventListener = function (type, fn) {
            if (type === 'DOMContentLoaded') { captured.push(fn); return; }
            return origAdd(type, fn);
        };
        try {
            Array.prototype.forEach.call(scripts, function (s) {
                if (s.src) return;
                var code = s.textContent || '';
                if (!code.trim()) return;
                var el = document.createElement('script');
                el.textContent = code;
                document.head.appendChild(el);
                document.head.removeChild(el);
            });
        } finally {
            document.addEventListener = origAdd;
        }
        captured.forEach(function (fn) {
            try { fn(); } catch (e) { console.error(e); }
        });
    },

    /** 侧边栏导航高亮（基于当前局部导航路径） */
    markActive: function () {
        var path = this.current;
        document.querySelectorAll('.nav-item').forEach(function (el) {
            var href = el.getAttribute('data-href');
            var on = href && (path === href || path.indexOf(href + '/') === 0);
            el.classList.toggle('active', on);
        });
    },

    /** 事件委托：拦截站内 <a> 点击（侧边栏菜单 / 内容区内链），交由局部刷新处理 */
    bind: function () {
        var that = this;
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) return;
            var href = a.getAttribute('href');
            if (a.target === '_blank' || a.hasAttribute('download')) return;
            if (href === '#') return;
            if (that.isFullPage(href)) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            that.go(href);
        });
    },
};

document.addEventListener('DOMContentLoaded', function () {
    Clinic.nav.current = window.location.pathname;
    Clinic.nav.bind();
});
