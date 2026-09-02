/**
 * historypanel.js v1.0.0 — 患者就诊历史弹窗（左右两栏）
 * 布局参考检验组合管理器：顶部患者信息条，下方左右两栏：
 *   左：就诊列表（日期/时间/科室/序号 + 状态徽章）+ 顶部搜索；
 *   右：只读病历文档（复用 /api/print?action=record 渲染，所见即所得
 *       打印版式）+ 顶部操作条（打印电子病历 / 诊断证明三态按钮）。
 * 数据源：GET /api/patient?action=history（结构化 patient + visits）。
 * 全局入口：showPatientHistory(patientNo)（emr.js 头像 / 医生站患者卡）。
 */
Clinic.history = (function () {

    var PATIENT = null;   // 患者信息条
    var VISITS = [];      // 就诊列表
    var CUR = null;       // 当前选中就诊（visits 元素）

    function escHtml(s) { return Clinic.escHtml(s); }

    function open(patientNo) {
        Clinic.get('/api/patient?action=history&patient_no=' + encodeURIComponent(patientNo), null, {
            onSuccess: function (j) {
                PATIENT = j.data.patient;
                VISITS = j.data.visits || [];
                CUR = null;
                render();
            },
        });
    }

    /* 状态徽章（与原列表配色一致） */
    function statusBadge(v) {
        var cls = v.status === 'finished' ? 'badge-success' : (v.status === 'refunded' ? 'badge-gray' : 'badge-warning');
        return '<span class="badge ' + cls + '" style="font-size:11px">' + escHtml(v.status_name) + '</span>';
    }

    /* 左侧就诊条目：日期 时间 科室（序号）+ 状态 */
    function visitItemHtml(v) {
        var seq = String(v.visit_seq).padStart(3, '0');
        var moved = v.current_dept_name && v.current_dept_name !== v.dept_name;
        return '<div class="hp-visit" id="hpV_' + v.code + '" onclick="Clinic.history.select(\'' + v.code + '\')">' +
            '<div class="fs-13 text-muted">' + escHtml(v.date) + ' ' + escHtml(v.time) + '</div>' +
            '<div class="fs-13 fw-600">' + escHtml(v.dept_name) + '（' + seq + '）' +
            (moved ? '<span class="fs-12 text-muted fw-400">→ ' + escHtml(v.current_dept_name) + '</span>' : '') + '</div>' +
            '<div class="flex gap-4" style="margin-top:2px">' + statusBadge(v) +
            (v.has_cert ? '<span class="badge badge-gray" style="font-size:11px">证明</span>' : '') + '</div>' +
            '</div>';
    }

    function render() {
        var p = PATIENT || {};
        var items = VISITS.map(visitItemHtml).join('') ||
            '<div class="hp-empty">暂无就诊记录</div>';
        var html =
            '<div class="hp-top">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div class="emr-patient-avatar">👤</div>' +
            '    <div>' +
            '      <div class="fs-16 fw-700">' + escHtml(p.name) +
            '        <span class="badge badge-gray" style="margin-left:8px">' + escHtml(p.gender) + ' / ' + escHtml(p.age_fmt) + '</span></div>' +
            '      <div class="fs-13 text-muted mt-2">患者ID：' + escHtml(p.patient_no) + ' ｜ 身份证：' + escHtml(p.id_card) +
            (p.phone ? ' ｜ 电话：' + escHtml(p.phone) : '') + '</div>' +
            '    </div>' +
            '  </div>' +
            '</div>' +
            '<div class="hp-body">' +
            '  <div class="hp-left">' +
            '    <input class="input" id="hpSearch" placeholder="🔍 搜索日期 / 科室" autocomplete="off" oninput="Clinic.history.filter()">' +
            '    <div class="hp-list" id="hpList">' + items + '</div>' +
            '  </div>' +
            '  <div class="hp-right" id="hpRight">' +
            '    <div class="hp-empty">点击左侧任一次就诊，查看只读病历<br><span class="fs-12">支持打印电子病历与诊断证明补开</span></div>' +
            '  </div>' +
            '</div>';
        Clinic.modal.open(html, { title: '🗂️ 患者就诊历史', size: 'modal-xl' });
    }

    /* 左侧搜索：按日期/科室过滤 */
    function filter() {
        var q = (document.getElementById('hpSearch').value || '').trim().toLowerCase();
        document.querySelectorAll('#hpList .hp-visit').forEach(function (el) {
            el.style.display = el.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    }

    /* 选中就诊：右侧渲染操作条 + 只读病历文档 */
    function select(code) {
        CUR = null;
        VISITS.forEach(function (v) { if (v.code === code) CUR = v; });
        if (!CUR) return;
        document.querySelectorAll('#hpList .hp-visit').forEach(function (el) { el.classList.remove('active'); });
        var row = document.getElementById('hpV_' + code);
        if (row) row.classList.add('active');
        renderRight();
        if (!CUR.has_record) return;   // 无病历只显示操作条与提示
        Clinic.get('/api/print?action=record&visit_id=' + code, null, {
            onSuccess: function (j) {
                var box = document.getElementById('hpDocBody');
                // 文档样式全部以 .print-area 为作用域（print.css），必须包裹才生效
                if (box && CUR && CUR.code === code) box.innerHTML = '<div class="print-area">' + j.data.html + '</div>';
            },
            onError: function () { /* 无病历时后端 json_fail，忽略提示 */ },
        });
    }

    /* 诊断证明按钮三态：已开具=查看 ｜ 已诊毕未开具=补开 ｜ 未诊毕=新增
     * （本文件底部定义 openHistoryCertificate / archiveCertificateConfirm /
     *  printHistoryCertificate 全局函数，行为与原 emr.js 实现完全一致，
     *  全局加载避免医生工作站等页面函数未定义） */
    function certBtnHtml() {
        if (CUR.has_cert) {
            return '<button class="btn btn-outline btn-sm" onclick="printHistoryCertificate(\'' + CUR.code + '\')">📄 查看诊断证明</button>';
        }
        if (CUR.finished) {
            return '<button class="btn btn-outline btn-sm" onclick="archiveCertificateConfirm(' + (CUR.treated ? 'true' : 'false') + ',\'' + CUR.code + '\')">📄 补开诊断证明</button>';
        }
        return '<button class="btn btn-outline btn-sm" onclick="openHistoryCertificate(\'' + CUR.code + '\')">📄 新增诊断证明</button>';
    }

    function renderRight() {
        var right = document.getElementById('hpRight');
        if (!right || !CUR) return;
        var seq = String(CUR.visit_seq).padStart(3, '0');
        right.innerHTML =
            '<div class="hp-right-bar">' +
            '  <div class="fs-13 fw-600">' + escHtml(CUR.date) + ' ' + escHtml(CUR.time) + ' ｜ ' + escHtml(CUR.dept_name) + ' 第' + seq + '号</div>' +
            '  <div class="flex gap-8">' +
            (CUR.has_record
                ? '<button class="btn btn-primary btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' + CUR.code + '\',null,\'a5\')">🖨️ 打印电子病历</button>'
                : '<button class="btn btn-outline btn-sm" onclick="Clinic.toast.warning(\'该次就诊病历尚未保存\')">🖨️ 打印电子病历</button>') +
            '    ' + certBtnHtml() +
            '  </div>' +
            '</div>' +
            '<div class="hp-doc">' +
            (CUR.has_record
                ? '<div class="hp-doc-body" id="hpDocBody"><div class="hp-loading"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto 8px"></div>病历加载中…</div></div>'
                : '<div class="hp-empty">该次就诊病历尚未保存<br><span class="fs-12">无病历内容可显示</span></div>') +
            '</div>';
        // 只读文档防复制：禁右键菜单与文本选择（操作条/左侧列表不受影响）
        var doc = right.querySelector('.hp-doc');
        if (doc) {
            doc.addEventListener('contextmenu', function (e) { e.preventDefault(); });
            doc.addEventListener('selectstart', function (e) { e.preventDefault(); });
            doc.addEventListener('dragstart', function (e) { e.preventDefault(); });
        }
    }

    return { open: open, select: select, filter: filter };
})();

/* 全局入口：兼容 emr.js 患者头像点击 / 医生工作站患者卡（原各视图内联定义已移除） */
function showPatientHistory(patientNo) {
    Clinic.history.open(patientNo);
}

/* ============================================================
 * 诊断证明补开/查看（就诊历史弹窗内按钮调用）
 * 原实现位于 emr.js（仅 EMR 页面加载），导致医生工作站等页面
 * 「补开诊断证明」报 archiveCertificateConfirm is not defined。
 * 迁至全局加载的 historypanel.js：若当前页面已加载 Clinic.emr
 * （病历编辑页），复用其 certificateModal；否则用内置实现，
 * 两者行为一致（已开具=查看/打印，未开具=编辑/开具并打印）。
 * ============================================================ */
function hpCertificateModal(visitId, title, warnOnIssued) {
    if (window.Clinic && Clinic.emr && typeof Clinic.emr.certificateModal === 'function') {
        Clinic.emr.certificateModal(visitId, title, null, !!warnOnIssued);
        return;
    }
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var text = function (html) {
        var t = document.createElement('div');
        t.innerHTML = html || '';
        return t.textContent.trim();
    };
    Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
        onSuccess: function (j) {
            var r = j.data.record || {};
            var issued = !!j.data.has_certificate;
            var cert = j.data.certificate || {};
            var cs = j.data.certificate || {};
            var cs2 = j.data.cert_summary || {};
            var pick = function (a, b, c) { return (a && String(a).trim()) || (b && String(b).trim()) || c || ''; };
            var cc = text(pick(cs.chief_complaint, cs2.chief_complaint, r.chief_complaint));
            var pi = text(pick(cs.present_illness, cs2.present_illness, r.present_illness));
            var diag = pick(cs.preliminary_diagnosis, cs2.preliminary_diagnosis, r.preliminary_diagnosis);

            var rows = [];
            if (issued) {
                rows.push('<div><strong>证明号：</strong>' + esc(cert.cert_no || '') + '</div>');
                rows.push('<div class="mt-4"><strong>开具时间：</strong>' + esc(cert.created_at || '') + '</div>');
            }
            rows.push('<div' + (rows.length ? ' class="mt-4"' : '') + '><strong>主诉：</strong>' + esc(cc) + '</div>');
            rows.push('<div class="mt-4"><strong>现病史：</strong>' + esc(pi) + '</div>');
            rows.push('<div class="mt-4"><strong>初步诊断：</strong>' + esc(diag) + '</div>');
            var summary =
                '<div class="fs-13 mb-8" style="border:1px solid var(--border);border-radius:8px;padding:10px">' +
                rows.join('') + '</div>';

            if (issued) {
                if (warnOnIssued) Clinic.toast.warning('该次就诊已开具过诊断证明');
                Clinic.modal.open(
                    summary +
                    '<div class="form-group"><label class="form-label">医生建议</label>' +
                    '<textarea class="textarea" rows="3" disabled ' +
                    'style="background:var(--bg);cursor:default;resize:none;">' +
                    esc(cert.content || '') + '</textarea></div>',
                    {
                        title: title,
                        size: 'modal-sm',
                        buttons: [
                            { text: '关闭', cls: 'btn-outline' },
                            {
                                text: '🖨️ 打印', cls: 'btn-success',
                                onClick: function () {
                                    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                },
                            },
                        ],
                    }
                );
                return;
            }

            if (!cc || !pi || !diag) {
                Clinic.toast.warning('该次就诊病历不完整（缺少主诉/现病史/初步诊断），无法开具诊断证明');
                return;
            }
            Clinic.modal.open(
                '<div class="fs-13 text-muted mb-8">将自动引用该次就诊病历，医生建议请手动填写：</div>' +
                summary +
                '<div class="form-group"><label class="form-label">医生建议</label>' +
                '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
                {
                    title: title,
                    size: 'modal-sm',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        {
                            text: '开具并打印', cls: 'btn-success', autoClose: false,
                            onClick: function () {
                                var content = document.getElementById('certContent').value.trim();
                                if (!content) { Clinic.toast.warning('请填写医生建议'); return; }
                                Clinic.ajax('/api/record', {
                                    action: 'certificate', visit_id: visitId, content: content,
                                }, {
                                    onSuccess: function () {
                                        Clinic.toast.success('诊断证明已开具');
                                        Clinic.modal.close();
                                        Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                    },
                                });
                            },
                        },
                    ],
                }
            );
        },
    });
}

/* 全局：归档病历补开诊断证明确认（就诊历史「补开」按钮调用）
 * 提示语区分是否接诊过该患者，确认后打开补开表单 */
function archiveCertificateConfirm(treated, visitId) {
    var msg = '该病历已经归档' +
        (treated ? '' : '，且您未接诊过该病人') +
        (treated ? '，是否补开诊断证明？' : '，是否确认为该患者开具诊断证明？');
    Clinic.modal.confirm(msg, function () {
        hpCertificateModal(visitId, '补开诊断证明', true);
    });
}

/* 全局：未开具时补开（与开具共用弹窗，仅标题不同） */
function openHistoryCertificate(visitId) {
    hpCertificateModal(visitId, '补开诊断证明', true);
}

/* 全局：已开具时查看/再次打印 */
function printHistoryCertificate(visitId) {
    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
}
