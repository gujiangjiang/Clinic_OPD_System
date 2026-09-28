<?php
/**
 * ============================================================
 * icons/ClinicalIcons.php — 临床体征与医疗检验图标
 * ============================================================
 * 说明：临床体征与医疗检验图标（体温、脉搏/心率、血压、血氧、
 * 试管、胶片等）。统一 24×24 视口、描边风格。
 * ============================================================ */
class ClinicalIcons {

    public static function icons() {
        return array(
            // 体温（水银温度计）
            'clinical:temperature' =>
                '<path d="M13.8 4a2.5 2.5 0 0 0-5 0v9.2a4.3 4.3 0 1 0 5 0z"/>' .
                '<path d="M12 7.5v6.2"/>' .
                '<path d="M12 15.8a1.6 1.6 0 1 0 0-3.2a1.6 1.6 0 0 0 0 3.2"/>',
            // 听诊器（医生/问诊）
            'clinical:stethoscope' =>
                '<path d="M5 3v4.8a3.2 3.2 0 0 0 3.2 3.2h1"/>' .
                '<path d="M19 3v4.8a3.2 3.2 0 0 1-3.2 3.2h-1"/>' .
                '<path d="M5 7.2H3.2a1.5 1.5 0 0 0-1.5 1.5V10a6.3 6.3 0 0 0 6.3 6.3"/>' .
                '<path d="M8 16.3v.7a6 6 0 0 0 6 6a6 6 0 0 0 6-6v-4.6"/>' .
                '<circle cx="20" cy="11.2" r="1.8"/>',
            // 注射（针筒）
            'clinical:injection' =>
                '<path d="M15.5 3.5l5 5"/>' .
                '<path d="M18.5 2.5l3 3"/>' .
                '<path d="M18 8 8.5 17.5"/>' .
                '<path d="M6.5 20.5 3 17l2.5-2.5 3.5 3.5z"/>' .
                '<path d="M8 15l1 1M10 13l1 1"/>',
            // 创可贴（处置/伤口）
            'clinical:plaster' =>
                '<path d="M8.5 8.5l7 7"/>' .
                '<path d="M9.5 4.8 4.8 9.5M14.5 19.2l4.7-4.7"/>' .
                '<rect x="3.8" y="8" width="4.8" height="8" rx="2" transform="rotate(45 8.2 12)"/>' .
                '<rect x="15.4" y="8" width="4.8" height="8" rx="2" transform="rotate(45 17.8 12)"/>',
            // 直尺（体格测量）
            'clinical:ruler' =>
                '<path d="M3.5 17 17 3.5"/>' .
                '<rect x="13.5" y="2" width="8.5" height="8.5" rx="1.5" transform="rotate(45 17.75 6.25)"/>' .
                '<path d="M15.5 8.5l1-1M12.8 11.2l1-1M10.1 13.9l1-1"/>',
            // 心电/脉搏（生命体征）
            'clinical:pulse' =>
                '<path d="M2 12h4l2.5-6 5 12 2.5-6h6"/>',
            // 血氧（指尖夹）
            'clinical:oximeter' =>
                '<path d="M8 2v9a4 4 0 0 0 8 0V2"/>' .
                '<path d="M8 6h8M8 9h8"/>' .
                '<path d="M5.5 2h13"/>',
            // 血压（袖带/仪表）
            'clinical:blood-pressure' =>
                '<circle cx="12" cy="12" r="8.5"/>' .
                '<path d="M12 12l3.5-3.5"/>' .
                '<path d="M12 12l-1 1"/>' .
                '<path d="M12 3.5v2M12 18.5v2M3.5 12h2M18.5 12h2"/>',
            // 视力/眼科（眼睛）
            'clinical:eye' =>
                '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>' .
                '<circle cx="12" cy="12" r="3"/>',
        );
    }
}