"""
Cognos 第一数字承载 — 数据库管理与 Vault 双写系统 (database.py)
"""

import os
import json
import re
from datetime import datetime
from typing import Generator

from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, Session

from models import Base, Space, EntityObject, ObjectInSpace, Relation, Movement

# 数据库路径与 Vault 双写目录路径
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "cognos.db")
VAULT_DIR = os.path.join(BASE_DIR, "vault")
VAULT_EVENTS_DIR = os.path.join(VAULT_DIR, "events")
VAULT_RELATIONS_DIR = os.path.join(VAULT_DIR, "relations")

os.makedirs(VAULT_EVENTS_DIR, exist_ok=True)
os.makedirs(VAULT_RELATIONS_DIR, exist_ok=True)

DATABASE_URL = f"sqlite:///{DB_PATH}"

engine = create_engine(
    DATABASE_URL,
    connect_args={"check_same_thread": False},
    echo=False
)

SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)


def init_db():
    """初始化 SQLite 数据库"""
    Base.metadata.create_all(bind=engine)


def get_db() -> Generator[Session, None, None]:
    """FastAPI 依赖注入 Session 辅助函数"""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def sanitize_filename(name: str) -> str:
    """清理文件名中的非法字符"""
    return re.sub(r'[\\/*?:"<>|]', "_", name).strip()


class VaultDoubleWriter:
    """双写日志提取与导出器：每次数据变更同步持久化到人类可读的 Vault 文件"""

    @staticmethod
    def write_movement(space_name: str, movement: Movement, actor_name: str):
        safe_name = sanitize_filename(space_name)
        jsonl_file = os.path.join(VAULT_EVENTS_DIR, f"{safe_name}.jsonl")
        
        data = {
            "id": movement.id,
            "space_id": movement.space_id,
            "space_name": space_name,
            "actor_id": movement.actor_id,
            "actor_name": actor_name,
            "type": movement.type,
            "content": movement.content,
            "linked_relations": movement.linked_relations,
            "created_at": movement.created_at.isoformat() if movement.created_at else datetime.utcnow().isoformat()
        }
        
        with open(jsonl_file, "a", encoding="utf-8") as f:
            f.write(json.dumps(data, ensure_ascii=False) + "\n")

    @staticmethod
    def write_relation(space_name: str, relation: Relation, from_name: str, to_name: str):
        safe_name = sanitize_filename(space_name)
        md_file = os.path.join(VAULT_RELATIONS_DIR, f"{safe_name}.md")
        
        timestamp = relation.created_at.strftime("%Y-%m-%d %H:%M:%S") if relation.created_at else datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        
        md_content = f"""
### 关系记录 [{relation.id}] - {timestamp}
- **空间**: {space_name} (ID: {relation.space_id})
- **主体/对象关系**: `[{from_name}]` --[{relation.relation_type}]--> `[{to_name}]`
- **显性程度**: Level {relation.manifestation_level}
- **效果描述**: {relation.effect_description or '无'}
- **状态**: {relation.status}
---
"""
        with open(md_file, "a", encoding="utf-8") as f:
            f.write(md_content)
