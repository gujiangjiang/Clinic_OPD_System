/**
 * ============================================================
 * smart_poller.js v1.0.0 — 弹性轮询管理器（SmartPoller）
 * ============================================================
 * 「推送优先、智能保底、状态自愈」混合架构的兜底轮询层：
 *  - 动态频率：推流健康（CONNECTED）时低频对齐（默认 60s）；
 *    推流断开/重连（DISCONNECTED/RECONNECTING）时自动升级为应急高频（默认 8s）；
 *  - Page Visibility：页面 hidden 立即挂起轮询（后台零空耗），
 *    切回 visible 立即执行一次静默同步并重置计时器；
 *  - 并发抑制：上一次请求未返回时跳过本次调度，杜绝请求堆积；
 *  - 与 push.js 状态广播（push:connected/disconnected/reconnecting）联动。
 * 用法：
 *   var poller = Clinic.smartPoller({
 *       url: '/api/deptwork?action=queue&...',   // 或函数返回完整地址
 *       interval: 60000,                          // 推流健康低频间隔
 *       emergencyInterval: 8000,                  // 推流断开应急间隔
 *       onSuccess: function (json) { render(json.data); },
 *   });
 *   poller.start();
 *   // poller.sync();   // 手动立即同步
 *   // poller.destroy(); // 解除监听与定时器
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.smartPoller = function (opts) {
    opts = opts || {};
    var cfg = {
        interval: typeof opts.interval === 'number' ? opts.interval : 60000,
        emergencyInterval: typeof opts.emergencyInterval === 'number' ? opts.emergencyInterval : 8000,
        url: opts.url || null,          // 字符串或函数（返回完整地址）
        fetch: opts.fetch || null,      // 自定义请求 (url, onSuccess, onError)
        onSuccess: opts.onSuccess || null,
        onError: opts.onError || null,
        immediate: opts.immediate !== false,   // start 时立即执行一次
    };
    var running = false;
    var inFlight = false;
    var hidden = false;
    var timer = null;
    // 初始应急态：不支持 SSE 或尚未收到连接成功事件 → 保守走应急高频
    var emergency = !(window.Clinic && Clinic.push && Clinic.push.supported());

    function currentInterval() {
        if (hidden) return 0;
        return emergency ? cfg.emergencyInterval : cfg.interval;
    }

    function schedule() {
        if (timer) clearTimeout(timer);
        timer = null;
        var iv = currentInterval();
        if (!running || iv <= 0) return;
        timer = setTimeout(function () { tick(); }, iv);
    }

    /** 执行一次拉取（成功/失败/并发抑制统一收敛到 done） */
    function tick() {
        if (!running || inFlight) { schedule(); return; }   // 并发抑制
        inFlight = true;
        var done = function () {
            inFlight = false;
            schedule();
        };
        var req = cfg.fetch || function (url, ok, err) {
            Clinic.get(url, null, { silent: true, onSuccess: ok, onError: err });
        };
        var url = typeof cfg.url === 'function' ? cfg.url() : cfg.url;
        // 纯 fetch 模式（调用方自带请求逻辑，如 refreshCallPanel）无需 url；
        // 仅当既无 fetch 又无 url 时才放弃本次调度
        if (!cfg.fetch && !url) { done(); return; }
        req(url, function (json) {
            if (cfg.onSuccess) { try { cfg.onSuccess(json); } catch (e) { console.error('[SmartPoller] onSuccess', e); } }
            done();
        }, function () {
            if (cfg.onError) { try { cfg.onError(); } catch (e) {} }
            done();
        });
    }

    /** 立即静默同步（visible 恢复 / 手动触发），不重置计时器逻辑 */
    function sync() {
        if (!running || inFlight) return;
        inFlight = true;
        var done = function () { inFlight = false; schedule(); };
        var req = cfg.fetch || function (url, ok, err) {
            Clinic.get(url, null, { silent: true, onSuccess: ok, onError: err });
        };
        var url = typeof cfg.url === 'function' ? cfg.url() : cfg.url;
        // 纯 fetch 模式（调用方自带请求逻辑，如 refreshCallPanel）无需 url；
        // 仅当既无 fetch 又无 url 时才放弃本次调度
        if (!cfg.fetch && !url) { done(); return; }
        req(url, function (json) {
            if (cfg.onSuccess) { try { cfg.onSuccess(json); } catch (e) {} }
            done();
        }, function () {
            if (cfg.onError) { try { cfg.onError(); } catch (e) {} }
            done();
        });
    }

    function onPushStatus(e) {
        var st = e && e.detail ? e.detail.status : null;
        var pushST = (window.Clinic && Clinic.push && Clinic.push.ST) || {};
        emergency = (st === pushST.DISCONNECTED || st === pushST.RECONNECTING)
            || !(window.Clinic && Clinic.push && Clinic.push.supported());
        schedule();
    }

    function onVisibility() {
        if (document.hidden) {
            hidden = true;
            if (timer) { clearTimeout(timer); timer = null; }   // 后台挂起，零空耗
        } else {
            hidden = false;
            sync();        // 切回可见：立即静默同步
            schedule();    // 并重置保底计时器
        }
    }

    return {
        start: function () {
            if (running) return;
            running = true;
            document.addEventListener('visibilitychange', onVisibility);
            document.addEventListener('push:connected', onPushStatus);
            document.addEventListener('push:disconnected', onPushStatus);
            document.addEventListener('push:reconnecting', onPushStatus);
            if (cfg.immediate) sync(); else schedule();
        },
        sync: function () { if (running) sync(); },
        stop: function () {
            running = false;
            if (timer) { clearTimeout(timer); timer = null; }
        },
        destroy: function () {
            this.stop();
            document.removeEventListener('visibilitychange', onVisibility);
            document.removeEventListener('push:connected', onPushStatus);
            document.removeEventListener('push:disconnected', onPushStatus);
            document.removeEventListener('push:reconnecting', onPushStatus);
        },
    };
};