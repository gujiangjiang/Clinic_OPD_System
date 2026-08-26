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
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="deptSearchKw" placeholder="🔍 快速搜索科室" style="width:220px" oninput="applyDeptFilter()">
        <span class="flex gap-4" id="deptTypeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-dtype="clinic,emergency" onclick="deptTypeFilter(this,'clinic,emergency')">临床</button>
            <button class="btn btn-sm btn-outline" data-dtype="clinic" onclick="deptTypeFilter(this,'clinic')">门诊</button>
            <button class="btn btn-sm btn-outline" data-dtype="emergency" onclick="deptTypeFilter(this,'emergency')">急诊</button>
            <button class="btn btn-sm btn-outline" data-dtype="tech" onclick="deptTypeFilter(this,'tech')">医技</button>
            <button class="btn btn-sm btn-outline" data-dtype="other" onclick="deptTypeFilter(this,'other')">其他</button>
        </span>
    </div>
</div>
<div class="card" id="deptList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
/* 默认「临床」（门诊+急诊）：医技/其他为叫号大屏专用科室，
   不混入默认列表，仅经「医技/其他」Tab 查看管理 */
var DEPT_TYPE = 'clinic,emergency';
var DEPT_TYPE_NAMES = { 'clinic,emergency': '临床科室', 'clinic': '门诊科室', 'emergency': '急诊科室', 'tech': '医技科室', 'other': '其他科室' };
/* 类型子标签（支持逗号分隔多类型）+ 快速搜索组合过滤，计数动态联动 */
function deptTypeFilter(btn, t) {
    DEPT_TYPE = t;
    document.querySelectorAll('#deptTypeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-dtype') || '') === t ? 'btn-primary' : 'btn-outline');
    });
    applyDeptFilter();
}
function applyDeptFilter() {
    var inp = document.getElementById('deptSearchKw');
    var q = ((inp && inp.value) || '').trim().toLowerCase();
    var types = DEPT_TYPE.split(',');
    var n = 0;
    document.querySelectorAll('#deptList tbody tr').forEach(function (tr) {
        var hit = types.indexOf(tr.getAttribute('data-type')) !== -1 &&
                  tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('deptCountDiv');
    if (!cnt) return;
    var label = DEPT_TYPE_NAMES[DEPT_TYPE] || '科室';
    var searched = q !== '';
    cnt.textContent = searched ? label + ' ' + n + ' 个' : label + '共 ' + n + ' 个';
}
Clinic.importer._reloads['dept'] = loadDeptList;
Clinic.importer.attach('dept', 'impBtns', '科室');
function loadDeptList() {
    Clinic.get('/api/admin?action=dept_list', null, {
        onSuccess: function (json) {
            document.getElementById('deptList').innerHTML = json.data.html;
            applyDeptFilter();
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

/* 门诊显示号源输入，急诊/医技/其他隐藏 */
function toggleQuota() {
    var type = document.getElementById('f_type').value;
    document.getElementById('quotaRow').style.display = type === 'clinic' ? '' : 'none';
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
