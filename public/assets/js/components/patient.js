/**
 * ============================================================
 * patient.js v1.0.0 — 患者信息修改弹窗
 * ============================================================
 * 说明：三处共用（挂号管理、医生工作站病历主页、
 * 护士站护理记录页面），点击患者姓名后弹出：
 * 可修改除姓名、性别、身份证号、出生年月外的其他信息
 * （手机号、职业、单位、婚姻、民族等）。
 * 表单由服务端渲染（字典来自 options_data.php）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.patient = {
    /**
     * 打开患者信息修改弹窗
     * @param {string} kw 患者ID / 身份证号
     */
    editModal: function (kw) {
        Clinic.get('/api/patient?action=edit_form&kw=' + encodeURIComponent(kw || ''), null, {
            onSuccess: function (json) {
                if (!json.data || !json.data.html) {
                    Clinic.toast.warning('未找到患者档案');
                    return;
                }
                Clinic.modal.open(json.data.html, {
                    title: '修改患者信息',
                    size: 'modal-lg',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        {
                            text: '保存修改', cls: 'btn-primary', autoClose: false,
                            onClick: function () {
                                Clinic.ajax('/api/patient', {
                                    action: 'update',
                                    id_card: document.getElementById('pmCard').value,
                                    phone: document.getElementById('pmPhone').value.trim(),
                                    ethnicity: document.getElementById('pmEth').value,
                                    marital: document.getElementById('pmMarital').value,
                                    occupation: document.getElementById('pmOcc').value,
                                    work_unit: document.getElementById('pmWork').value.trim(),
                                    address: document.getElementById('pmAddr').value.trim(),
                                }, {
                                    onSuccess: function (j) {
                                        Clinic.toast.success(j.msg);
                                        Clinic.modal.close();
                                    },
                                });
                            },
                        },
                    ],
                });
            },
        });
    },
};
