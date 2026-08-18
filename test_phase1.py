"""
Cognos Phase 1 单元测试与双写全流程校验脚本 (test_phase1.py)
"""

import os
import json
from database import init_db, SessionLocal, DB_PATH, VAULT_EVENTS_DIR, VAULT_RELATIONS_DIR, VaultDoubleWriter
from models import Space, EntityObject, ObjectInSpace, Relation, Movement


def run_phase1_test():
    print("=== 开始 Phase 1 自动化验证 ===")

    # 1. 重置测试数据库
    if os.path.exists(DB_PATH):
        try:
            os.remove(DB_PATH)
            print("[1/6] 已清理旧测试数据库 cognos.db")
        except Exception as e:
            print(f"清理旧数据库提示: {e}")

    # 2. 初始化数据库结构
    init_db()
    print("[2/6] SQLite 数据库初始化建表完成 (Space, EntityObject, ObjectInSpace, Relation, Movement)")

    db = SessionLocal()
    try:
        # 3. 创建空间 Space
        space = Space(
            name="Cognos 系统反思",
            purpose="验证第一数字承载的实体与关系流动"
        )
        db.add(space)
        db.commit()
        db.refresh(space)
        print(f"[3/6] 成功创建空间: ID={space.id}, Name='{space.name}'")

        # 4. 创建实体 EntityObject
        xiaolin = EntityObject(
            name="小林",
            type="human",
            subject_level=3,
            description="创作者/组织裁决者"
        )
        claude = EntityObject(
            name="Claude",
            type="ai",
            subject_level=3,
            description="AI 协作者/反应式反射墙"
        )
        db.add_all([xiaolin, claude])
        db.commit()
        db.refresh(xiaolin)
        db.refresh(claude)
        print(f"[4/6] 成功创建实体: ID={xiaolin.id} ({xiaolin.name}, level={xiaolin.subject_level}), ID={claude.id} ({claude.name}, level={claude.subject_level})")

        # 5. 建立 ObjectInSpace 共存关联
        ois1 = ObjectInSpace(object_id=xiaolin.id, space_id=space.id, status="active")
        ois2 = ObjectInSpace(object_id=claude.id, space_id=space.id, status="active")
        db.add_all([ois1, ois2])

        # 6. 建立 Relation (强制依附 space_id)
        relation = Relation(
            space_id=space.id,  # 物理防线：必须包含 space_id
            from_entity_id=xiaolin.id,
            to_entity_id=claude.id,
            relation_type="纠正",
            effect_description="判断是透支，关系依附于空间，软件是第一数字承载",
            manifestation_level=2,
            status="active"
        )
        db.add(relation)
        db.commit()
        db.refresh(relation)

        # 双写关系到 Vault
        VaultDoubleWriter.write_relation(space.name, relation, xiaolin.name, claude.name)
        print(f"[5/6] 成功建立强依附关系: Relation ID={relation.id}, Space={relation.space_id}, Type='{relation.relation_type}'")

        # 7. 记录 Movement 事件
        movement = Movement(
            space_id=space.id,
            actor_id=xiaolin.id,
            type="judgment",
            content="判断本质是透支，软件是第一数字承载。",
            linked_relations_json=json.dumps([relation.id])
        )
        db.add(movement)
        db.commit()
        db.refresh(movement)

        # 双写运动到 Vault
        VaultDoubleWriter.write_movement(space.name, movement, xiaolin.name)
        print(f"[6/6] 成功记录 Movement 事件: ID={movement.id}, Actor='{xiaolin.name}', Type='{movement.type}'")

        # 8. 校验与断言
        print("\n--- 自动化断言校验 ---")
        assert relation.space_id == space.id, "断言失败: Relation 缺少 space_id 外键"
        print("✔ [PASS] 物理断言: Relation 必须依赖 space_id 外键")

        jsonl_path = os.path.join(VAULT_EVENTS_DIR, "Cognos 系统反思.jsonl")
        md_path = os.path.join(VAULT_RELATIONS_DIR, "Cognos 系统反思.md")

        assert os.path.exists(jsonl_path), "断言失败: JSONL 双写日志未生成"
        assert os.path.exists(md_path), "断言失败: MD 关系双写日志未生成"
        
        with open(jsonl_path, "r", encoding="utf-8") as f:
            jsonl_content = f.read()
            assert "判断本质是透支" in jsonl_content, "断言失败: JSONL 内容校验不符"

        with open(md_path, "r", encoding="utf-8") as f:
            md_content = f.read()
            assert "纠正" in md_content and "小林" in md_content, "断言失败: MD 关系记录内容不符"

        print("✔ [PASS] 双写断言: Vault .jsonl 与 .md 文件完美落盘并通过完整性匹配")
        print("\n🎉 Phase 1 全部验收标准通过！数据层与双写系统正常运转。")

    finally:
        db.close()


if __name__ == "__main__":
    run_phase1_test()
