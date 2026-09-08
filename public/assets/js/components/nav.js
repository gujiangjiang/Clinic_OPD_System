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
    /** 最近一次局部加载的完整地址（含 query，用于去重；EMR 切换患者时
        query 不同即视为不同目标，不能只比 path） */
    lastUrl: '',
    /** 防重复加载锁 */
    _busy: false,

    /** 局部导航加载遮罩：延迟显示，避免快速切换页面时一闪而过 */
    _navLoadingTimer: null,
    _navLoadingShown: false,

    /** 延迟显示加载遮罩（180ms 内完成则不显示，消除快速切换的闪烁） */
    _showNavLoading: function () {
        var that = this;
        this._navLoadingShown = false;
        clearTimeout(this._navLoadingTimer);
        this._navLoadingTimer = setTimeout(function () {
            that._navLoadingShown = true;
            Clinic.loading.show();
        }, 180);
    },
    /** 关闭加载遮罩（取消未到期的延迟显示，并撤销已显示的遮罩） */
    _hideNavLoading: function () {
        clearTimeout(this._navLoadingTimer);
        this._navLoadingTimer = null;
        if (this._navLoadingShown) {
            this._navLoadingShown = false;
            Clinic.loading.hide();
        }
    },

    /** 页面按需组件栈：partial 响应 data-needs 声明所需栈，
        layout 未全局加载的脚本由 nav.js 动态注入（保持「按页裁剪」的体积设计）
        emr 栈：病历/模板/审核预览共用；docTools 栈：仅医生工作站
        顺序即加载顺序（emr_* 子模块依赖 Clinic.emr 先就绪） */
    pageScripts: {
        emr: ['queuepanel_core', 'order', 'emreditor', 'emr_ctxmenu', 'eventbus', 'emr', 'emr_diag', 'emr_cert', 'emr_consult', 'emr_rules', 'emr_format', 'emr_template', 'emr_fee', 'emr_patient', 'emr_orders', 'emr_segments', 'emr_consent', 'vitals', 'queuepanel'],
        docTools: ['room_heartbeat', 'doctor_tools'],
        deptwork: ['queuepanel_core', 'deptwork', 'vitals'],
        adminItems: ['admin_items'],
    },

    /** 判断组件是否已加载（按全局命名空间标记） */
    isLoaded: function (name) {
        if (!window.Clinic) return false;
        switch (name) {
            case 'order': return !!Clinic.order;
            case 'emreditor': return !!Clinic.emrEditor;
            case 'emr_ctxmenu': return !!Clinic.emrMenu;
case 'emr_cert': return !!(Clinic.emr && Clinic.emr.cert);
case 'emr_consult': return !!(Clinic.emr && Clinic.emr.consult);
case 'emr_diag': return !!(Clinic.emr && Clinic.emr.diag);
            case 'eventbus': return !!Clinic.eventBus;
            case 'emr': return !!Clinic.emr;
            case 'emr_rules': return !!(Clinic.emr && Clinic.emr.rules);
            case 'emr_format': return !!(Clinic.emr && Clinic.emr.format);
            case 'emr_template': return !!(Clinic.emr && Clinic.emr.template);
            case 'emr_fee': return !!(Clinic.emr && Clinic.emr.fee);
            case 'emr_patient': return !!(Clinic.emr && Clinic.emr.patient);
            case 'emr_orders': return !!(Clinic.emr && Clinic.emr.orders);
            case 'emr_segments': return !!(Clinic.emr && Clinic.emr.segments);
            case 'emr_consent': return !!(Clinic.emr && Clinic.emr.consent);
            case 'queuepanel': return !!Clinic.queuePanel;
            case 'queuepanel_core': return !!Clinic.queuePanelCore;
            case 'vitals': return !!Clinic.vitals;
            case 'room_heartbeat': return !!Clinic.roomHeartbeat;
            case 'doctor_tools': return !!Clinic.docTools;
            case 'deptwork': return !!Clinic.deptwork;
            case 'admin_items': return !!(Clinic.adminItems);
            default: return false;
        }
    },

    /** 依据 data-needs 收集缺失组件并按序加载（顺序保证依赖） */
    loadNeeds: function (root) {
        var needs = (root.getAttribute('data-needs') || '').split(/\s+/).filter(Boolean);
        if (!needs.length) return Promise.resolve();
        var that = this;
        var missing = [];
        needs.forEach(function (n) {
            (that.pageScripts[n] || []).forEach(function (name) {
                if (!that.isLoaded(name)) missing.push(name);
            });
        });
        missing = missing.filter(function (v, i) { return missing.indexOf(v) === i; });
        if (!missing.length) return Promise.resolve();
        return this.loadScripts(missing);
    },

    /** 顺序加载外部脚本（前一个 onload 后再注入下一个，保证依赖先后） */
    loadScripts: function (names) {
        var chain = Promise.resolve();
        var ver = document.body.getAttribute('data-ver') || '';
        names.forEach(function (name) {
            chain = chain.then(function () {
                return new Promise(function (resolve, reject) {
                    var s = document.createElement('script');
                    s.src = '/assets/js/components/' + name + '.js?v=' + ver;
                    s.onload = resolve;
                    s.onerror = function () { reject(new Error('script load failed: ' + name)); };
                    document.head.appendChild(s);
                });
            });
        });
        return chain;
    },

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
                // 确认放弃离开：清除未保存标记，否则后续每次 SPA 导航都会再次弹确认
                if (window.Clinic && Clinic.emr && Clinic.emr.markClean) Clinic.emr.markClean();
                Clinic.nav.load(href);
            });
            return;
        }
        this.load(href);
    },

    /** 拉取并安装目标页 partial 内容 */
    load: function (href) {
        if (this._busy) return;
        if (href === this.lastUrl) return;
        this._busy = true;
        // 导航离开前关闭可能打开的模态窗（如会诊详情内「查看完整病历」入口）
        if (window.Clinic && Clinic.modal && Clinic.modal.close) Clinic.modal.close();
        this._showNavLoading();
        var sep = href.indexOf('?') === -1 ? '?' : '&';
        var that = this;
        fetch(href + sep + '_partial=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Partial': '1' },
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                // 临时容器解析：脚本不会自动执行，可安全提取
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var root = tmp.querySelector('.view-root');
                // 未登录 / 会话失效 / 独立页回退整页加载
                if (!root) {
                    that._hideNavLoading();
                    that._busy = false;
                    window.location.reload();
                    return;
                }
                // 先按需注入页面组件脚本（依赖就绪后）再安装内容与执行内联脚本；
                // 遮罩在内容安装完成后再关闭，避免露出旧内容造成闪烁
                that.loadNeeds(root).then(function () {
                    that._busy = false;
                    that.install(root);
                    that._hideNavLoading();
                    that.current = href.split('?')[0];
                    that.lastUrl = href;
                    that.markActive();
                    if (window.Clinic && Clinic.refresh) Clinic.refresh(document.querySelector('.content'));
                }).catch(function () {
                    that._hideNavLoading();
                    that._busy = false;
                    Clinic.toast.error('页面组件加载失败，请刷新重试');
                });
            })
            .catch(function () {
                that._hideNavLoading();
                that._busy = false;
                Clinic.toast.error('页面加载失败，请重试');
            });
    },

    /** 安装局部内容：替换 .content + 重执行脚本 + 更新标题/高亮（root 为解析后的 view-root 元素） */
    install: function (root) {
        var title = root.getAttribute('data-page-title') || '';
        var scripts = root.querySelectorAll('script');

        var contentEl = document.querySelector('.content');
        if (!contentEl) { window.location.reload(); return; }
        // 逐个移动非 script / 非顶栏补丁的子节点（保留原始节点引用，确保事件/状态完整）
        contentEl.innerHTML = '';
        var nodes = Array.prototype.slice.call(root.childNodes);
        nodes.forEach(function (n) {
            if (n.nodeType !== 1) { contentEl.appendChild(n); return; }
            if (n.tagName === 'SCRIPT' || n.classList.contains('view-topbar-patch')) return;
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
        // 页面专属顶栏元素同步：病历页注入医生工具组 + 科室胶囊，其他页移除
        this.syncTopbar(root);
        // EMR 病历页：重建病历工作台（emr.js 的 DOMContentLoaded 全局监听
        // 已在首屏执行过，此处主动调用 init 完成病历状态重建）
        if (document.getElementById('visitId') && window.Clinic && Clinic.emr && Clinic.emr.init) {
            Clinic.emr.init();
        }
        // EMR 页顶栏重建后，候诊按钮（#queueBtn）随旧顶栏被移除，需重新挂载
        if (document.getElementById('emrHeader') && window.Clinic && Clinic.queuePanel && Clinic.queuePanel.init) {
            Clinic.queuePanel.init();
        }
        // 离开病历患者页（无 #visitId）：清空 EMR 30s 大纲轮询定时器，
        // 防止其以空 visit_id 请求接口弹「就诊记录不存在」toast
        if (!document.getElementById('visitId') && window.__emrNavTimer) {
            clearInterval(window.__emrNavTimer);
            window.__emrNavTimer = null;
        }
    },

    /**
     * 同步页面专属顶栏元素（SPA 局部导航下顶栏常驻不重渲染）：
     * - 目标页带 [data-topbar-doc-tools]（医生工作站病历页）→ 注入工具组并切换
     *   标题为 doc-work-title（含科室胶囊）；doctor_tools 异步回填科室名
     * - 目标页无补丁 → 移除已注入的工具组并恢复普通标题
     */
    syncTopbar: function (root) {
        var bar = document.querySelector('.topbar-right');
        var tt = document.querySelector('.topbar-title');
        if (!bar || !tt) return;
        // 医生工作站工具组（叫号大屏绑定/工具箱）与科室工作台工具组（叫号/工具箱）共用一套注入逻辑
        var groups = ['[data-topbar-doc-tools]', '[data-topbar-dept-tools]'];
        groups.forEach(function (sel) {
            var hasTools = root.querySelector(sel);
            var curTools = bar.querySelector(sel);
            if (hasTools && !curTools) {
                var themeBtn = bar.querySelector('[data-theme-btn]');
                if (themeBtn) bar.insertBefore(hasTools, themeBtn);
                else bar.appendChild(hasTools);
            } else if (!hasTools && curTools) {
                curTools.parentNode.removeChild(curTools);
            }
        });
        if (root.querySelector('[data-topbar-doc-tools]')) {
            if (!tt.classList.contains('doc-work-title')) {
                tt.classList.add('doc-work-title');
                var dept = document.createElement('span');
                dept.className = 'doc-work-dept';
                dept.id = 'docWorkDept';
                dept.textContent = '加载科室…';
                tt.appendChild(dept);
            }
            // 重新进入病历页（doctor_tools 已加载、科室数据已就绪）时回填科室名
            if (window.Clinic && Clinic.docTools && Clinic.docTools.refreshTitle) {
                Clinic.docTools.refreshTitle();
            }
        } else if (tt.classList.contains('doc-work-title')) {
            tt.classList.remove('doc-work-title');
            var d = tt.querySelector('#docWorkDept');
            if (d) d.parentNode.removeChild(d);
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
