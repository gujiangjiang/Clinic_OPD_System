<?php
/**
 * admin/drugs.php — 药品信息管理
 * 说明：药品名称、通用名称、企业名称及缩写（处方打印显示）、
 * 包装单位、规格/含量、剂型、单次使用剂量、用药频次、使用途径、
 * 数量、单价、是否处方药、是否限制类药品、备注；
 * 途径选中时自动按【给药途径设置】勾选【需护士站处理】。
 * 新增药品需在审核中心通过后方可开方。
 */
Router::title('药品信息');
?>
<div class="page-head">
    <div><div class="page-title">💊 药品信息</div><div class="page-desc">药品档案管理（新增药品需审核通过后可用）</div></div>
    <button class="btn btn-primary btn-sm" onclick="openDrugForm(0)">＋ 新增药品</button>
</div>
<div class="card" id="drugList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadDrugList() {
    Clinic.get('/api/admin?action=drug_list', null, {
        onSuccess: function (json) {
            document.getElementById('drugList').innerHTML = json.data.html;
        },
    });
}

function openDrugForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'drug_form', id: id || 0 }, { title: id ? '编辑药品' : '新增药品' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
        // 途径 → 需护士站处理 自动勾选
        var routeMap = (e.detail && e.detail.route_nurse) || {};
        var nurseChk = document.getElementById('f_nurse');
        nurseChk.setAttribute('data-cur', (e.detail && e.detail.need_nurse) || 0);
        window.__routeMap = routeMap;
        window.syncNurse = function () {
            var route = document.getElementById('f_route').value;
            if (routeMap[route] === 1) {
                nurseChk.checked = true;
            }
        };

        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="drugSave">保存</button>';
        document.getElementById('drugSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'drug_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                generic_name: document.getElementById('f_generic').value.trim(),
                category: document.getElementById('f_category').value,
                vendor: document.getElementById('f_vendor').value.trim(),
                vendor_short: document.getElementById('f_vendor_short').value.trim(),
                package_unit: document.getElementById('f_pkg').value,
                spec: document.getElementById('f_spec').value.trim(),
                form: document.getElementById('f_form').value,
                single_dose: document.getElementById('f_dose').value.trim(),
                frequency_name: document.getElementById('f_freq').value,
                route_name: document.getElementById('f_route').value,
                price: document.getElementById('f_price').value,
                qty: document.getElementById('f_qty').value,
                is_rx: document.getElementById('f_rx').checked ? 1 : 0,
                is_limited: document.getElementById('f_limited').checked ? 1 : 0,
                need_nurse: document.getElementById('f_nurse').checked ? 1 : 0,
                note: document.getElementById('f_note').value.trim(),
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    loadDrugList();
                },
            });
        });
    });
}

function delDrug(id) {
    Clinic.modal.confirm('确定删除该药品？', function () {
        Clinic.ajax('/api/admin', { action: 'drug_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDrugList();
            },
        });
    });
}

loadDrugList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
(function () {
    var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1];
    if (m) openDrugForm(parseInt(m, 10));
})();
</script>
