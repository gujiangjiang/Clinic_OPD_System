<?php
/**
 * ============================================================
 * icons/AlertIcons.php — 提示与状态反馈图标
 * ============================================================
 * 说明：提示与状态反馈（危急值、警告、错误、成功、处理中等）。
 * 统一 24×24 视口、描边风格；圆点类图标使用填充风格。
 * ============================================================ */
class AlertIcons {

    public static function icons() {
        return array(
            // 警告（三角感叹号）
            'alert:warning' =>
                '<path d="M12 3 2.5 20.5h19z"/>' .
                '<path d="M12 9.5v4.5"/>' .
                '<path d="M12 17h.01"/>',
            // 危急值/紧急（圆感叹号）
            'alert:critical' =>
                '<circle cx="12" cy="12" r="8.5"/>' .
                '<path d="M12 7.5V13"/>' .
                '<path d="M12 16.5h.01"/>',
            // 错误（圆叉）
            'alert:error' =>
                '<circle cx="12" cy="12" r="8.5"/>' .
                '<path d="M15 9l-6 6M9 9l6 6"/>',
            // 成功（圆勾）
            'alert:success' =>
                '<circle cx="12" cy="12" r="8.5"/>' .
                '<path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
            // 信息（圆 i）
            'alert:info' =>
                '<circle cx="12" cy="12" r="8.5"/>' .
                '<path d="M12 11.5v4.5"/>' .
                '<path d="M12 8h.01"/>',
            // 禁止（八边形斜杠）
            'alert:blocked' =>
                '<path d="M8 3h8l5 5v8l-5 5H8l-5-5V8z"/>' .
                '<path d="M8.5 12h7"/>',
            // 盾牌（安全/权限）
            'alert:shield' =>
                '<path d="M12 2.5l8 3.5v6c0 4.8-3.4 8.2-8 9.5-4.6-1.3-8-4.7-8-9.5v-6z"/>' .
                '<path d="M9 12l2.2 2.2 4-4.5"/>',
            // 处理中（转圈）
            'alert:loading' =>
                '<path d="M21 12a9 9 0 1 1-2.6-6.3"/>' .
                '<path d="M21 3v5h-5"/>',
            // 状态圆点（填充）
            'alert:dot-red' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            'alert:dot-green' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            'alert:dot-yellow' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            'alert:dot-blue' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            'alert:dot-white' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            'alert:dot-gray' =>
                '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>',
            // 感叹号（纯叹号）
            'alert:exclaim' =>
                '<path d="M12 3v11"/>' .
                '<path d="M12 19.5h.01"/>',
        );
    }
}