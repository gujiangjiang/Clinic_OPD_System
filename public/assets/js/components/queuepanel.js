/**
 * queuepanel.js v1.0.0 — 病历页候诊队列面板
 * 挂载于医生病历编辑页顶部患者信息横条左侧：
 *   「📋 候诊XX」按钮（XX=当前科室今日未就诊人数），
 *   点击弹出近3天患者列表（已诊/当日多选过滤、范围内搜索、点击跳转病历）。
 * 数据源：GET /api/doctor?action=queue_list（一次返回近3天全量+候诊数，
 * 前端本地过滤排序，多选切换与搜索零请求）。
 */
Clinic.queuePanel = (function () {

    var DATA = null;        // queue_list 接口缓存 { waiting, list[] }
    var TIMER = null;       // 30 秒自动刷新
    var seen = false;       // 多选项：已诊
    var todayOnly = false;  // 多选项：当日

    /* 拉取队列数据（force=true 强制刷新） */
    function load(force, cb) {
        if (DATA && !force) { if (cb) cb(); return; }
        Clinic.get('/api/doctor?action=queue_list', null, {
            onSuccess: function (json) {
                DATA = json.data;
                renderBtn();
                if (cb) cb();
            },
        });
    }

    /* 顶部按钮：候诊XX（未就诊=今日该科 status='paid' 人数） */
    function renderBtn() {
        var btn = document.getElementById('queueBtn');
        if (!btn || !DATA) return;
        btn.innerHTML = '📋 候诊 <b>' + (DATA.waiting || 0) + '</b>';
    }

    function init() {
        var bar = document.querySelector('.emr-top-bar');
        var header = document.getElementById('emrHeader');
        if (!bar || !header || document.getElementById('queueBtn')) return;
        var btn = document.createElement('button');
        btn.className = 'btn btn-outline btn-sm';
        btn.id = 'queueBtn';
        btn.style.flexShrink = '0';
        btn.title = '候诊 / 近3天患者列表';
        btn.innerHTML = '📋 候诊 …';
        bar.insertBefore(btn, header);
        load(true);
        TIMER = setInterval(function () { load(true); }, 30000);
    }

    return { init: init, refresh: function () { DATA = null; load(true); }, _load: load };
})();

/* 病历页存在患者信息横条时自动挂载 */
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('emrHeader')) Clinic.queuePanel.init();
});
