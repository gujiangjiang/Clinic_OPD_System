/**
 * ============================================================
 * import.js v1.0.0 — 通用数据导入/导出 UI
 * ============================================================
 * Clinic.importer —— 为管理端各模块列表页提供统一操作：
 *   .attach(moduleName, buttonsId)  在容器中注入三个按钮并绑定逻辑
 *   .openImport(moduleName)         打开导入模态框（上传→预检→冲突确认→落库）
 * 按钮：📥 下载模板 / 📤 导出全部 / 📥 批量导入
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.importer = (function () {

    function openImport(moduleName, moduleTitle) {
        Clinic.modal.open(
            '<div id="impBody">' +
            '<div class="fs-13 text-muted mb-12">选择 CSV 文件（首行为中文表头，可先下载模板）。导入前将自动预检冲突。</div>' +
            '<div class="form-group"><input type="file" class="input" id="impFile" accept=".csv,text/csv"></div>' +
            '<div id="impResult" class="fs-13"></div>' +
            '</div>',
            {
                title: '📥 批量导入 · ' + (moduleTitle || moduleName),
                size: 'modal-md',
                buttons: [
                    { text: '关闭', cls: 'btn-outline' },
                    { text: '上传并预检', cls: 'btn-primary', autoClose: false, onClick: doPreview },
                ],
            }
        );
        // 模块信息挂到 body 上供回调使用
        var m = Clinic.modal._lastBody || document.getElementById('impBody');
        m.setAttribute('data-module', moduleName);
        m.setAttribute('data-title', moduleTitle || moduleName);
    }

    function doPreview() {
        var body = document.getElementById('impBody');
        var file = document.getElementById('impFile').files[0];
        if (!file) { Clinic.toast.warning('请先选择文件'); return; }
        var fd = new FormData();
        fd.append('csrf_token', document.body.getAttribute('data-csrf'));
        fd.append('action', 'import_preview');
        fd.append('module', body.getAttribute('data-module'));
        fd.append('file', file);
        var box = document.getElementById('impResult');
        box.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div><div class="text-center fs-13 mt-4">正在解析预检…</div>';
        fetch('/api/admin', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { box.innerHTML = '<div class="text-danger fs-13">' + (j.msg || '预检失败') + '</div>'; return; }
                renderResult(box, j.data);
            })
            .catch(function () { box.innerHTML = '<div class="text-danger fs-13">网络请求失败</div>'; });
    }

    function renderResult(box, d) {
        var html = '<div class="mb-8" style="line-height:2">' +
            '共 <b>' + d.total_count + '</b> 条：新增 <b class="text-success">' + d.valid_count + '</b> 条，' +
            '冲突 <b class="text-warning">' + d.conflict_count + '</b> 条，' +
            '错误 <b class="text-danger">' + d.error_count + '</b> 条</div>';
        if (d.error_list && d.error_list.length) {
            html += '<div class="fs-12 mb-8" style="color:var(--danger);max-height:120px;overflow-y:auto">' +
                d.error_list.map(function (e) {
                    return '第' + e.row + '行：' + e.key + ' — ' + e.reason;
                }).join('<br>') + '</div>';
        }
        if (d.conflict_list && d.conflict_list.length) {
            html += '<div class="fs-13 fw-600 mb-4">冲突明细（' + d.conflict_count + ' 条）：</div>' +
                '<div class="table-wrap" style="max-height:140px;overflow-y:auto"><table class="table"><thead><tr>' +
                '<th>唯一键</th><th>名称</th><th>原因</th></tr></thead><tbody>' +
                d.conflict_list.map(function (c) {
                    return '<tr><td>' + c.key + '</td><td>' + (c.name || '') + '</td><td class="text-warning">' + c.reason + '</td></tr>';
                }).join('') + '</tbody></table></div>';
            html += '<div class="mt-8"><label class="flex gap-4 mb-4" style="font-size:13px;cursor:pointer">' +
                '<input type="radio" name="impStrategy" value="skip" checked> 忽略冲突（仅导入全新数据，保留现有）</label>' +
                '<label class="flex gap-4" style="font-size:13px;cursor:pointer">' +
                '<input type="radio" name="impStrategy" value="overwrite"> 覆盖更新（用导入数据更新已有记录）</label></div>';
            html += '<button type="button" class="btn btn-success btn-sm mt-8" onclick="Clinic.importer.confirmImport()">确认执行导入</button>';
        } else {
            html += '<div class="fs-13 text-success mt-8">✅ 无冲突，可直接导入。</div>' +
                '<button type="button" class="btn btn-success btn-sm mt-8" onclick="Clinic.importer.confirmImport()">确认执行导入</button>';
        }
        box.innerHTML = html;
        box.setAttribute('data-valid', d.valid_count);
    }

    function confirmImport() {
        var box = document.getElementById('impResult');
        var strategy = 'skip';
        var rb = box.querySelector('input[name="impStrategy"]:checked');
        if (rb) strategy = rb.value;
        Clinic.ajax('/api/admin', {
            action: 'import_confirm',
            module: document.getElementById('impBody').getAttribute('data-module'),
            conflict_strategy: strategy,
        }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                // 刷新当前列表（各模块回调注入）
                var mod = document.getElementById('impBody').getAttribute('data-module');
                Clinic.modal.close();
                var reload = Clinic.importer._reloads[mod];
                if (reload) reload();
                else location.reload();
            },
        });
    }

    /** 注入「数据管理」下拉按钮（下载模板 / 导出全部 / 批量导入） */
    function attach(moduleName, containerId, moduleTitle) {
        var box = document.getElementById(containerId);
        if (!box) return;
        box.innerHTML =
            '<div class="dd-wrap">' +
            '<button type="button" class="btn btn-outline btn-sm" onclick="Clinic.importer.toggleMenu(this)">📊 数据管理 ▾</button>' +
            '<div class="dd-menu">' +
            '<div class="dd-item" onclick="Clinic.importer.downloadTemplate(\'' + moduleName + '\');Clinic.importer.toggleMenu(null)">📥 下载模板</div>' +
            '<div class="dd-item" onclick="Clinic.importer.exportData(\'' + moduleName + '\');Clinic.importer.toggleMenu(null)">📤 导出全部</div>' +
            '<div class="dd-item" onclick="Clinic.importer.openImport(\'' + moduleName + '\',\'' + (moduleTitle || '') + '\');Clinic.importer.toggleMenu(null)">📥 批量导入</div>' +
            '</div></div>';
    }

    function toggleMenu(btn) {
        document.querySelectorAll('.dd-wrap .dd-menu').forEach(function (m) { m.classList.remove('open'); });
        if (!btn) return;
        var wrap = btn.parentElement;
        var menu = wrap.querySelector('.dd-menu');
        if (menu) menu.classList.add('open');
        // 点击其他区域关闭
        setTimeout(function () {
            var handler = function (e) {
                if (!wrap.contains(e.target)) {
                    menu.classList.remove('open');
                    document.removeEventListener('mousedown', handler);
                }
            };
            document.addEventListener('mousedown', handler);
        }, 0);
    }

    function downloadTemplate(mod) { location.href = '/api/admin?action=download_template&module=' + mod; }
    function exportData(mod) { location.href = '/api/admin?action=export_data&module=' + mod; }

    return {
        attach: attach,
        toggleMenu: toggleMenu,
        openImport: openImport,
        confirmImport: confirmImport,
        downloadTemplate: downloadTemplate,
        exportData: exportData,
        _reloads: {},
    };
})();
