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