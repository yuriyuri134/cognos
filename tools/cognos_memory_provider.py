"""Cognos Memory Provider — Hermes plugin that stores memory in Cognos Notes.

POC scope (read-only):
- Load Hermes memory from Cognos notes.db (where source_json contains 'hermes_memory')
- Expose it via prefetch() so the agent sees Cognos-stored memory at turn start
- No writes yet (that requires bidirectional sync + candidate/confirmed flow)

Future: sync_turn() writes back to Cognos; memory tool calls create Notes with
candidate status; user confirmation in Cognos UI flips them to confirmed.
"""

from __future__ import annotations

import json
import logging
import sqlite3
from pathlib import Path
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)

# Placeholder base class until we connect to real Hermes
try:
    from agent.memory_provider import MemoryProvider, RecallStatus, INDICATOR_GLYPH
except ImportError:
    # Stub for standalone testing
    class MemoryProvider:
        pre_compress_checkpoint_api_version = 1
        @property
        def name(self) -> str: return "cognos"
        def is_available(self) -> bool: return True
        def initialize(self, session_id: str, **kwargs) -> None: pass
        def get_tool_schemas(self) -> List[Dict[str, Any]]: return []
    
    class RecallStatus:
        def __init__(self, provider_label: str, count: int, glyph: str = "🧠"):
            self.provider_label, self.count, self.glyph = provider_label, count, glyph
    
    INDICATOR_GLYPH = "🧠"


class CognosMemoryProvider(MemoryProvider):
    """Cognos-backed memory provider (POC: read-only, Hermes sees Notes as memory)."""
    
    pre_compress_checkpoint_api_version = 1  # best-effort for now
    
    def __init__(self):
        self._db_path: Optional[Path] = None
        self._hermes_home: Optional[Path] = None
        self._last_recall_count = 0
        self._cached_memory = ""
    
    @property
    def name(self) -> str:
        return "cognos"
    
    def is_available(self) -> bool:
        """Check if Cognos notes.db exists at the expected location."""
        # Try to find notes.db in the standard Cognos location
        candidate = Path("/mnt/c/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial/.cognos-state/notes.db")
        if candidate.exists():
            return True
        # Also check if config tells us where it is
        try:
            from hermes_cli.config import load_config_readonly
            cfg = load_config_readonly().get("memory", {}).get("cognos", {})
            if path := cfg.get("notes_db_path"):
                return Path(path).exists()
        except Exception:
            pass
        return False
    
    def unavailable_reason(self) -> str:
        return "Cognos notes.db not found at expected path. Set memory.cognos.notes_db_path in config.yaml."
    
    def initialize(self, session_id: str, **kwargs) -> None:
        """Initialize once at agent startup."""
        self._hermes_home = Path(kwargs.get("hermes_home", "~/.hermes")).expanduser()
        
        # Resolve notes.db path
        try:
            from hermes_cli.config import load_config_readonly
            cfg = load_config_readonly().get("memory", {}).get("cognos", {})
            if path := cfg.get("notes_db_path"):
                self._db_path = Path(path)
        except Exception:
            pass
        
        if not self._db_path:
            self._db_path = Path("/mnt/c/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial/.cognos-state/notes.db")
        
        if not self._db_path.exists():
            logger.warning("Cognos memory provider: notes.db not found at %s", self._db_path)
            return
        
        # Pre-load memory so first prefetch is instant
        self._cached_memory = self._load_memory_from_cognos()
        logger.info("Cognos memory provider initialized: %d chars from %s", len(self._cached_memory), self._db_path)
    
    def _load_memory_from_cognos(self) -> str:
        """Load all notes marked as Hermes memory and format them."""
        if not self._db_path or not self._db_path.exists():
            return ""
        
        try:
            conn = sqlite3.connect(str(self._db_path), timeout=5.0)
            conn.row_factory = sqlite3.Row
            
            # Find notes where source_json contains {"hermes_memory": true} or similar marker
            # For POC: treat all notes with 'hermes' or 'memory' in title as memory
            # Production: add proper source_json schema
            rows = conn.execute("""
                SELECT id, title, content, source_json, created_at, updated_at
                FROM notes
                WHERE lower(title) LIKE '%memory%' 
                   OR lower(title) LIKE '%hermes%'
                   OR source_json LIKE '%hermes_memory%'
                ORDER BY updated_at DESC
                LIMIT 50
            """).fetchall()
            
            if not rows:
                return ""
            
            self._last_recall_count = len(rows)
            
            # Format as Hermes expects (similar to built-in MEMORY.md)
            blocks = []
            for row in rows:
                source = json.loads(row["source_json"] or "{}")
                note_type = source.get("type", "note")
                
                # Skip if explicitly marked as not-memory
                if source.get("hermes_memory") is False:
                    continue
                
                content = row["content"].strip()
                if not content:
                    continue
                
                # Each note becomes a memory entry
                # If content has § separators, honor them; otherwise treat as one block
                if "§" in content:
                    blocks.extend(content.split("§"))
                else:
                    blocks.append(content)
            
            conn.close()
            
            # Join with § separator (Hermes memory format)
            return "\n§\n".join(b.strip() for b in blocks if b.strip())
        
        except Exception as exc:
            logger.error("Failed to load memory from Cognos: %s", exc, exc_info=True)
            return ""
    
    def system_prompt_block(self) -> str:
        """Static instruction that memory comes from Cognos."""
        return (
            "Your memory is stored in Cognos Notes. "
            "When you add/replace/remove memory, it will sync to the Cognos Notes system "
            "where the user can see, edit, and manage it through the Cognos UI."
        )
    
    def prefetch(self, query: str, *, session_id: str = "") -> str:
        """Return cached memory (already loaded in initialize)."""
        # POC: return the whole cache; production would filter by relevance to query
        return self._cached_memory
    
    def recall_status(self) -> Optional[RecallStatus]:
        """Report how many notes were recalled."""
        if self._last_recall_count > 0:
            return RecallStatus(
                provider_label="Cognos",
                count=self._last_recall_count,
                glyph="📝"  # notebook emoji
            )
        return None
    
    def sync_turn(
        self, user_content: str, assistant_content: str, *,
        session_id: str = "", messages: Optional[List[Dict[str, Any]]] = None,
        turn_author: Optional[Dict[str, Any]] = None,
    ) -> None:
        """POC: no writes yet."""
        # Future: extract facts from assistant_content, write to notes.db with candidate flag
        pass
    
    def get_tool_schemas(self) -> List[Dict[str, Any]]:
        """POC: no tools yet (would expose Cognos-native memory operations)."""
        return []
    
    def on_memory_write(self, action: str, target: str, content: str, metadata: Optional[Dict[str, Any]] = None) -> None:
        """Mirror built-in memory writes to Cognos Notes."""
        # This is called when the user uses the built-in memory tool
        # We can write to notes.db here to keep Cognos in sync
        # POC: log only
        logger.info("Cognos memory mirror: %s %s (%d chars)", action, target, len(content))
        # Future: INSERT INTO notes with source_json={'hermes_memory': true, 'action': action, ...}


# Plugin discovery entrypoint
def create_provider() -> CognosMemoryProvider:
    """Hermes calls this to instantiate the provider."""
    return CognosMemoryProvider()
