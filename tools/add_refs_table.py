#!/usr/bin/env python3
"""
添加 cognos_refs 表到 activities.db
用于记录 Activity 和其他实体（笔记、文件、决策）之间的引用关系
"""

import sqlite3
import sys
from pathlib import Path

def add_refs_table(db_path: str):
    """添加 cognos_refs 表"""
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    
    try:
        # 创建 refs 表
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS cognos_refs (
                id TEXT PRIMARY KEY,
                source_id TEXT NOT NULL,
                source_type TEXT NOT NULL,
                target_id TEXT NOT NULL,
                target_type TEXT NOT NULL,
                relation_type TEXT NOT NULL DEFAULT 'references',
                target_version INTEGER,
                excerpt TEXT,
                note TEXT,
                created_at TEXT NOT NULL,
                created_by TEXT NOT NULL,
                UNIQUE(source_id, target_id, relation_type)
            )
        """)
        
        # 创建索引
        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_cognos_refs_source 
            ON cognos_refs(source_id, source_type)
        """)
        
        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_cognos_refs_target 
            ON cognos_refs(target_id, target_type)
        """)
        
        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_cognos_refs_relation 
            ON cognos_refs(relation_type)
        """)
        
        conn.commit()
        print("✓ cognos_refs 表创建成功")
        
        # 验证
        cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='cognos_refs'")
        if cursor.fetchone():
            print("✓ 表验证通过")
        else:
            print("✗ 表验证失败")
            return False
            
        return True
        
    except Exception as e:
        print(f"✗ 错误: {e}")
        conn.rollback()
        return False
    finally:
        conn.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("用法: python3 add_refs_table.py <db_path>")
        print("示例: python3 add_refs_table.py /path/to/activities.db")
        sys.exit(1)
    
    db_path = sys.argv[1]
    
    if not Path(db_path).exists():
        print(f"✗ 数据库文件不存在: {db_path}")
        sys.exit(1)
    
    print(f"数据库: {db_path}")
    success = add_refs_table(db_path)
    sys.exit(0 if success else 1)
