"""导入 Obsidian vault 到 Cognos notes.db"""
from __future__ import annotations

import re
import sys
import hashlib
import uuid
from pathlib import Path
from datetime import datetime, timezone

sys.path.insert(0, str(Path(__file__).parent.parent / "web"))
from notes_store import NotesStore


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _id() -> str:
    return str(uuid.uuid4())


def extract_frontmatter(content: str) -> tuple[dict, str]:
    """提取 YAML frontmatter"""
    if not content.startswith("---\n"):
        return {}, content
    
    parts = content.split("---\n", 2)
    if len(parts) < 3:
        return {}, content
    
    # 简单解析 YAML（只处理 tags）
    frontmatter = {}
    yaml_text = parts[1]
    
    # tags: [tag1, tag2] 或 tags: tag1
    tags_match = re.search(r'tags:\s*\[(.*?)\]', yaml_text)
    if tags_match:
        tags = [t.strip().strip('"\'') for t in tags_match.group(1).split(',')]
        frontmatter['tags'] = tags
    else:
        tags_match = re.search(r'tags:\s*(.+)', yaml_text)
        if tags_match:
            frontmatter['tags'] = [tags_match.group(1).strip()]
    
    return frontmatter, parts[2]


def extract_wikilinks(content: str) -> list[str]:
    """提取 [[双向链接]]"""
    # [[目标页面]] 或 [[目标页面|显示文本]]
    return re.findall(r'\[\[([^\]|]+)(?:\|[^\]]+)?\]\]', content)


def import_vault(
    vault_path: Path,
    notes_db_path: Path,
    space_id: str | None = None,
    created_by: str = "system",
    owner_account_id: str = "",
    dry_run: bool = False
) -> dict:
    """
    导入 Obsidian vault
    
    Args:
        vault_path: Obsidian vault 根目录
        notes_db_path: notes.db 路径
        space_id: 目标 note_space ID（None = 自动创建）
        created_by: 创建者用户名
        owner_account_id: 所有者账号 ID
        dry_run: True = 只分析不写入
    
    Returns:
        统计信息
    """
    store = NotesStore(notes_db_path)
    
    # 1. 创建或使用 space
    if space_id is None:
        space_id = _id()
        if not dry_run:
            with store._conn() as conn:
                conn.execute(
                    """INSERT INTO note_spaces 
                       (id, title, description, created_by, owner_account_id, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?)""",
                    (space_id, "Obsidian Import", f"从 {vault_path} 导入", 
                     created_by, owner_account_id, _now(), _now())
                )
        print(f"✓ 创建 note_space: {space_id}")
    
    # 2. 扫描所有 .md 文件
    md_files = list(vault_path.rglob("*.md"))
    print(f"✓ 找到 {len(md_files)} 个 markdown 文件")
    
    # 3. 第一遍：导入笔记内容
    note_id_map = {}  # 文件路径 → note_id
    all_links = []     # 延迟处理链接
    
    skipped = 0
    for md_file in md_files:
        # 跳过 .obsidian 配置目录
        if ".obsidian" in md_file.parts:
            skipped += 1
            continue
        
        try:
            content = md_file.read_text(encoding="utf-8")
        except Exception as e:
            print(f"✗ 读取失败 {md_file}: {e}")
            skipped += 1
            continue
        
        # 提取 frontmatter
        frontmatter, body = extract_frontmatter(content)
        
        # 标题 = 文件名（去除 .md）
        title = md_file.stem
        
        # 相对路径（用于链接解析）
        rel_path = md_file.relative_to(vault_path)
        
        # 提取双向链接
        links = extract_wikilinks(content)
        
        # 创建 note
        note_id = _id()
        note_id_map[str(rel_path)] = note_id
        note_id_map[title] = note_id  # 也按标题索引（Obsidian 链接可能用标题）
        
        if not dry_run:
            content_hash = hashlib.sha256(content.encode()).hexdigest()[:16]
            
            with store._conn() as conn:
                conn.execute(
                    """INSERT INTO notes 
                       (id, space_id, title, content, source_json, created_by, 
                        created_at, updated_at, revision, content_hash)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                    (note_id, space_id, title, content, 
                     '{"source": "obsidian", "path": "' + str(rel_path) + '"}',
                     created_by, _now(), _now(), 1, content_hash)
                )
        
        # 记录待处理的链接
        for link_target in links:
            all_links.append((note_id, link_target))
        
        if len(note_id_map) % 50 == 0:
            print(f"  已导入 {len(note_id_map)} 个笔记...")
    
    print(f"✓ 导入完成：{len(note_id_map)} 个笔记，跳过 {skipped} 个")
    
    # 4. 第二遍：处理链接
    linked = 0
    broken = 0
    
    for source_id, target_title in all_links:
        # 尝试匹配目标笔记
        target_id = note_id_map.get(target_title)
        
        # Obsidian 可能用相对路径
        if target_id is None:
            target_id = note_id_map.get(target_title + ".md")
        
        if target_id is None:
            broken += 1
            continue
        
        if not dry_run:
            link_id = _id()
            with store._conn() as conn:
                try:
                    conn.execute(
                        """INSERT INTO note_links 
                           (id, space_id, source_note_id, target_note_id, label, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?)""",
                        (link_id, space_id, source_id, target_id, "", created_by, _now())
                    )
                    linked += 1
                except Exception:
                    # 重复链接，忽略
                    pass
    
    print(f"✓ 链接处理：{linked} 条成功，{broken} 条断链")
    
    return {
        "space_id": space_id,
        "notes_imported": len(note_id_map),
        "notes_skipped": skipped,
        "links_created": linked,
        "links_broken": broken
    }


if __name__ == "__main__":
    import argparse
    
    parser = argparse.ArgumentParser(description="导入 Obsidian vault 到 Cognos")
    parser.add_argument("vault_path", help="Obsidian vault 目录路径")
    parser.add_argument("--notes-db", default="../deploy/nextcloud-trial/.cognos-state/notes.db",
                       help="notes.db 路径")
    parser.add_argument("--space-id", help="目标 note_space ID（不指定则创建新的）")
    parser.add_argument("--user", default="cognos-admin", help="创建者用户名")
    parser.add_argument("--owner-id", default="", help="所有者账号 ID")
    parser.add_argument("--dry-run", action="store_true", help="只分析不写入")
    
    args = parser.parse_args()
    
    vault = Path(args.vault_path).resolve()
    notes_db = Path(args.notes_db).resolve()
    
    if not vault.exists():
        print(f"✗ Vault 不存在: {vault}")
        sys.exit(1)
    
    print(f"Obsidian vault: {vault}")
    print(f"notes.db: {notes_db}")
    print(f"Dry run: {args.dry_run}")
    print()
    
    result = import_vault(
        vault_path=vault,
        notes_db_path=notes_db,
        space_id=args.space_id,
        created_by=args.user,
        owner_account_id=args.owner_id,
        dry_run=args.dry_run
    )
    
    print()
    print("=" * 60)
    print(f"Space ID: {result['space_id']}")
    print(f"笔记导入: {result['notes_imported']}")
    print(f"笔记跳过: {result['notes_skipped']}")
    print(f"链接创建: {result['links_created']}")
    print(f"断链: {result['links_broken']}")
    print("=" * 60)
    
    if args.dry_run:
        print("\n⚠️  这是 dry run，没有实际写入数据库")
        print("   去掉 --dry-run 参数执行真正的导入")
