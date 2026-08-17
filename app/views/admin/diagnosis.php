<?php
/**
 * ============================================================
 * admin/diagnosis.php v1.1.0 — 诊断管理
 * ============================================================
 * 说明：诊断默认保存在 icd10 数据库中：
 *   诊断码（diagnosis_code）、诊断名称（diagnosis_name）、
 *   诊断拼音首字母（pinyin，快速检索）。
 * 支持按编码 / 名称 / 拼音首字母检索，可新增、编辑、删除；
 * 病历初步诊断（ICD10 联动）直接使用本库数据。
 * ============================================================ */
Router::title('诊断管理');
?>
<div class="page-head">
    <div><div class="page-title">📖 诊断管理</div><div class="page-desc">ICD10 诊断码 / 诊断名称 / 拼音首字母检索维护（病历诊断联动数据源）</div></div>
    <div class="flex gap-8">
        <input class="input" id="diagKw" placeholder="输入诊断码 / 名称 / 拼音首字母" style="width:240px" autocomplete="off">
        <button class="btn btn-primary btn-sm" onclick="loadDiag()">查询</button>
        <button class="btn btn-primary btn-sm" onclick="openDiagForm(0)">＋ 新增诊断</button>
    </div>
</div>
<div class="card" id="diagList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadDiag() {
    var kw = document.getElementById('diagKw').value.trim();
    Clinic.get('/api/icd10?action=list&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('diagList');
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">📖</div>未检索到诊断（可新增诊断）</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共 ' + list.length + ' 条诊断</div>' +
                '<div class="table-wrap"><table class="table"><thead><tr>' +
                '<th style="width:160px">诊断码（ICD10）</th><th>诊断名称</th><th>拼音首字母</th><th style="width:140px">操作</th>' +
                '</tr></thead><tbody>' +
                list.map(function (d) {
                    return '<tr>' +
                        '<td class="fw-600" style="font-family:monospace">' + d.code + '</td>' +
                        '<td>' + d.name + '</td>' +
                        '<td class="fs-12 text-muted" style="font-family:monospace">' + (d.pinyin || '—') + '</td>' +
                        '<td><div class="flex gap-4">' +
                        '<button class="btn btn-outline btn-sm" onclick="openDiagForm(' + d.id + ',\'' + d.code + '\',\'' + (d.name || '').replace(/'/g, "\\'") + '\',\'' + (d.pinyin || '').replace(/'/g, "\\'") + '\')">编辑</button>' +
                        '<button class="btn btn-outline btn-sm" onclick="delDiag(' + d.id + ',\'' + (d.name || '').replace(/'/g, "\\'") + '\')">删除</button>' +
                        '</div></td></tr>';
                }).join('') + '</tbody></table></div>';
        },
    });
}

/* 新增/编辑诊断（拼音首字母为空时自动生成） */
function openDiagForm(id, code, name, pinyin) {
    code = code || '';
    name = name || '';
    pinyin = pinyin || '';
    Clinic.modal.open(
        '<input type="hidden" id="f_id" value="' + id + '">' +
        '<div class="form-row">' +
        '  <div class="form-group"><label class="form-label">诊断码（ICD10）<span class="req">*</span></label>' +
        '    <input class="input" id="f_code" value="' + code + '" placeholder="如：J18.9" style="font-family:monospace"></div>' +
        '  <div class="form-group"><label class="form-label">诊断名称 <span class="req">*</span></label>' +
        '    <input class="input" id="f_name" value="' + name + '" placeholder="如：肺炎"></div>' +
        '</div>' +
        '<div class="form-group"><label class="form-label">拼音首字母（留空自动生成，用于快速检索）</label>' +
        '  <input class="input" id="f_pinyin" value="' + pinyin + '" placeholder="如：FY" style="font-family:monospace"></div>' +
        '<div class="fs-12 text-muted">保存后医生工作站病历【初步诊断】可直接检索到该诊断。</div>',
        {
            title: id ? '编辑诊断' : '新增诊断',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '保存', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var code2 = document.getElementById('f_code').value.trim().toUpperCase();
                        var name2 = document.getElementById('f_name').value.trim();
                        if (!code2) { Clinic.toast.warning('请填写诊断码'); return; }
                        if (!name2) { Clinic.toast.warning('请填写诊断名称'); return; }
                        Clinic.ajax('/api/icd10', {
                            action: 'save',
                            id: id,
                            code: code2,
                            name: name2,
                            pinyin: document.getElementById('f_pinyin').value.trim().toUpperCase(),
                        }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                loadDiag();
                            },
                        });
                    },
                },
            ],
        }
    );
}

function delDiag(id, name) {
    Clinic.modal.confirm('确定删除诊断【' + name + '】？', function () {
        Clinic.ajax('/api/icd10', { action: 'delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDiag();
            },
        });
    });
}

/* 回车查询 */
document.getElementById('diagKw').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') loadDiag();
});

loadDiag();
</script>
