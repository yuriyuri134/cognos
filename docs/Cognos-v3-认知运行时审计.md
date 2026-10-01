# Cognos 认知运行时对齐审计

更新时间：2026-09-20

这份审计针对当前实际项目，不把设计讨论中的候选概念当成已经实现的能力。

## 当前已经成立的部分

### 世界与活动基底

- Organization、Project、Conversation、文件和 Activity 可以共同构成活动环境。
- 原始发生、Activity 修订、Move、结果和 Context Snapshot 都有持久记录。
- 外部检索、项目取材、用户回答、模型构造和明确发布都有真实结果边界。
- 检索失败、协议错误、网络失败、并发修订和重复请求都有留痕或幂等处理。

这些部分证明 Cognos 已经能让模型在跨轮活动中接触材料并留下可回看的历史。

### 开放活动协议

- 一轮可以只有 message、Formation、tension、Move 或 pause。
- 多个 Formation 可以并存，旧的单一 situation 只作兼容投影。
- Move 可以没有程序操作，也不要求预先知道结果。
- Encounter/Result 可以延迟数轮才重新获得意义。
- 实际 Context 以快照和 hash 保存，能回看模型当时究竟看到了什么。

这些部分证明系统没有再把认识强制压成固定流水线。

### 认知运行时的最小边界

`web/cognitive_runtime.py` 目前整理出：

- 正在形成的多个组织；
- 尚未安顿的 tension；
- 最近遭遇中的未吸收候选；
- 原始材料、参与者、外部世界、构造和其他 Formation 的重新开放通道；
- 当前条件指纹。

`tension_updates` 只是参与者对未安顿内容的记录，状态可以是 `open`、`changed` 或 `resolved`，不等同于系统已经识别了客观张力，也不产生分数。

Move 还可以自愿携带 `formation_refs` 和 `tension_refs`。它们只保存选择轨迹，允许后续检查一次活动是否回应了某个形成或张力，不要求模型事后把这种关联解释成客观因果。

参与者提供的 `id` 与系统生成的 `event_id` 分开保留，既能让后续活动回指原先的形成，也能追溯它在哪一次运行中出现。

## 仍未被证明的部分

### Formation 是否真的改变了生成条件

当前 Formation 和 tension 已经会进入后续 Context，因此具备“可能产生影响”的软件位置。但还没有足够真实实例证明：

- 后续检索方向因此发生了变化；
- 后续 Move 因此出现了原本不会出现的认知运动；
- 外部遭遇能够让 Formation 修改、分裂或退出；
- 同一材料在跨活动中会形成不同的进入方式。

目前不能把 Context 中出现过文本称为 Cognos 已经学会了思考。

### Cognitive Development

系统还没有可靠的慢循环，能够从多个活动中改变以后 Formation、Move 或探索方式的产生条件。当前历史主要改变可见上下文，尚未改变模型或选择机制本身。

### 超出单次模型调用的能力

尚未完成严格对照：

```text
模型 + Cognos 运行时
vs.
模型 + 同样材料的一次性长提示
```

因此目前只能说 Cognos 提供了可持续、可追溯、可接触世界的外部认知环境，不能声称已经创造了独立的思考能力。

## 下一次真实验收

下一批只做一个纵向实验，不新增认知名词：

1. 从一个模糊处境或学习材料开始；
2. 记录模型形成的多个读法和一个开放 tension；
3. 让模型提出一次未知结果的 Move；
4. 让真实用户、外部检索或构造产生 Encounter；
5. 检查下一轮 Move 是否实际改变；
6. 检查旧 Formation 是否被保留、修改、分裂或暂缓；
7. 保存前后 Context Snapshot，区分模型自述与程序真实变化。

只有这个过程在开放处境、学习构造和创作材料中重复出现，才考虑把某种认知运动提升为可复用策略。否则继续保持开放运行边界，不新增 `Lens`、`Subject`、`Thought` 或固定操作模块。

## 当前验证

- Python 全量回归：`173 passed, 2 subtests passed`（2026-09-20，Codex 接线及 SDK 更新后）。
- 本地服务：`/api/health` 返回 `ready`。
- 前端 Activity 资源已加载 `cognitive_conditions` 的张力计数显示。
- 浏览器自动化仍未完成：环境没有 Playwright 依赖；这不影响后端回归，但不能替代浏览器级证据。

## 真实隔离试验

2026-09-20 的 Gemini 隔离试验保存在 `work/cognitive-runtime-gemini-trial-2026-09-20.json`。它支持两个有限结论：开放活动可以不先制定计划；一个模型提出的开放 tension 可以进入下一轮 Context。它同时记录了一次后续运行时失败，因此不能据此宣称认知运行时已经超出基础模型能力。

## 本轮修复：让停用和修订具有实际程序作用

原代码把每个 Formation 事件追加回当前列表，回读时丢失 status；同一个 tension 后来 resolved，早期 open 版本仍然进入未解决列表。这使状态改变没有兑现。

现在按明确 ID 从事件重建当前视图，不按相似文字合并。同 ID 的后续更新替换当前视图，未提供的文本和材料引用保留；dormant 移出活动 Formation 列表，active 可以重新启用。旧事件不改写，当前项保留 revision_event_ids。resolved tension 移到已处理视图。旧单一 situation 明确标识为兼容投影，不能重新把已停用 Formation 注入活动列表；用户独立编辑的 situation 继续保留。失败 Encounter 也继续可见。

验证包括五轮停用/恢复、重新构造服务后从存储接续、原始材料保留、相同措辞的不同 ID 不合并、状态更新超过列表长度边界，以及旧协议回归。162 项程序测试通过。脚本替身中的理解内容是测试预置，不能称为模型能力的反事实证据。

## 模型输入边界：发现与后续修复

检查发现 Gemini/DeepSeek 适配器的 send 会把 state.contents 中的旧会话加入请求。当前 Snapshot 精确保存的是 Activity 交给适配器的提示，不是完整 HTTP 请求，也不包括服务器端可能保留的会话。已有快照不可改写成“包含全部输入”的记录。

因此本轮停用只证明 Activity 当前视图不再把该理解作为 active；不能宣称模型已经看不到旧理解或旧理解不再有影响。下一步应让 Activity 显式选择本轮输入范围，并保留普通聊天的原有会话状态；对不支持明确范围的 runtime 标明限制。处理此边界以后，才适合做有意义的模型对照。

后续已实现：Gemini 和 OpenAI-compatible HTTP 适配器新增 send_activity，使用临时调用状态，不读取或写入普通聊天状态文件及缓存。普通 send 行为保留。Activity 每次请求/重试前保存 runtime_requests（请求正文、固定指令、选用模型、尝试序号和哈希）；不保存认证头或带密钥的 URL。请求记录保存失败则不发送。调用后的运行元数据保存在 event，不再修改调用前的 context。

快照详情接口与界面可回看 runtime_requests；没有此能力的运行时标为 runtime_managed_history_not_captured，不能宣称完整输入受控。HTTP 记录仅覆盖客户端正文，不验证服务提供方内部上下文或真实后端身份。OpenAI-compatible 响应未报告模型时保留 actual_model=null，不用请求名补成响应事实。失败日志只保存错误类型/HTTP 状态等，不复制可能含认证信息的服务返回。

离线传输回归检验预存聊天不进入请求、活动不写回聊天、随后普通聊天正常接续、失败重试记录、记录先于发送，以及请求正文与保存内容相等。全量 168 项通过；服务已启动，health=ready，新界面资源已确认加载。这些是程序与 HTTP 边界证据，尚不是新版本实模型认知效果或浏览器完整使用验收。

当时 Codex App-Server 仍使用原会话路径；后续修复见下。Formation 的停用不意味着从所有历史材料抹除其文字，已标注的历史仍可被重新面对。

## Codex 临时会话与真实创作接续

Codex 现支持独立 send_activity：每次 thread_start(ephemeral=True)，不加载普通聊天绑定；成功和失败均不保存到原聊天状态文件。SDK 入参在调用前进入 runtime_requests。scope 明确标为 fresh_thread_runtime_context_not_captured，因为新会话仍可能有 app-server 额外指令、配置和工具读取，不宣称已记录完整模型上下文。

真实调用先暴露了依赖问题：Python SDK 与其配套 CLI 仍为 0.144.4，虽系统 CLI 较新，默认模型请求仍返回版本过旧。已将虚拟环境和 requirements 一起更新至 SDK/CLI 0.155.1，并通过全量测试。旧失败证据保留为 `work/codex-activity-scope-trial-2026-09-20.json`。

更新后的 `work/codex-activity-scope-trial-2026-09-20-v2.json` 记录了真实两轮创作：第一轮 formation_updates=[]，直接 create 保存续写；程序创建第二份文档；第二轮新 thread 通过 Activity Context 接收文档和执行结果后暂停，两轮均没有被迫给出 Formation。普通聊天绑定保持不变，生产数据未写入。

这支持无先定问题的创作能在两次独立模型调用间实际接续。它不是 Formation 因果效果证明，不是跨活动学习证明；使用本机默认模型，actual_model 仍未知，不能记成 GPT-5.6 high 试用。

对照是辅助证据，不是项目目标。不能通过删除 Formation、额外提供信息或预置模型回答来宣称架构提高了思考能力；实际比较需说明信息、调用预算、模型历史以及随机性差异。
