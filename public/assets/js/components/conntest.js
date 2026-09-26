/**
 * ============================================================
 * conntest.js v1.0.0 — 连接测试统一组件（数据库 / 缓存）
 * ============================================================
 * 说明：安装向导与系统设置（数据库中心/缓存与性能）共用同一套连接测试交互，
 * 避免各页重复编写测试按钮与请求逻辑：
 *   - Clinic.connTest.affix(inputHtml, btnId, text)  输入框右端内嵌测试按钮
 *     （输入框与按钮融为一体，非突兀的独立按钮）
 *   - Clinic.connTest.affixBtn(btnId, text)          仅生成内嵌按钮
 *   - Clinic.connTest.run({url, data, btnId, msgId}) 发起测试并就地反馈
 * 后端统一走 ConnectionTester（app/core/ConnectionTester.php）。
 * 依赖：ajax.js（Clinic.ajax / Clinic.escHtml）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.connTest = (function () {

    /** 内嵌测试按钮（置于 .input-affix 容器右端，与输入框视觉一体） */
    function affixBtn(btnId, text) {
        return '<button type="button" class="input-affix-btn" id="' + btnId + '" title="测试连接可用性">' +
            (text || '测试') + '</button>';
    }

    /** 将输入框包裹为「内嵌测试按钮」容器；inputHtml 应为 <input class="input" ...> */
    function affix(inputHtml, btnId, text) {
        return '<span class="input-affix">' + inputHtml + affixBtn(btnId, text) + '</span>';
    }

    /**
     * 执行连接测试
     * @param {object} o { url, data, btnId, msgId, okText, onDone }
     */
    function run(o) {
        o = o || {};
        var btn = o.btnId ? document.getElementById(o.btnId) : null;
        var msg = o.msgId ? document.getElementById(o.msgId) : null;
        if (msg) msg.innerHTML = '<span class="text-muted">测试中…</span>';
        if (btn && btn.disabled) return;
        if (btn) { btn.disabled = true; btn.classList.add('testing'); }
        var done = function () {
            if (btn) { btn.disabled = false; btn.classList.remove('testing'); }
            if (o.onDone) o.onDone();
        };
        Clinic.ajax(o.url, o.data || {}, {
            loading: false,
            silent: true,    // 网络层失败静默
            noToast: true,   // 业务失败也以行内提示为主（避免与行内提示重复）
            onSuccess: function (json) {
                if (msg) msg.innerHTML = '<span class="text-success">✓ ' + Clinic.escHtml(json.msg || '连接成功') + '</span>';
                done();
            },
            onError: function (x, json) {
                var m = (x && x.msg) || (json && json.msg) || '连接失败';
                if (msg) msg.innerHTML = '<span class="text-danger">✗ ' + Clinic.escHtml(m) + '</span>';
                done();
            },
        });
    }

    return { affix: affix, affixBtn: affixBtn, run: run };
})();
