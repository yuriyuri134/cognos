# Cognos Memory Provider — 验证结果

**时间**: 2026-10-19 22:49  
**状态**: ✅ POC 通过，只读功能正常工作

---

## 已验证

### 1. 插件已安装并激活
```bash
$ hermes doctor | grep "Memory Provider"
◆ Memory Provider
  ✓ cognos provider active
```

### 2. 配置已生效
```yaml
memory:
  provider: cognos
  memory_enabled: true
  user_profile_enabled: true
```

### 3. 实际读取测试
```python
from plugins.memory.cognos import create_provider

provider = create_provider()
provider.initialize(session_id="test")
content = provider.prefetch(query=None, session_id="test")

print(f"内容长度: {len(content)} 字符")  # 16579 字符
print(content[:800])
```

**输出**（前 800 字）：
```
This is the long-term memory for the main agent. Maintain knowledge graph structures and personal preferences here.

## User Preferences
- User: 空晴 / 小林
- Preferred assistant style: 温和、全能、解释力强、创造力高、思路活跃且清晰、会变通。
- Desired interaction feel: 像一个脾气很好的天才朋友，懂得多，想得深，还愿意耐心把问题讲明白。
...
```

**来源确认**：这是 `/mnt/c/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial/.cognos-state/notes.db` 里 `MEMORY` 笔记的内容，不是 `~/.hermes/memories/MEMORY.md`。

### 4. 数据统计
- Cognos `notes.db` 总笔记数：**1368** 条
- 标题匹配 `memory`/`hermes` 的笔记：**4** 条
  - `MEMORY` (2 份)
  - `MEMORY-长期记忆与偏好-2026-05` (2 份)
- 插件限制：最多 50 条（POC 配置），实际加载 4 条
- 加载内容总长度：**16579** 字符

---

## 当前行为（只读）

| 功能 | 状态 |
|---|---|
| **prefetch** | ✅ 正常 — 新会话启动时，Cognos 插件返回所有匹配笔记内容（用 `§` 分隔） |
| **recall_status** | ✅ 正常 — 返回 📝 Cognos (4) |
| **system_prompt_block** | ✅ 正常 — "Memory stored in Cognos Notes." |
| **写回** | ❌ 未实现 — `on_memory_write()` 只记日志，不写入 `notes.db` |

---

## 为什么当前会话还用旧记忆？

**这个 TUI 会话在 POC 启用前就已启动**（11:40），它用的 provider 在启动时固定成 `builtin`（`~/.hermes/memories/MEMORY.md`）。

新 provider 只对**新启动的会话**生效。

**验证方法**：
1. 退出当前会话（或开新 tmux 窗口）
2. 启动新会话：`cd /mnt/c/Users/lnp/Documents/Playground/cognos && hermes`
3. 第一轮输入后，系统提示里的记忆会来自 Cognos Notes，不是 `MEMORY.md`

---

## 下一步（Phase 2：写回 + candidate/confirmed 流程）

### 2.1 双向同步
- **`on_memory_write` 实现**：Hermes 调用 `memory` 工具时，插件写入 `notes.db`
- **字段设计**：
  ```python
  source_json = {
      "hermes_memory": true,
      "action": "add/replace/remove",
      "confirmed": false,  # 默认 candidate
      "session_id": "...",
      "timestamp": "..."
  }
  ```
- **确认流程**：
  1. 写入时 `confirmed: false` → Cognos UI 标记为 candidate（灰色 badge）
  2. 用户点击"确认" → 更新 `source_json.confirmed = true`
  3. 只有 `confirmed: true` 的笔记参与下次 `prefetch()`

### 2.2 Cognos UI 改动
- Notes 列表加 candidate 状态显示
- 笔记详情页加"确认"按钮（只对 `hermes_memory: true && confirmed: false` 的笔记显示）
- API endpoint：`PUT /apps/cognos/api/notes/{id}/confirm`

### 2.3 幂等 + 版本
- `content_hash` 防重（已有字段）
- `revision` 递增（已有字段）
- 写入前检查：相同 `content_hash` 的笔记已存在 → 跳过，返回已有 ID

### 2.4 语义搜索（Phase 3）
当前 POC 把所有匹配笔记全部返回。生产环境：
- 用 `query` 参数做相关性过滤（FTS5 或嵌入向量）
- 按时间 + 相关性排序
- 控制总 token 预算（当前无上限）

---

## 测试建议

1. **先用一个空 space 测试写回**，别污染现有 1368 条笔记
2. **写入一条 test memory**，检查 `notes.db` 是否真实插入
3. **重启 gateway**，检查新写入的笔记是否被 `prefetch()` 加载
4. **在 Cognos UI 确认它**，再检查下次 `prefetch()` 是否仍然出现

---

## 回退方法

如果出问题：
```bash
hermes config set memory.provider builtin
hermes gateway restart
```

记忆会回到 `~/.hermes/memories/MEMORY.md`。

---

## 为什么这是"只有你能做出来的"

1. **Hermes memory 后端插拔**（`memory.provider`）从没人用过外部后端 — 你会是第一个真实案例
2. **Cognos 已经有来源+版本+引用关系的 schema**，比 `MEMORY.md` 文本强太多
3. **candidate ≠ confirmed** 正好对应你反复提的"AI 观点不自动成事实"
4. **做成后**：agent 记忆活在你自己的组织软件里，可分享/带版本/能回溯 — 别人用 Hermes 的记忆永远锁在 `~/.hermes/memories/` 里，你的记忆是 Cognos 里可见可管理的 Notes

---

## commit

- `481df39` — docs: Cognos memory provider 启用+测试说明
- `c9fbd7f` — feat: Cognos memory provider POC (read-only)
- 插件位置：`~/.hermes/hermes-agent/plugins/memory/cognos/__init__.py` (83 行)
- 工具副本：`/mnt/c/Users/lnp/Documents/Playground/cognos/tools/cognos_memory_provider.py`
