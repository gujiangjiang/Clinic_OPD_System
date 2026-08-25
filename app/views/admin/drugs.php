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
    <div class="flex gap-8">
        <span id="drugImportBtns" class="flex gap-8"></span>
        <button class="btn btn-primary btn-sm" onclick="openDrugForm(0)">＋ 新增药品</button>
    </div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" placeholder="🔍 快速搜索药品 / 通用名 / 厂家" style="width:220px" oninput="quickFilter(this.value,'drugList')">
        <span class="flex gap-4" id="drugCatTabs" style="flex-wrap:wrap"></span>
    </div>
</div>
<div class="card" id="drugList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var DRUG_CAT = '';
/* 分类子 tab（按数据动态生成） */
function buildDrugCats() {
    var cats = [];
    document.querySelectorAll('#drugList tbody tr').forEach(function (tr) {
        var c = tr.getAttribute('data-cat') || '';
        if (c && cats.indexOf(c) === -1) cats.push(c);
    });
    var bar = document.getElementById('drugCatTabs');
    bar.innerHTML = '<button class="btn btn-sm ' + (DRUG_CAT === '' ? 'btn-primary' : 'btn-outline') + '" onclick="drugCatFilter(this,\'\')">全部</button>' +
        cats.map(function (c) {
            return '<button class="btn btn-sm ' + (DRUG_CAT === c ? 'btn-primary' : 'btn-outline') + '" data-cat="' + c + '" onclick="drugCatFilter(this,\'' + c + '\')">' + c + '</button>';
        }).join('');
}
function drugCatFilter(btn, c) {
    DRUG_CAT = c;
    document.querySelectorAll('#drugCatTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === c ? 'btn-primary' : 'btn-outline');
    });
    document.querySelectorAll('#drugList tbody tr').forEach(function (tr) {
        tr.style.display = (c === '' || tr.getAttribute('data-cat') === c) ? '' : 'none';
    });
}
/* 快速搜索：分类 + 关键词组合过滤 */
function quickFilter() {
    applyDrugFilter();
}
/* 分类子标签 + 关键词组合过滤，并动态更新计数 */
function drugCatFilter(btn, c) {
    DRUG_CAT = c;
    document.querySelectorAll('#drugCatTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === c ? 'btn-primary' : 'btn-outline');
    });
    applyDrugFilter();
}
function applyDrugFilter() {
    var inp = document.querySelector('input[oninput*="drugList"]');
    var q = ((inp && inp.value) || '').trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#drugList tbody tr').forEach(function (tr) {
        var hit = (DRUG_CAT === '' || tr.getAttribute('data-cat') === DRUG_CAT) &&
                  tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    updateDrugCount(n);
}
/* 计数动态更新：默认「共 N 种」/ 分类「药品（西药）共 N 种」/ 搜索「药品 N 种」 */
function updateDrugCount(n) {
    var cnt = document.getElementById('drugCountDiv');
    if (!cnt) return;
    var inp = document.querySelector('input[oninput*="drugList"]');
    var searched = ((inp && inp.value) || '').trim() !== '';
    if (DRUG_CAT === '') {
        cnt.textContent = searched ? '药品 ' + n + ' 种' : '共 ' + n + ' 种药品';
    } else {
        cnt.textContent = searched ? '药品（' + DRUG_CAT + '）' + n + ' 种' : '药品（' + DRUG_CAT + '）共 ' + n + ' 种';
    }
}
Clinic.importer._reloads['drug'] = loadDrugList;
Clinic.importer.attach('drug', 'drugImportBtns', '药品');
function loadDrugList() {
    Clinic.get('/api/admin?action=drug_list', null, {
        onSuccess: function (json) {
            document.getElementById('drugList').innerHTML = json.data.html;
            buildDrugCats();
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
            // 皮试必填校验：勾选"需要皮试"必须关联皮试处置项目
            var skinTestChk = document.getElementById('f_skin_test');
            var skinItemVal = parseInt(document.getElementById('f_skin_item') ? document.getElementById('f_skin_item').value : '0', 10) || 0;
            if (skinTestChk && skinTestChk.checked && !skinItemVal) {
                Clinic.toast.warning('勾选了【需要皮试药品】，请先选择关联的皮试处置项目（点击"选择/新建"）');
                return;
            }
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
                need_skin_test: document.getElementById('f_skin_test') ? (document.getElementById('f_skin_test').checked ? 1 : 0) : 0,
                skin_test_item_id: parseInt(document.getElementById('f_skin_item') ? document.getElementById('f_skin_item').value : '0', 10) || 0,
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
