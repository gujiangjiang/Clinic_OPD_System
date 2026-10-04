/**
 * ============================================================
 * theme.js v1.0.0 — 主题切换（明亮/夜间/自动）
 * ============================================================
 * 说明：
 * 1. 用户偏好 auto/light/dark 保存在服务器（用户设置）
 * 2. 页面加载时读取 body 的 data-theme 初始值（服务端输出）
 * 3. auto 模式跟随系统 prefers-color-scheme
 * 4. 切换时无需刷新页面，即时生效
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.theme = (function () {
    /** 当前偏好 */
    let pref = 'auto';

    /**
     * 根据偏好计算实际主题
     * @param {string} p auto/light/dark
     * @returns {string} light/dark
     */
    function resolve(p) {
        if (p === 'auto') {
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark' : 'light';
        }
        return p;
    }

    /**
     * 应用主题到页面
     * @param {string} p 偏好
     */
    function apply(p) {
        pref = p;
        document.body.setAttribute('data-theme', resolve(p));
        // 同步系统主题变化（auto 模式实时跟随）
    }

    /**
     * 初始化：读取服务端注入的偏好
     */
    function init() {
        const saved = document.body.getAttribute('data-theme-pref') || 'auto';
        apply(saved);
        // auto 模式下监听系统主题变化
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                if (pref === 'auto') apply('auto');
            });
        }
    }

    /**
     * 保存偏好到服务器
     * @param {string} p 偏好
     */
    function save(p) {
        apply(p);
        Clinic.ajax('/api/auth', { action: 'theme', theme: p }, { loading: false })
            .then(function (json) {
                if (json.ok) Clinic.toast.success('主题设置已保存');
            });
    }

    return { init: init, apply: apply, save: save, current: function () { return pref; } };
})();

/* 页面加载完成后初始化主题 */
document.addEventListener('DOMContentLoaded', function () {
    Clinic.theme.init();
});
