/**
 * ============================================================
 * ajax.js v1.0.0 — AJAX 请求封装
 * ============================================================
 * 说明：网站核心通信层。所有接口统一走本封装：
 * 自动附加 CSRF Token、统一 JSON 解析、统一错误提示。
 * 页面局部刷新全部依赖此模块。
 * ============================================================ */

/* 全局命名空间 */
window.Clinic = window.Clinic || {};

/**
 * 全局 HTML 转义（单实现，各组件统一复用，避免多处重复定义）
 * @param {*} s 任意值，null/undefined 视为空串
 * @returns {string} 转义后的字符串
 */
Clinic.escHtml = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
};

/**
 * 发起 AJAX 请求
 * @param {string} url   接口地址（如 /api/register）
 * @param {object} data  参数（自动附加 csrf_token）
 * @param {object} opts  附加选项 { method, onSuccess, onError, loading }
 * @returns {Promise} 解析后的响应对象
 */
Clinic.ajax = function (url, data, opts) {
    opts = opts || {};
    const method = (opts.method || 'POST').toUpperCase();
    const formData = new FormData();

    // 自动附加 CSRF 令牌（页面初始化时注入到 body 的 data-csrf）
    if (method === 'POST') {
        formData.append('csrf_token', document.body.getAttribute('data-csrf') || '');
    }
    if (data) {
        Object.keys(data).forEach(function (k) {
            const v = data[k];
            if (v === undefined || v === null) return;
            if (Array.isArray(v)) {
                // 数组以 JSON 字符串传递（开单多选等场景）
                formData.append(k, JSON.stringify(v));
            } else {
                formData.append(k, v);
            }
        });
    }

    if (opts.loading) Clinic.loading.show();

    return fetch(url, {
        method: method,
        body: method === 'POST' ? formData : undefined,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (opts.loading) Clinic.loading.hide();
            if (!json.ok) {
                // 统一失败处理
                Clinic.toast.error(json.msg || '操作失败');
                if (opts.onError) opts.onError(json);
                return json;
            }
            if (opts.onSuccess) opts.onSuccess(json);
            return json;
        })
        .catch(function (err) {
            if (opts.loading) Clinic.loading.hide();
            Clinic.toast.error('网络请求失败，请检查网络连接');
            if (opts.onError) opts.onError({ ok: false, msg: '网络错误' });
            throw err;
        });
};

/**
 * GET 请求快捷方式（查询数据用）
 * @param {string} url  接口地址
 * @param {object} data 查询参数
 * @param {object} opts 附加选项
 */
Clinic.get = function (url, data, opts) {
    const qs = data ? '?' + new URLSearchParams(data).toString() : '';
    return fetch(url + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (!json.ok) {
                Clinic.toast.error(json.msg || '操作失败');
                if (opts && opts.onError) opts.onError(json);
                return json;
            }
            if (opts && opts.onSuccess) opts.onSuccess(json);
            return json;
        })
        .catch(function (err) {
            Clinic.toast.error('网络请求失败');
            if (opts && opts.onError) opts.onError({ ok: false });
            // 重抛使真实异常（如回调内 ReferenceError）可见于控制台，
            // 避免被吞后仅剩无定位信息的「网络请求失败」提示
            throw err;
        });
};

/**
 * 加载遮罩控制
 */
Clinic.loading = {
    el: null,
    count: 0,
    show: function () {
        this.count++;
        if (this.el) return;
        this.el = document.createElement('div');
        this.el.className = 'loading-mask';
        this.el.innerHTML = '<div class="spinner"></div>';
        document.body.appendChild(this.el);
    },
    hide: function () {
        this.count = Math.max(0, this.count - 1);
        if (this.count === 0 && this.el) {
            this.el.remove();
            this.el = null;
        }
    },
};
