<?php
/**
 * ============================================================
 * 002_user.php — 用户库 schema
 * 说明：系统用户（工号/姓名/角色/多科室关联/照片/职称学历等）
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 6,
    'tables' => array(
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            emp_no TEXT,
            username TEXT UNIQUE,
            password TEXT,
            name TEXT,
            role TEXT,
            dept_ids TEXT,
            photo TEXT,
            education TEXT,
            degree TEXT,
            title TEXT,
            position TEXT,
            intro TEXT,
            theme TEXT DEFAULT 'auto',
            sidebar TEXT DEFAULT 'expand',
            pwd_changed INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            created_at TEXT,
            last_login TEXT,
            current_dept_id INTEGER DEFAULT 0,
            print_auto INTEGER DEFAULT 0,
            queue_days INTEGER DEFAULT 3,
            login_fail_count INTEGER DEFAULT 0,
            login_locked_until TEXT
        )",
    ),
    // v2：医生当前看诊科室（叫号屏跟随医生端选择动态显示，由 /api/doctor set_dept 更新）
    // v3：侧边栏显示偏好 expand 展开 / mini 缩小（仅图标），跟随用户保存，登录后保持
    // v4：打印偏好——自动打印（弹出预览后自动调起系统打印并收起预览），跟随用户保存
    'migrations' => array(
        2 => array(
            'ALTER TABLE users ADD COLUMN current_dept_id INTEGER DEFAULT 0',
        ),
        3 => array(
            "ALTER TABLE users ADD COLUMN sidebar TEXT DEFAULT 'expand'",
        ),
        4 => array(
            'ALTER TABLE users ADD COLUMN print_auto INTEGER DEFAULT 0',
        ),
        // v5：候诊列表可显示天数（2-7，默认3；医生站候诊队列按此回看天数，
        // 最低2天确保急诊0点后仍能看到前一天患者）
        5 => array(
            'ALTER TABLE users ADD COLUMN queue_days INTEGER DEFAULT 3',
        ),
        // v6：登录失败计数与锁定时间（防暴力破解）
        6 => array(
            'ALTER TABLE users ADD COLUMN login_fail_count INTEGER DEFAULT 0',
            'ALTER TABLE users ADD COLUMN login_locked_until TEXT',
        ),
    ),
    'seed' => array(),
);
