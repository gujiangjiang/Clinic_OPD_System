/**
 * ============================================================
 * infinite.js v1.1.0 — 通用无限滚动加载工具
 * ============================================================
 * 说明：把「滚动接近列表底部 → 自动加载下一页」收敛为统一方法，
 * 避免各页面反复重写滚动判定。自动识别滚动容器：
 *   1. 从目标元素向上查找 overflow-y:auto/scroll 的最近祖先（弹窗内
 *      固定高度列表、页面主内容区 .content 等均适用）；
 *   2. 未找到则回退 window（整页滚动）。
 * 用法：
 *   var isc = Clinic.infiniteScroll({
 *       el: 列表底部哨兵元素（或列表容器本身）,
 *       threshold: 40,               // 距底部多少 px 触发（默认 40）
 *       onNearBottom: function () {  // 接近底部时回调；返回 false 表示
 *                                    // 已无更多数据，自动停止监听
 *           if (!hasMore) return false;
 *           loadNextPage();
 *       },
 *   });
 *   isc.stop();                      // 需要时主动解除
 *   isc.check();                     // 手动触发一次判定（加载完成回调后
 *                                    // 调用：内容不满一屏时自动续加载）
 * ============================================================ */

window.Clinic = window.Clinic || {};

/**
 * 注册无限滚动
 * @param {object}   opts
 *   el          目标元素（滚动容器为其最近滚动祖先，或回退 window）
 *   threshold   距底部触发阈值 px（默认 40）
 *   onNearBottom 接近底部回调：返回 false 则停止监听（不再触发）
 * @return {{stop: function, check: function}}
 */
Clinic.infiniteScroll = function (opts) {
    opts = opts || {};
    var el = opts.el || null;
    var threshold = typeof opts.threshold === 'number' ? opts.threshold : 40;
    var onNearBottom = opts.onNearBottom || function () {};
    var stopped = false;

    /** 向上查找最近滚动容器（overflow-y / overflow 为 auto|scroll），无则 null */
    function findScroller(node) {
        var cur = node;
        while (cur && cur !== document.body && cur !== document.documentElement) {
            var st = window.getComputedStyle(cur);
            var oy = st.overflowY;
            var ox = st.overflow;
            if (/(auto|scroll)/i.test(oy) || (/(auto|scroll)/i.test(ox) && /(visible|clip)/i.test(oy))) {
                return cur;
            }
            cur = cur.parentNode;
        }
        return null;
    }

    var scroller = findScroller(el);
    var useWindow = !scroller;
    var target = useWindow ? window : scroller;

    /** 是否接近底部：与打印中心/诊断搜索一致的判定——仅在真正滚动到接近容器底部时触发。
     *  注意：不要在此加入「内容不满一屏视为接近底部」的自动连续加载逻辑，
     *  否则首屏数据不足一屏时会把后续页全部自动加载完（失去分段意义）。 */
    function nearBottom() {
        if (useWindow) {
            var doc = document.documentElement;
            return (window.pageYOffset || doc.scrollTop || 0) + window.innerHeight >= doc.scrollHeight - threshold;
        }
        return scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - threshold;
    }

    function fire() {
        if (stopped) return;
        if (!nearBottom()) return;
        if (onNearBottom() === false) stop();
    }

    function onScroll() { fire(); }

    function stop() {
        if (stopped) return;
        stopped = true;
        target.removeEventListener('scroll', onScroll);
        if (useWindow) window.removeEventListener('scroll', onScroll);
    }

    /** 手动触发一次判定（内容不满一屏时配合调用方加载完成回调实现连续加载） */
    function check() { fire(); }

    target.addEventListener('scroll', onScroll, { passive: true });
    return { stop: stop, check: check };
};

/**
 * 统一的分页列表封装：把「分页请求 + 滚动无限加载 + 追加渲染」收敛为一个调用，
 * 各处列表只需传入接口地址、每页数量与渲染函数，消除重复的页码/加载锁/追加逻辑。
 *
 * 用法：
 *   var list = Clinic.infiniteList({
 *       el: document.getElementById('list容器'),   // 必填：滚动容器（内部滚动）
 *       url: '/api/xxx?action=list&kw=...',         // 接口地址（会自动拼接 &page=&size=）
 *       pageSize: 20,                              // 每页数量（诊断 5-10 / 就诊 10-20 / 引用 20）
 *       threshold: 40,                             // 距底部触发阈值 px
 *       totalEl: document.getElementById('总数元素'),  // 可选：显示「共 N 条」
 *       emptyHtml: '<div class="empty">暂无数据</div>', // 可选：空态 HTML
 *       render: function (list, isFirst) {          // 必填：把一页数据渲染为 HTML
 *           return list.map(function (r) {          //   isFirst=true 首次渲染（可含表头）
 *               return '<div>...</div>';            //   追加到容器
 *           }).join('');
 *       },
 *       onSuccess: function (json) { }              // 可选：每次加载成功回调（可写业务状态）
 *       onError: function () { }                    // 可选：加载失败回调（可展示空态/重试提示）
 *   });
 *   list.reset();   // 重置到第一页（搜索条件变化时调用）
 *   list.stop();    // 解除监听
 *
 * @return {{reset: function, stop: function}}
 */
Clinic.infiniteList = function (opts) {
    opts = opts || {};
    var el = opts.el;
    var pageSize = typeof opts.pageSize === 'number' ? opts.pageSize : 20;
    var threshold = typeof opts.threshold === 'number' ? opts.threshold : 40;
    var totalEl = opts.totalEl || null;
    var emptyHtml = opts.emptyHtml || '<div class="empty">暂无数据</div>';
    var render = opts.render || function () { return ''; };
    var onSuccess = opts.onSuccess || null;

    var page = 0;
    var loading = false;
    var hasMore = true;
    var stopFn = null;

    function loadPage(p) {
        if (loading || !el) return;
        loading = true;
        // url 支持字符串（自动拼 page/size）或函数（返回完整地址，自行拼参）
        var url;
        if (typeof opts.url === 'function') url = opts.url(p, pageSize);
        else url = opts.url + (opts.url.indexOf('?') === -1 ? '?' : '&') + 'page=' + p + '&size=' + pageSize;
        Clinic.get(url, null, {
            onSuccess: function (json) {
                loading = false;
                var d = json.data || {};
                var list = d.list || [];
                if (p <= 1) {
                    el.innerHTML = '';
                    if (!list.length) {
                        el.innerHTML = emptyHtml;
                        hasMore = false;
                    }
                }
                if (list.length) {
                    var html = render(list, p <= 1);
                    if (typeof opts.append === 'function') {
                        // 自定义追加：表格类列表后续页仅返回行，由 append 插入已有 tbody
                        opts.append(el, html);
                    } else {
                        el.insertAdjacentHTML('beforeend', html);
                    }
                }
                hasMore = !!d.has_more;
                if (totalEl) totalEl.textContent = '共 ' + (d.total || 0) + ' 条';
                if (onSuccess) onSuccess(json, p);
            },
            onError: function () { loading = false; if (opts.onError) opts.onError(); },
        });
    }

    function fireLoad() {
        if (!hasMore) return false;   // 无更多：停止监听
        page++;
        loadPage(page);
    }

    stopFn = Clinic.infiniteScroll({
        el: el,
        threshold: threshold,
        onNearBottom: fireLoad,
    });

    loadPage(1);   // 首屏加载第一页

    return {
        /** 重置到第一页（搜索条件变化时调用） */
        reset: function () {
            page = 0; hasMore = true;
            loadPage(1);
        },
        /** 手动触发一次判定（内容不满一屏时配合加载完成回调实现连续加载） */
        check: function () { if (stopFn && stopFn.check) stopFn.check(); },
        stop: function () { if (stopFn && stopFn.stop) stopFn.stop(); },
    };
};