<?php
/**
 * ============================================================
 * main/22_push_events.php — 实时推送事件队列（SSE）
 * ============================================================ */
return array(
    'tables' => array(

        'push_events' => "CREATE TABLE IF NOT EXISTS push_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL,
            payload TEXT NOT NULL DEFAULT '',
            created_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_push_events_channel ON push_events(channel, id)",
    ),
    'migrations' => array(
        // v35：实时推送事件队列（SSE 长连接）：通道（scr:屏幕令牌 / msg:用户ID / room:诊室ID / dept:科室ID）
        35 => array(
            "CREATE TABLE IF NOT EXISTS push_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL,
                payload TEXT NOT NULL DEFAULT '',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_push_events_channel ON push_events(channel, id)",
        ),
    ),
);
