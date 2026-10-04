/**
 * ============================================================
 * naming.js v1.0.0 — 名称拼接公共方法（叫号语音 / 按钮 / 悬浮窗标题共用）
 * ============================================================
 * 说明：诊室对外展示与语音呼叫需要「科室名 + 诊室名」的全称，
 * 例如：外科门诊 + 1诊室 → 外科门诊1诊室。
 * 该拼接在大屏语音播报、顶栏叫号按钮状态、叫号悬浮窗标题三处共用，
 * 统一收敛到 Clinic.deptRoomName，避免各处重复实现导致不一致。
 * 依赖：ajax.js（提供 window.Clinic 命名空间）；大屏页 screen.js 亦独立引用。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.deptRoomName = function (dept, room) {
    dept = String(dept == null ? '' : dept).trim();
    room = String(room == null ? '' : room).trim();
    if (!dept) return room;
    if (!room) return dept;
    // 诊室名已包含科室名（历史数据如「外科门诊1诊室」）则不再重复拼接
    if (room.indexOf(dept) !== -1) return room;
    return dept + room;
};
