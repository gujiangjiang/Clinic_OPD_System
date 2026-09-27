/* ============================================================
 * assets/js/modules/measurements.js — 测量与 ROI 计算模块（纯函数）
 * ============================================================ */
(function (global) {
    'use strict';
    var HU_MIN = -1000, HU_MAX = 1500;
    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
    function distPx(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }
    function distMM(a, b, ps) { return distPx(a, b) * (ps || 0.7); }
    function angleDeg(a, b, c) {
        var v1 = { x: a.x - b.x, y: a.y - b.y }, v2 = { x: c.x - b.x, y: c.y - b.y };
        var d = (v1.x * v2.x + v1.y * v2.y) / ((Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y)) || 1);
        return Math.acos(clamp(d, -1, 1)) * 180 / Math.PI;
    }
    /** 区域灰度统计：imgData 为 BASE 尺寸的 ImageData；isHU=true 时把均值换算为伪 HU */
    function roiStats(imgData, pts, type, isHU, ps) {
        if (!imgData) return null;
        var BASE = imgData.width;
        var x0 = clamp(Math.round(Math.min(pts[0].x, pts[1].x)), 0, BASE - 1);
        var x1 = clamp(Math.round(Math.max(pts[0].x, pts[1].x)), 0, BASE - 1);
        var y0 = clamp(Math.round(Math.min(pts[0].y, pts[1].y)), 0, BASE - 1);
        var y1 = clamp(Math.round(Math.max(pts[0].y, pts[1].y)), 0, BASE - 1);
        var cx = (x0 + x1) / 2, cy = (y0 + y1) / 2, rx = Math.max(1, (x1 - x0) / 2), ry = Math.max(1, (y1 - y0) / 2);
        var sum = 0, n = 0;
        for (var y = y0; y <= y1; y++) {
            for (var x = x0; x <= x1; x++) {
                if (type === 'ellipse') { var dx = (x - cx) / rx, dy = (y - cy) / ry; if (dx * dx + dy * dy > 1) continue; }
                sum += imgData.data[(y * BASE + x) * 4]; n++;
            }
        }
        if (!n) return null;
        var meanByte = sum / n;
        return {
            area: n * (ps || 0.7) * (ps || 0.7),
            mean: isHU ? (meanByte / 255 * (HU_MAX - HU_MIN) + HU_MIN) : meanByte,
            hu: !!isHU, count: n
        };
    }
    global.PvMeasure = { distPx: distPx, distMM: distMM, angleDeg: angleDeg, roiStats: roiStats };
})(window);
