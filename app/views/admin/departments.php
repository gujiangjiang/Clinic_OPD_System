<?php
/**
 * admin/departments.php — 科室管理
 * 说明：科室名称、类型（门诊/急诊）、挂号费；
 * 门诊科室需设置每日上午/下午号源数量，急诊无需号源。
 */
Router::title('科室管理');
?>
<div class="page-head">
    <div><div class="page-title">🏥 科室管理</div><div class="page-desc">门诊科室需设置上午/下午号源数量，急诊科室无需号源</div></div>
    <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openDeptForm(0)">＋ 新增科室</button></div>
</div>
<div class="card" style="margin-bottom:12px">
    <input class="input" placeholder="🔍 快速搜索科室" oninput="quickFilter(this.value,'deptList')">
</div>
<div class="card" id="deptList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
/* 快速搜索：按行文本过滤 + 动态计数（搜索时去掉「共」） */
function quickFilter(q, boxId) {
    q = q.trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#' + boxId + ' tbody tr').forEach(function (tr) {
        var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('deptCountDiv');
    if (cnt) cnt.textContent = q !== '' ? '科室 ' + n + ' 个' : '共 ' + n + ' 个科室';
}
Clinic.importer._reloads['dept'] = loadDeptList;
Clinic.importer.attach('dept', 'impBtns', '科室');
function loadDeptList() {
    Clinic.get('/api/admin?action=dept_list', null, {
        onSuccess: function (json) {
            document.getElementById('deptList').innerHTML = json.data.html;
        },
    });
}

function openDeptForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'dept_form', id: id || 0 }, { title: id ? '编辑科室' : '新增科室' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="deptSave">保存</button>';
        document.getElementById('deptSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'dept_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                type: document.getElementById('f_type').value,
                fee: document.getElementById('f_fee').value,
                am_quota: document.getElementById('f_am').value,
                pm_quota: document.getElementById('f_pm').value,
                status: document.getElementById('f_status').value,
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    loadDeptList();
                },
            });
        });
    });
}

/* 门诊显示号源输入，急诊隐藏 */
function toggleQuota() {
    var type = document.getElementById('f_type').value;
    document.getElementById('quotaRow').style.display = type === 'emergency' ? 'none' : '';
}

function delDept(id) {
    Clinic.modal.confirm('确定删除该科室？（已有挂号记录的科室不可删除，可改为停用）', function () {
        Clinic.ajax('/api/admin', { action: 'dept_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDeptList();
            },
        });
    });
}

loadDeptList();
</script>
