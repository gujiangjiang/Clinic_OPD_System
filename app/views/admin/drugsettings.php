<?php
/**
 * admin/drugsettings.php — 药品设置
 * 说明：药品分类（西药/中成药/中药）、包装单位、药品剂型、
 * 用药频次、给药途径；新增途径时可勾选【是否需要护士站处理】
 * （如静脉输液等需前往护士站执行）。
 */
Router::title('药品设置');
?>
<div class="page-head">
    <div><div class="page-title">📦 药品设置</div><div class="page-desc">分类 / 包装单位 / 剂型 / 用药频次 / 给药途径</div></div>
</div>

<div class="flex gap-8 mb-12" id="dsTabs">
    <button class="btn btn-primary btn-sm" data-stype="category" onclick="switchDs('category')">药品分类</button>
    <button class="btn btn-outline btn-sm" data-stype="package" onclick="switchDs('package')">包装单位</button>
    <button class="btn btn-outline btn-sm" data-stype="form" onclick="switchDs('form')">药品剂型</button>
    <button class="btn btn-outline btn-sm" data-stype="freq" onclick="switchDs('freq')">用药频次</button>
    <button class="btn btn-outline btn-sm" data-stype="route" onclick="switchDs('route')">给药途径</button>
</div>

<div class="card">
    <div class="flex-between mb-12">
        <span class="fs-13 text-muted" id="dsHint"></span>
        <button class="btn btn-primary btn-sm" id="dsAddBtn" onclick="openDsForm(0)">＋ 新增</button>
    </div>
    <div id="dsList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
</div>

<script>
var CUR_STYPE = 'category';
var DS_ADMIN = document.body.getAttribute('data-role') === 'admin';   // 非管理员新增走审核（按钮保留），删除仅管理员
var DS_NAMES = { category: '药品分类', package: '包装单位', form: '药品剂型', freq: '用药频次', route: '给药途径' };

/** 途径绑定处置：只读展示 + 通用检索/快捷创建按钮 */
function routeBindBox(name, id) {
    setTimeout(function () {
        var b = document.getElementById('dsBindPick');
        if (b) b.addEventListener('click', pickRouteDisposal);
        var c = document.getElementById('dsBindClear');
        if (c) c.addEventListener('click', clearRouteDisposal);
    }, 50);
    return '<input type="hidden" id="dsBind" value="' + (id || 0) + '">' +
        '<div class="form-group"><label class="form-label">绑定计费处置（开方时按数量自动联动）</label>' +
        '<div class="flex gap-8"><input class="input" id="dsBindName" value="' + (name || '') + '" readonly placeholder="点击右侧选择或新建">' +
        '<button type="button" class="btn btn-outline btn-sm" id="dsBindPick">🔍 选择/新建</button>' +
        '<button type="button" class="btn btn-outline btn-sm" id="dsBindClear">清除</button></div>' +
        '<div class="fs-12 text-muted mt-4">如：静脉输液 → 静脉输液费。开方时按数量自动生成处置。</div></div>';
}
function pickRouteDisposal() {
    Clinic.universalSelector.open({
        title: '选择绑定的计费处置项目',
        searchAction: 'disposal_search',
        allowCreate: true,
        createAction: 'disposal_quick_create',
        createContext: '在配置给药途径时快捷创建处置',
        onSelect: function (item) {
            document.getElementById('dsBind').value = item.id;
            document.getElementById('dsBindName').value = item.name;
        },
    });
}
function clearRouteDisposal() {
    document.getElementById('dsBind').value = '0';
    document.getElementById('dsBindName').value = '';
}

function switchDs(stype) {
    CUR_STYPE = stype;
    document.querySelectorAll('#dsTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-stype') === stype ? 'btn-primary' : 'btn-outline');
    });
    document.getElementById('dsHint').textContent = DS_NAMES[stype] +
        (stype === 'route' ? '：新增途径时可设置【是否需要护士站处理】（如静脉输液需护士站执行）' : '');
    loadDs();
}

function loadDs() {
    Clinic.get('/api/admin?action=drugsetting_list&stype=' + CUR_STYPE, null, {
        onSuccess: function (json) {
            document.getElementById('dsList').innerHTML = json.data.html;
        },
    });
}

function openDsForm(id) {
    var name = '', nurse = 0;
    if (id) {
        // 编辑已有项（由行内按钮传入）
        return;
    }
    var nurseBox = CUR_STYPE === 'route'
        ? '<div class="form-group"><label class="flex gap-4" style="font-size:13px;cursor:pointer">' +
          '<input type="checkbox" id="dsNurse" value="1"> 该途径【需护士站处理】（如：静脉输液）</label></div>' +
          routeBindBox('', 0)
        : '';
    Clinic.modal.open(
        '<input type="hidden" id="dsId" value="0">' +
        '<div class="form-group"><label class="form-label">' + DS_NAMES[CUR_STYPE] + '名称 <span class="req">*</span></label>' +
        '<input class="input" id="dsName" placeholder="请输入名称"></div>' + nurseBox +
        (DS_ADMIN ? '' : '<div class="fs-12 text-muted mt-4">新增设置项需管理员审核，提交后待审核通过方可使用。</div>'),
        {
            title: '新增' + DS_NAMES[CUR_STYPE],
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '保存', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var nm = document.getElementById('dsName').value.trim();
                        if (!nm) { Clinic.toast.warning('请输入名称'); return; }
                        Clinic.ajax('/api/admin', {
                            action: 'drugsetting_save',
                            id: 0,
                            stype: CUR_STYPE,
                            name: nm,
                            is_nurse: document.getElementById('dsNurse') && document.getElementById('dsNurse').checked ? 1 : 0,
                        }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                loadDs();
                            },
                        });
                    },
                },
            ],
        }
    );
}

/* 编辑（行内按钮调用） */
function editDrugSetting(stype, id, name, needNurse, bindId, bindName) {
        bindId = bindId || 0; bindName = bindName || '';
    var nurseBox = stype === 'route'
        ? '<div class="form-group"><label class="flex gap-4" style="font-size:13px;cursor:pointer">' +
          '<input type="checkbox" id="dsNurse" value="1"' + (needNurse ? ' checked' : '') + '> 该途径【需护士站处理】（如：静脉输液）</label></div>' +
          routeBindBox(bindName, bindId)
        : '';
    Clinic.modal.open(
        '<input type="hidden" id="dsId" value="' + id + '">' +
        '<div class="form-group"><label class="form-label">' + DS_NAMES[stype] + '名称 <span class="req">*</span></label>' +
        '<input class="input" id="dsName" value="' + name + '"></div>' + nurseBox,
        {
            title: '编辑' + DS_NAMES[stype],
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '保存', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var nm = document.getElementById('dsName').value.trim();
                        if (!nm) { Clinic.toast.warning('请输入名称'); return; }
                        Clinic.ajax('/api/admin', {
                            action: 'drugsetting_save',
                            id: id,
                            stype: stype,
                            name: nm,
                            is_nurse: document.getElementById('dsNurse') && document.getElementById('dsNurse').checked ? 1 : 0,
                            bind_disposal_item_id: parseInt(document.getElementById('dsBind') ? document.getElementById('dsBind').value : '0', 10) || 0,
                        }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                loadDs();
                            },
                        });
                    },
                },
            ],
        }
    );
}

function delDrugSetting(id) {
    Clinic.modal.confirm('确定删除该设置项？', function () {
        Clinic.ajax('/api/admin', { action: 'drugsetting_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDs();
            },
        });
    });
}

switchDs('category');
</script>
