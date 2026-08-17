/**
 * ============================================================
 * ui.js v1.0.0 — 通用表单弹窗助手
 * ============================================================
 * 说明：管理端 CRUD 复用：
 * formModal(url, data, title, fields, onSaved)
 *   url     接口地址（如 /api/admin）
 *   data    打开表单的参数（如 {action:'dept_form', id:0}）
 *   title   弹窗标题
 *   fields  需要收集并提交的表单字段 id 列表
 *   onSaved 保存成功后的回调（通常用于刷新列表）
 * 表单由服务端渲染（字典/选项统一来自 options_data.php）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.ui = {
    /**
     * 打开服务端渲染的表单弹窗并绑定保存
     */
    formModal: function (url, data, title, fields, onSaved) {
        var mask = Clinic.modal.load(url, data, { title: title });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
            var foot = mask.querySelector('.modal-foot');
            foot.innerHTML =
                '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
                '<button type="button" class="btn btn-primary" id="fSave">保存</button>';
            document.getElementById('fSave').addEventListener('click', function () {
                var payload = { action: 'save' };
                fields.forEach(function (f) {
                    var el = document.getElementById(f);
                    if (el) payload[f] = el.value;
                });
                Clinic.ajax(url, payload, {
                    onSuccess: function (json) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        if (onSaved) onSaved(json);
                    },
                });
            });
        });
    },
};
