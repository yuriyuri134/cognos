#!/usr/bin/env php
<?php
/**
 * 创建 Activity 消息表
 */

if ($argc < 2) {
    echo "用法: php create_messages_table.php <activities.db路径>\n";
    exit(1);
}

$dbPath = $argv[1];

if (!file_exists($dbPath)) {
    echo "错误: 数据库文件不存在: $dbPath\n";
    exit(1);
}

// 备份数据库
$backupPath = $dbPath . '.backup.' . date('YmdHis');
if (!copy($dbPath, $backupPath)) {
    echo "错误: 无法创建备份\n";
    exit(1);
}
echo "✓ 已备份到: $backupPath\n";

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 创建消息表
    $db->exec('
        CREATE TABLE IF NOT EXISTS cognos_activity_messages (
            id TEXT PRIMARY KEY,
            activity_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ("user", "assistant", "system")),
            content TEXT NOT NULL,
            model TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (activity_id) REFERENCES cognos_activities(id) ON DELETE CASCADE
        )
    ');

    // 创建索引
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_activity ON cognos_activity_messages(activity_id, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_user ON cognos_activity_messages(user_id, created_at)');

    echo "✓ cognos_activity_messages 表创建成功\n";
    echo "✓ 索引创建完成\n";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "正在恢复备份...\n";
    copy($backupPath, $dbPath);
    exit(1);
}

echo "\n完成！\n";
