# Cognos 笔记与会话迁入：第一批

更新：2026-10-08。这里只是迁入试验清单，不是全量导入授权。

## 目的

验证 Cognos 是否能承载真实的笔记、会话交接和结构化材料，同时保留来源边界。第一批不自动总结、不自动分类、不调用模型、不修改原始文件。

## 来源规模盘点

只读盘点结果：

| 来源 | 文本/结构文件 | 总字节数 | 说明 |
| --- | ---: | ---: | --- |
| `C:\Users\lnp\Documents\Obsidian Vault` | 728 | 1,186,319 | 含 Markdown、JSON/JSONL、Obsidian 配置；配置不作为内容导入 |
| `C:\Users\lnp\Documents\ChatGPT\New project` | 602 | 232,819 | 含 Cognos 交接、Packet、试用记录和工作文档 |
| `C:\Users\lnp\Documents\Playground\cognos` | 784 | 6,880,631 | 含代码、文档、测试和工作证据；代码不作为笔记全量导入 |

这些数字只表示文件发现，不表示内容已阅读、相关或适合迁入。

## 第一批样本

1. `C:\Users\lnp\Documents\Obsidian Vault\00 Inbox\项目方向清单-待展开.md`
   - 类型：带 frontmatter 的用户笔记
   - 用途：验证用户自定义元数据、未定型内容和“建立空间但不先分类”
2. `C:\Users\lnp\Documents\Obsidian Vault\10 Projects\Spark-核心设计哲学.md`
   - 类型：项目 Markdown 笔记
   - 用途：验证长笔记、标题层级和内部结构
3. `C:\Users\lnp\Documents\ChatGPT\New project\Cognos-给pi-agent的执行交接.md`
   - 类型：跨 Agent 交接文档
   - 用途：验证参与者、时间、版本和执行边界
4. `C:\Users\lnp\Documents\Obsidian Vault\10 Projects\_原料_异轴思想.json`
   - 类型：结构化外部/检索材料
   - 用途：验证 JSON 原文保留与结构化读取并存；失败检索不能被显示成成功事实
5. `C:\Users\lnp\Documents\Playground\cognos\docs\Cognos-空间与认知架构-工作稿.md`
   - 类型：当前空间架构工作稿
   - 用途：让迁入系统直接承载正在形成的认知架构

若样本文件不存在，迁入器必须报告缺失并停止该项，不用相似文件自动替换。

## 迁入最小记录

每个迁入记录至少保留：

```json
{
  "source_id": "稳定来源指纹",
  "source_root": "Obsidian Vault | ChatGPT project | Cognos repository",
  "source_path": "原始相对路径",
  "source_kind": "markdown | json | jsonl | text",
  "title": "不改变原题目的显示标题",
  "raw_content": "原文或不可变原文引用",
  "content_hash": "原文哈希",
  "source_mtime": "来源修改时间",
  "imported_at": "迁入时间",
  "frontmatter": "可解析时保留，解析失败不丢正文",
  "links": "明确发现的链接，不推断语义关系",
  "participants": "只有来源明确给出时才记录",
  "import_status": "imported | missing | failed"
}
```

核心原则：迁入记录不能替换或覆盖原始文件；解析后的标题、frontmatter、链接是派生信息，原文和哈希是来源证据。

## 第一条使用闭环

```text
选择第一批样本
→ 只读建立来源记录
→ 在 Cognos 中打开原文
→ 用户建立一个工作空间
→ 用户手动连接两份材料
→ 刷新/重新进入仍能看到来源和连接
→ 用户点击或 @ Agent
→ Agent 只提出整理或关系候选
→ 用户决定是否接受
```

第一条闭环不要求三空间已经实现，不自动把材料变成项目任务/决定，也不自动发布到项目现实。

## 暂不做

- 不全量导入 Obsidian、ChatGPT 或 Cognos 仓库；
- 不把所有会话压缩成一个总摘要；
- 不自动判断主体/对象/世界归属；
- 不自动生成全局图谱；
- 不因存在 `[[wikilink]]` 就宣称关系已经成立；
- 不让 Agent 在没有点击或 `@` 的情况下处理材料；
- 不把迁入成功当作认知架构有效的证据。
