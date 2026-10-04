<?php
/**
 * ============================================================
 * main/04_users.php — 系统用户
 * ============================================================ */
return array(
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
            email TEXT,
            status INTEGER DEFAULT 1,
            created_at TEXT,
            last_login_at TEXT,
            current_dept_id INTEGER DEFAULT 0,
            print_auto INTEGER DEFAULT 0,
            queue_days INTEGER DEFAULT 3,
            login_fail_count INTEGER DEFAULT 0,
            locked_until TEXT,
            lock_reason TEXT DEFAULT NULL,
            locked_at TEXT DEFAULT NULL,
            lock_ip TEXT DEFAULT ''
        )",
    ),
    'migrations' => array(
        // v31：登录安全体系升级——锁定归因三件套（lock_reason 锁定原因 /
        // locked_at 锁定时间 / lock_ip 触发来源 IP），支撑：
        // · 密码连续错误达上限 → status=0 + lock_reason='password_error_locked'
        //   自动安全锁定（区别于管理员主动停用 'admin_disabled'）；
        // · 管理员用户列表三态徽章展示 + 一键解锁（解锁即清零全部锁定字段）。
        // ALTER ADD COLUMN 幂等（DatabaseManager 检测列已存在自动跳过），
        // 存量数据不受影响（默认 NULL/空串）。
        31 => array(
            "ALTER TABLE users ADD COLUMN lock_reason TEXT DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN locked_at TEXT DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN lock_ip TEXT DEFAULT ''",
        ),
        // v40：用户邮箱（users.email）——安装向导管理员邮箱与后台用户管理
        // 共用同一字段，保证登录后「我的资料 / 用户管理」与安装时填写一致。
        40 => array(
            "ALTER TABLE users ADD COLUMN email TEXT",
        ),
    ),
);
