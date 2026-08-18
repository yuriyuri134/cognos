# Cognos 当前实现边界

当前生效的实现有两层：

- [`phase0/`](phase0/)：经官方 Codex SDK 创建并恢复同一条 thread，保留每次 turn 的精确引用。
- [`web/`](web/)：第一个可打开的整体雏形。在一个持续发生场中与 Codex 沟通，原文追加保存到 Obsidian Vault，服务重启后继续同一 thread。

[`phase1/`](phase1/) 的来源回放探针保留为局部能力，但不再作为网页之前的验收门槛。

项目根目录下的 `models.py`、`database.py`、`test_phase1.py` 是早期固定-schema 实验，保留作历史证据，不是现行基础模型。后续代码不得默认导入它们，也不得因为它们已经存在，就把下列假设当成定案：

- 基础实体只能是五种预列对象；
- 对象必须先有固定 `type` 与全局 `subject_level`；
- 关系必然是预列类型的二元边；
- Movement 必须落入预列事件类型；
- 当前 SQLite schema 能穷尽未来的世界映射。

这不是宣布以后不使用 schema，而是拒绝让一次旧实验冒充不可重读的世界边界。新的结构只在真实回路要求它时进入实现，并保留其来源与形成过程。

启动网页：

```powershell
cd web
.\start.ps1
```
