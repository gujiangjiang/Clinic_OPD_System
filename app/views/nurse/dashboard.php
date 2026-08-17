<?php
/**
 * nurse/dashboard.php — 护士工作站
 * 说明：
 * 1. 待处置：开单时勾选【护士站处置】的项目（缴费后进入待处置）
 * 2. 今日患者：查看/录入生命体征（与医生站双向同步）、护理记录
 * 3. 患者护理记录页面点击患者姓名可修改患者信息
 */
Router::title('护士工作站');
?>
<div class="page-head">
    <div><div class="page-title">💉 护士工作站</div><div class="page-desc">处置执行、生命体征与护理记录</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="treat" onclick="switchTab('treat')">待处置</button>
    <button class="btn btn-outline btn-sm" data-tab="patients" onclick="switchTab('patients')">今日患者</button>
</div>

<div id="nurseBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    if (tab === 'treat') loadTreatments();
    else loadPatients();
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
                        '  <a href="javascript:void(0)" class="fs-16 fw-700" onclick="Clinic.patient.editModal(\'' + p.id_card + '\')">' + p.name + '</a>' +
                        '  <span class="fs-13 text-muted"> ' + p.gender + ' / ' + p.age + '岁</span>' +
                        '  <span class="badge badge-gray" style="margin-left:6px">' + p.dept_name + ' 第' + String(p.visit_seq).padStart(3, '0') + '号</span></div>' +
                        '<div class="fs-12 text-muted">' + p.flow_no + '</div></div>' +
                        '<div class="flex gap-8 mt-8">' +
                        '<button class="btn btn-outline btn-sm" onclick="openVitals(' + p.visit_id + ')">🌡️ 生命体征</button>' +
                        '<button class="btn btn-outline btn-sm" onclick="openNursing(' + p.visit_id + ')">📝 护理记录</button>' +
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
                '<div class="form-group"><label class="form-label">血压（收缩压/舒张压）</label><input class="input" id="vBP" placeholder="120/80" value="' + (v.bp_systolic ? v.bp_systolic + '/' + v.bp_diastolic : '') + '"></div>' +
                '<div class="form-group"><label class="form-label">心率（次/分）</label><input class="input" id="vHR" value="' + val(v.heart_rate) + '"></div></div>' +
                '<div class="form-row">' +
                '<div class="form-group"><label class="form-label">脉搏（次/分）</label><input class="input" id="vPulse" value="' + val(v.pulse) + '"></div>' +
                '<div class="form-group"><label class="form-label">血氧饱和度（%）</label><input class="input" id="vSpO2" value="' + val(v.spo2) + '"></div></div>' +
                '<div class="form-group"><label class="form-label">呼吸（次/分）</label><input class="input" id="vRR" value="' + val(v.respiration) + '"></div>' +
                '<div class="fs-12 text-muted">保存后医生工作站病历将自动同步显示。</div>',
                {
                    title: '生命体征录入',
                    size: 'modal-sm',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        {
                            text: '保存', cls: 'btn-primary', autoClose: false,
                            onClick: function () {
                                var bp = (document.getElementById('vBP').value || '').split('/');
                                Clinic.ajax('/api/nurse', {
                                    action: 'save_vitals',
                                    visit_id: visitId,
                                    bp_systolic: parseInt(bp[0], 10) || 0,
                                    bp_diastolic: parseInt(bp[1], 10) || 0,
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

switchTab('treat');
</script>
