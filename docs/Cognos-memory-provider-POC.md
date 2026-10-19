# Cognos Memory Provider — 启用说明

## 已完成（POC 阶段）

插件位置：`~/.hermes/plugins/memory/cognos/__init__.py`  
功能：读取 Cognos `notes.db` 中标题含 `memory` 或 `hermes` 的笔记，作为 Hermes 记忆注入到每轮开始  
状态：**只读**（写回待实现）

## 启用步骤

### 1. 配置
```bash
hermes config set memory.provider cognos
```

或手动编辑 `~/.hermes/config.yaml`：
```yaml
memory:
  provider: cognos
  memory_enabled: true
  user_profile_enabled: true
```

### 2. 重启 gateway
```bash
hermes gateway run --replace
```

### 3. 验证
启动新会话，第一轮输入后检查日志：
```bash
tail -50 ~/.hermes/logs/gateway.log | grep -i cognos
```

应该看到：
```
Cognos memory provider initialized: XXXX chars from /mnt/c/.../notes.db
```

在会话里输入 `你的记忆从哪里来？` ——agent 应该回答 "Cognos Notes"。

## 当前行为

- **prefetch**: 每轮开始时，Cognos 插件返回所有匹配笔记的内容（用 `§` 分隔，Hermes 记忆格式）
- **recall indicator**: 📝 Cognos (N) —— N 是加载的笔记数
- **system_prompt_block**: "Memory stored in Cognos Notes."
- **写入**: `on_memory_write` 会记录日志，但不会真正写回 notes.db

## 现有 notes.db 状态

- 路径：`/mnt/c/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial/.cognos-state/notes.db`
- 笔记总数：**1368** 条
- 匹配 `memory`/`hermes` 的笔记：需要实测（POC 限制 50 条）
- schema：`id, space_id, title, content, source_json, created_by, created_at, updated_at, revision, content_hash`

## 下一步（写回 + candidate/confirmed 流程）

### Phase 2: 双向同步
1. **`on_memory_write` 实现**：Hermes 用户调用 `memory` 工具时，插件写入 `notes.db`
   - 字段：`source_json = {"hermes_memory": true, "action": "add/replace/remove", "confirmed": false}`
   - 默认 `confirmed: false` —— 进入 Cognos 时是 **candidate**

2. **Cognos UI 确认流程**
   - Notes 列表标记 candidate 状态（UI 用灰色 badge）
   - 用户点击"确认"→ 更新 `source_json.confirmed = true`
   - 只有 `confirmed: true` 的笔记才参与下次 `prefetch()`

3. **幂等 + 版本**
   - `content_hash` 防重
   - `revision` 递增

### Phase 3: 语义搜索
当前 POC 把所有匹配笔记全部返回。生产环境：
- 用 `query` 参数做相关性过滤（FTS5 或嵌入向量）
- 按时间 + 相关性排序
- 控制总 token 预算（当前无上限）

## 测试建议

1. **先用一个空 space 测试写回**，别污染现有 1368 条笔记
2. **写入一条 test memory**，检查 notes.db 是否真实插入
3. **重启 gateway**，检查新写入的笔记是否被 prefetch 加载
4. **在 Cognos UI 确认它**，再检查下次 prefetch 是否仍然出现

## 回退

如果出问题：
```bash
hermes config set memory.provider builtin
hermes gateway run --replace
```

记忆会回到 `~/.hermes/memories/MEMORY.md`。
