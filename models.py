"""
Cognos 第一数字承载 — 核心数据模型 (models.py)

工程底线：
1. 不建 User 表、Task 表。基础实体只有：Space、EntityObject、ObjectInSpace、Relation、Movement。
2. Relation 必须有 space_id 外键（NOT NULL）。脱离空间的关系在物理上不允许存在。
3. 不预定义死位置。位置在对话中动态涌现。
4. 历史只追加不覆盖。所有 Movement 写入后不可 UPDATE/DELETE。
"""

from datetime import datetime
from typing import Optional, List
import json

from sqlalchemy import String, Integer, DateTime, ForeignKey, Text, Enum
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


class Space(Base):
    """空间：对象与关系共存的场所"""
    __tablename__ = "spaces"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    purpose: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    parent_space_id: Mapped[Optional[int]] = mapped_column(Integer, ForeignKey("spaces.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)

    # Relationships
    objects_in_space: Mapped[List["ObjectInSpace"]] = relationship("ObjectInSpace", back_populates="space")
    relations: Mapped[List["Relation"]] = relationship("Relation", back_populates="space")
    movements: Mapped[List["Movement"]] = relationship("Movement", back_populates="space")


class EntityObject(Base):
    """对象 / 主体：可在空间中被指认的实体，具有 0-4 级主体化程度"""
    __tablename__ = "entity_objects"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    type: Mapped[str] = mapped_column(String(50), nullable=False)  # human / ai / concept / project / relation
    subject_level: Mapped[int] = mapped_column(Integer, default=0)  # 0=普通对象, 1=可展开, 2=候选主体, 3=活跃主体, 4=组织器官
    description: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)

    spaces: Mapped[List["ObjectInSpace"]] = relationship("ObjectInSpace", back_populates="entity_object")


class ObjectInSpace(Base):
    """对象在空间中的共存连接记录"""
    __tablename__ = "objects_in_space"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    object_id: Mapped[int] = mapped_column(Integer, ForeignKey("entity_objects.id"), nullable=False)
    space_id: Mapped[int] = mapped_column(Integer, ForeignKey("spaces.id"), nullable=False)
    entered_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    status: Mapped[str] = mapped_column(String(20), default="active")  # active / dormant / archived

    space: Mapped["Space"] = relationship("Space", back_populates="objects_in_space")
    entity_object: Mapped["EntityObject"] = relationship("EntityObject", back_populates="spaces")


class Relation(Base):
    """关系：必须依赖空间存在的实体连接。包含 manifestation_level 显形梯度"""
    __tablename__ = "relations"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    space_id: Mapped[int] = mapped_column(Integer, ForeignKey("spaces.id"), nullable=False)  # 强制外键：脱离空间无关系
    from_entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("entity_objects.id"), nullable=False)
    to_entity_id: Mapped[int] = mapped_column(Integer, ForeignKey("entity_objects.id"), nullable=False)
    relation_type: Mapped[str] = mapped_column(String(50), nullable=False)  # 纠正 / 支撑 / 冲突 / 分支 / 依赖 / 来源 / 压缩风险
    effect_description: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    manifestation_level: Mapped[int] = mapped_column(Integer, default=1)  # 0=潜在共存, 1=已显现, 2=活关系, 3=主体化关系
    status: Mapped[str] = mapped_column(String(20), default="active")  # active / dormant / archived
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)

    space: Mapped["Space"] = relationship("Space", back_populates="relations")
    from_entity: Mapped["EntityObject"] = relationship("EntityObject", foreign_keys=[from_entity_id])
    to_entity: Mapped["EntityObject"] = relationship("EntityObject", foreign_keys=[to_entity_id])


class Movement(Base):
    """运动 / 事件记录：只追加不修改的历史展开链条"""
    __tablename__ = "movements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    space_id: Mapped[int] = mapped_column(Integer, ForeignKey("spaces.id"), nullable=False)
    actor_id: Mapped[int] = mapped_column(Integer, ForeignKey("entity_objects.id"), nullable=False)
    type: Mapped[str] = mapped_column(String(50), nullable=False)  # message / judgment / attempt / landing / residue / correction / consensus / learning
    content: Mapped[str] = mapped_column(Text, nullable=False)
    linked_relations_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True, default="[]")
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)

    space: Mapped["Space"] = relationship("Space", back_populates="movements")
    actor: Mapped["EntityObject"] = relationship("EntityObject", foreign_keys=[actor_id])

    @property
    def linked_relations(self) -> list:
        if self.linked_relations_json:
            try:
                return json.loads(self.linked_relations_json)
            except Exception:
                return []
        return []

    @linked_relations.setter
    def linked_relations(self, value: list):
        self.linked_relations_json = json.dumps(value, ensure_ascii=False)
