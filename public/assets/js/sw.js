/**
 * ============================================================
 * sw.js — PWA Service Worker（离线静态资源缓存）
 * ============================================================
 * 说明：为 PWA 桌面安装提供 Service Worker 支持：
 * 1. 仅缓存静态资源（css/js/字体/图片），缓存优先 + 后台刷新
 *    （stale-while-revalidate），资源带 ?v= 版本参数自动失效更新；
 * 2. 页面与接口请求一律直连网络，绝不缓存动态数据（病历/报告/消息等
 *    实时性要求高，避免陈旧数据）。
 * ============================================================ */

var CACHE = 'clinic-opd-v1';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) {
                if (k !== CACHE) return caches.delete(k);
                return null;
            }).filter(Boolean));
        })
    );
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') return;
    try {
        var url = new URL(req.url);
    } catch (e) { return; }
    if (url.origin !== location.origin) return;
    // 仅静态资源走缓存优先 + 后台刷新；页面/接口不拦截（走默认网络）
    if (/\.(css|js|woff2?|ttf|png|jpg|jpeg|gif|svg|webp|ico)(\?|$)/i.test(url.pathname)) {
        event.respondWith(
            caches.open(CACHE).then(function (cache) {
                return cache.match(req).then(function (hit) {
                    var next = fetch(req).then(function (res) {
                        if (res && res.status === 200) {
                            try { cache.put(req, res.clone()); } catch (err) { /* 忽略 */ }
                        }
                        return res;
                    }).catch(function () { return hit; });
                    return hit || next;
                });
            })
        );
    }
});
