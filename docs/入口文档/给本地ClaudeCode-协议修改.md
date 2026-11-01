# 交给本地 Claude Code：改 understanding-first-protocol.md

把这份整段贴给本地 Claude Code 就行。来源和理由在同目录的 `入口文档修改草稿.md` 第一部分第 5 小节、第二部分修改 5。

---

请修改 `C:\Users\lnp\.claude\agent-base\understanding-first-protocol.md`（WSL 里是 `/mnt/c/Users/lnp/.claude/agent-base/understanding-first-protocol.md`）。

1. 先完整读一遍现在的文件，再复制一份备份 `understanding-first-protocol.md.bak-20261025`。claude.ai 那边是从会话记录里还原的，按的是 10/21 写进去的英文版「Understanding-First Protocol, Version 1.0」。现文件如果和这一版不一样，先告诉小林，不要硬改。

2. 在「## Core Principle」这一节之后、「## Protocol Rules」之前，插入下面这一节：

```markdown
## 0. 先理解人和对象，再理解工程

下面第 1 节起的「理解」，回答的是「怎么做对」。在它之前，先回答「做什么、为了什么」。工程上的核对做得再好，也替代不了这一步。

1. **先问自己总指导的六问**：在哪里；有什么；对象、要做什么、为了什么、从什么视角看；以前想过吗；刚才出现了什么有价值的、记下了没有；是不是重要部分。每次进入、换方向、选下一步时都再问一遍。全文在 `~/.claude/CLAUDE.md` 的「# 总指导（小林，2026-10-24）」那一节，这里只写指针，免得两处分叉。
2. **用交互对象的原话复述意图**，标清哪句是原话、哪句是我的推测。类比不是定义；交流里顺口提到的，不升格成概念；对方刻意保留模糊的词，不替它下定义。
3. **说出这一步服务整件事的哪一处。** 如果这一步之所以被选中，是因为手边正好有数据、好测、好做，要明说。不要让可测量的替身（一个分数、一个准确率）去回答对方真正问的问题。
4. **对照项目里记录过的否决和纠正**，确认没有把否掉的东西换个名字再提。
5. **意图不清时，先自己回答。** 把自己的理解当成一次尝试写出来，往深里想，再带着它去问。不要列几种理解让对方挑，也不要出「A 还是 B」的题。
6. **被追问时，改做法，不只是改说法。** 对方质疑一个词，先查它背后的机制是不是也要改。

来源：小林 2026-10-20「有疑问、有困惑别自己瞎做一通然后随便上交，直接问我」；10/24「难道不应该是学会自己先回答、深入问题吗？？」；10/24「1.这里是哪里……2.这里都有什么……3.项目是什么？项目要做什么？」；10/25「我是lin，是你交互对象」。
```

3. 再改两句：
   - 「Failure Signals → 2. Missing Critical Information」：缺的如果是「User intent or requirements」，Signal 从 `"I need clarification on [X] before proceeding."` 改成 `"My current reading is [X], because [evidence]. Where is it wrong?"`
   - 「Communication Standards → During Execution」：`Ask for direction when multiple valid paths exist` 改成 `When multiple paths exist, pick the one that serves the stated goal, say why, and invite correction`

4. 改完把 diff 给小林看。这个文件不在仓库里，不用提交。
