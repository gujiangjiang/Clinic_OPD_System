<?php
/**
 * ============================================================
 * nurse/dashboard.php v1.1.0 — 护士工作站
 * ============================================================
 * 说明：
 * 1. 患者搜索：通过 ID号 / 身份证号 / 门诊流水号查询，可查看
 *    每次就诊历史，点击患者姓名可修改患者信息（需求21.1）
 * 2. 待处置：开单时勾选【护士站处置】的处置项目（缴费后进入）
 * 3. 今日患者：生命体征录入（与医生站双向同步）、护理记录、
 *    查看当日医生开具的检验/检查/处置/处方（需求21.2）
 * 4. 待执行医嘱：处方勾选【护士站执行】且缴费后进入，
 *    护士【等待执行】→【执行完成】并反馈医生工作站（需求21.4）
 */
Router::title('护士工作站');
?>
<div class="page-head">
    <div><div class="page-title">💉 护士工作站</div><div class="page-desc">处置执行、生命体征、护理记录与执行医嘱</div></div>
</div>

<!-- 患者搜索 -->
<div class="card" style="padding:12px 16px">
    <div class="flex gap-8">
        <input class="input" id="nurseKw" placeholder="输入患者ID / 身份证号 / 门诊流水号查询" style="flex:1" autocomplete="off">
        <button class="btn btn-primary btn-sm" onclick="searchPatients()">查询</button>
    </div>
    <div class="fs-12 text-muted mt-8">按患者ID或身份证查询时，可查看该患者所有既往就诊历史。</div>
</div>

<div class="flex gap-8 mb-12 mt-12">
    <button class="btn btn-primary btn-sm" data-tab="treat" onclick="switchTab('treat')">待处置</button>
    <button class="btn btn-outline btn-sm" data-tab="patients" onclick="switchTab('patients')">今日患者</button>
    <button class="btn btn-outline btn-sm" data-tab="med" onclick="switchTab('med')">待执行医嘱</button>
</div>

<div id="nurseBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    if (tab === 'treat') loadTreatments();
    else if (tab === 'med') loadMedOrders();
    else loadPatients();
}

/* ---------- 患者搜索（ID/身份证/流水号） ---------- */
function searchPatients() {
    var kw = document.getElementById('nurseKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入查询关键字'); return; }
    Clinic.get('/api/nurse?action=search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('nurseBody');
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未检索到患者就诊记录</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共检索到 ' + list.length + ' 次就诊，点击查看详情：</div>' +
                list.map(function (g) {
                    var v = g.visit, p = g.patient;
                    var name = p ? p.name : '';
                    var info = p ? (p.gender + ' / ' + p.age + '岁') : '';
                    return '<div class="card" style="padding:12px 16px;margin-bottom:8px;cursor:pointer" onclick="openVisitDetail(' + v.id + ')">' +
                        '<div class="flex-between">' +
                        '  <span class="fw-600 fs-15">' + name + ' <span class="fs-12 text-muted fw-400">' + info + '</span></span>' +
                        '  <span class="badge badge-primary">' + v.flow_no + '</span></div>' +
                        '<div class="fs-12 text-muted mt-4">' + v.first_dept_name + ' 第' + String(v.visit_seq).padStart(3, '0') + '号 ｜ ' + v.register_time +
                        ' ｜ <span class="badge ' + (v.status === 'finished' ? 'badge-success' : 'badge-gray') + '">' + visitStatusName(v.status) + '</span></div></div>';
                }).join('');
        },
    });
}
function visitStatusName(s) {
    var map = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '就诊完毕', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}

/* ---------- 就诊详情（患者信息 + 当日医嘱 + 生命体征 + 护理） ---------- */
function openVisitDetail(visitId) {
    Clinic.get('/api/nurse?action=visit_detail&visit_id=' + visitId, null, {
        onSuccess: function (json) {
            Clinic.modal.open(json.data.html, { title: '患者就诊详情', size: 'modal-lg' });
        },
    });
}

/* ---------- 待处置 ---------- */
function loadTreatments() {
    Clinic.get('/api/nurse?action=treatments', null, {
        onSuccess: function (json) {
            document.getElementById('nurseBody').innerHTML = json.data.html;
        },
    });
}

function completeTreatment(itemId) {
    Clinic.modal.confirm('确认该处置已执行完成？', function () {
        Clinic.ajax('/api/nurse', { action: 'complete', item_id: itemId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadTreatments();
            },
        });
    });
}

/* ---------- 待执行医嘱（护士站执行处方） ---------- */
function loadMedOrders() {
    Clinic.get('/api/nurse?action=med_orders', null, {
        onSuccess: function (json) {
            document.getElementById('nurseBody').innerHTML = json.data.html;
        },
    });
}

function medDetail(orderId) {
    Clinic.get('/api/nurse?action=med_detail&order_id=' + orderId, null, {
        onSuccess: function (json) {
            Clinic.modal.open(json.data.html, { title: '医嘱详情', size: 'modal-lg' });
        },
    });
}

function medStart(itemId) {
    Clinic.ajax('/api/nurse', { action: 'med_start', item_id: itemId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            loadMedOrders();
        },
    });
}

function medDone(itemId) {
    Clinic.modal.confirm('确认该医嘱已执行完成？执行后将反馈医生工作站。', function () {
        Clinic.ajax('/api/nurse', { action: 'med_done', item_id: itemId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadMedOrders();
            },
        });
    }, { title: '执行确认', okText: '执行完成' });
}

/* ---------- 今日患者 ---------- */
function loadPatients() {
    Clinic.get('/api/nurse?action=patients', null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('nurseBody');
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">📭</div>今日暂无就诊患者</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">今日就诊患者：' + list.length + ' 人（点击姓名可修改患者信息）</div>' +
                list.map(function (p) {
                    return '<div class="card" style="padding:14px 16px;margin-bottom:10px">' +
                        '<div class="flex-between">' +
                        '<div>' +
                        '  <a href="javascript:void(0)" class="fs-16 fw-700" onclick="Clinic.patient.editModal(\'' + p.patient_no + '\')">' + p.name + '</a>' +
                        '  <span class="fs-13 text-muted"> ' + p.gender + ' / ' + p.age + '岁</span>' +
                        '  <span class="badge badge-gray" style="margin-left:6px">' + p.dept_name + ' 第' + String(p.visit_seq).padStart(3, '0') + '号</span></div>' +
                        '<div class="fs-12 text-muted">' + p.flow_no + '</div></div>' +
                        '<div class="flex gap-8 mt-8">' +
                        '<button class="btn btn-outline btn-sm" onclick="openVitals(' + p.visit_id + ')">🌡️ 生命体征</button>' +
                        '<button class="btn btn-outline btn-sm" onclick="openNursing(' + p.visit_id + ')">📝 护理记录</button>' +
                        '<button class="btn btn-outline btn-sm" onclick="openVisitDetail(' + p.visit_id + ')">📋 医嘱</button>' +
                        '</div></div>';
                }).join('');
        },
    });
}

/* ---------- 生命体征（与医生站共用接口双向同步） ---------- */
function openVitals(visitId) {
    Clinic.get('/api/nurse?action=vitals&visit_id=' + visitId, null, {
        onSuccess: function (json) {
            var v = json.data.vitals || {};
            var val = function (x) { return x || ''; };
            Clinic.modal.open(
                '<div class="form-row">' +
                '<div class="form-group"><label class="form-label">收缩压（mmHg）</label><input class="input" id="vSys" type="number" min="0" value="' + val(v.bp_systolic) + '"></div>' +
                '<div class="form-group"><label class="form-label">舒张压（mmHg）</label><input class="input" id="vDia" type="number" min="0" value="' + val(v.bp_diastolic) + '"></div></div>' +
                '<div class="form-row">' +
                '<div class="form-group"><label class="form-label">心率（次/分）</label><input class="input" id="vHR" value="' + val(v.heart_rate) + '"></div>' +
                '<div class="form-group"><label class="form-label">脉搏（次/分）</label><input class="input" id="vPulse" value="' + val(v.pulse) + '"></div></div>' +
                '<div class="form-row">' +
                '<div class="form-group"><label class="form-label">血氧饱和度（%）</label><input class="input" id="vSpO2" value="' + val(v.spo2) + '"></div>' +
                '<div class="form-group"><label class="form-label">呼吸（次/分）</label><input class="input" id="vRR" value="' + val(v.respiration) + '"></div></div>' +
                '<div class="fs-12 text-muted">保存后医生工作站病历将自动同步显示。</div>',
                {
                    title: '生命体征录入',
                    size: 'modal-sm',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        {
                            text: '保存', cls: 'btn-primary', autoClose: false,
                            onClick: function () {
                                Clinic.ajax('/api/nurse', {
                                    action: 'save_vitals',
                                    visit_id: visitId,
                                    bp_systolic: parseInt(document.getElementById('vSys').value, 10) || 0,
                                    bp_diastolic: parseInt(document.getElementById('vDia').value, 10) || 0,
                                    heart_rate: document.getElementById('vHR').value,
                                    pulse: document.getElementById('vPulse').value,
                                    spo2: document.getElementById('vSpO2').value,
                                    respiration: document.getElementById('vRR').value,
                                }, {
                                    onSuccess: function (json) {
                                        Clinic.toast.success(json.msg);
                                        Clinic.modal.close();
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

/* ---------- 护理记录 ---------- */
function openNursing(visitId) {
    var loadList = function () {
        Clinic.get('/api/nurse?action=nursing_list&visit_id=' + visitId, null, {
            onSuccess: function (json) {
                var listBox = document.getElementById('nursingList');
                if (listBox) listBox.innerHTML = json.data.html;
            },
        });
    };
    Clinic.modal.open(
        '<div id="nursingList"><div class="text-muted fs-13">加载中…</div></div>' +
        '<div class="form-group mt-8"><label class="form-label">新增护理记录</label>' +
        '<textarea class="textarea" id="nursingContent" rows="3" placeholder="如：测量体温36.5℃，患者生命体征平稳"></textarea></div>',
        {
            title: '护理记录',
            size: 'modal-lg',
            buttons: [
                { text: '关闭', cls: 'btn-outline' },
                {
                    text: '添加记录', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var content = document.getElementById('nursingContent').value.trim();
                        if (!content) { Clinic.toast.warning('请输入护理记录内容'); return; }
                        Clinic.ajax('/api/nurse', { action: 'nursing_add', visit_id: visitId, content: content }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                document.getElementById('nursingContent').value = '';
                                loadList();
                            },
                        });
                    },
                },
            ],
        }
    );
    loadList();
}

/* 回车搜索 */
document.getElementById('nurseKw').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') searchPatients();
});

switchTab('treat');
</script>
