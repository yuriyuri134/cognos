# Cognos v3 施工台账

这是 Cognos 重构的持续交接文件。它把研究判断、工程批次、验收结果和未决问题放在同一个地方，后续执行者无需重新阅读全部聊天才能继续。

分批实施细则见同目录的《Cognos-v3-分批实施计划》；长篇研究对话不直接作为执行指令，后续以每批 Packet、真实试用和回归结果推进。

项目过程留下的认识结晶见《Cognos-v3-行动思想》。施工台账回答“发生了什么、证据是什么”；行动思想回答“这些过程怎样改变了我们对 Cognos 的理解，以及下一步因此怎样行动”。重要 Packet、真实试用、删除实验和失败修复之后，两者都应复核；行动思想允许被后续证据修订或撤回，不把工程经验冻结成教义。

## 当前目标

把 Cognos 做成帮助“尚未完全形成的尝试”持续展开的开放运行骨架：Organization 是现实环境，Activity 是其中持续展开的过程。`Material → Formation → Move → Encounter` 是第一条运行脊柱，不是强制流水线，也不是最终认识本体。

最高工程约束：已有结构可以组织未来，但不能穷尽未来。任何一轮都可以只有材料、形成、表达、Move 或暂停；真实结果进入以后，可以延迟数轮才获得意义。

## 已完成批次

### Packet K11 · 用户直接控制单项 Formation（2026-09-22）

- Activity 增加单项 Formation 更新接口；用户可以直接暂缓、重新启用或修订文字，不再只能等待模型在下一次回复里代为更新。
- 用户更新不另建“理解表”，而是追加为同一套 run event 中的 Formation 更新，因此当前视图、修订谱系与下一轮 Context 使用同一 replay 结果。
- 更新保留 occurrence 与参与者身份；重复 request_id 返回同一 run，不重复写入。修订引用仍经过现有材料引用校验。
- 界面在每张暂定读法卡片上增加“暂缓/重新启用”和“修订文字”；旧版本继续可在形成与修订记录中查看。
- 定向 API 回归与历史浏览合计 `40 passed, 5 subtests passed`，Python/JS 语法和 `git diff --check` 通过。新增界面尚未做 Playwright 自动化验收，因为当前机器未安装 Playwright。
- 这证明用户能直接改变下一轮使用哪些 Formation，不证明某个 Formation 更正确，也不建立唯一当前结论。

### Packet K10 · 独立浏览原始历史，减少当前筛选盲区（2026-09-22）

- 增加 `browse_project_history` 与 Activity `browse_history`：不依赖当前关键词、作者或类型筛选，按 append-only occurrence 身份从新到旧有界浏览当前项目的原始发言与外部执行结果。
- 每项只展示最多 500 字并明确是否截断；`matches_current_filters` 只表示是否命中当前字面筛选，不被解释成相关性或理解状态。
- 翻页游标使用 occurrence 身份，新记录追加后旧游标仍稳定；选中记录后仍沿用 `read_occurrence` 分段回读全文。
- Activity 页面“引入已有结果或发言”改为独立浏览入口，可回到最新或继续查看更早记录；引入时保存完整原文和来源。
- 补上 `/imports` 的项目边界校验：明确属于其他项目的 occurrence 不能直接带入；历史上没有 `project_id` 的旧 occurrence 继续兼容。
- 定向回归 `39 passed, 5 subtests passed`；随后全项目回归为 `188 passed, 7 subtests passed`。当前机器缺少 Playwright，因此新增浏览 UI 尚未完成自动化浏览器检查。
- 这条通路只能让未命中当前筛选的内容重新可见；系统仍不会自动判断“遗漏了什么最重要”，也没有因此解决检索偏向本身。

### Packet K9 · 多理解视图与独立补充保护（2026-09-21）

- 模型输入与界面共用 collect_activity_updates 的事件身份及 project_updates 的当前状态，避免两套逻辑各自解释历史。
- 当前活动详情返回 understanding（active、dormant、原始更新历史）；明确请求过去 revision 时不冒充可重建当时的事件投影，因为状态更新并不都增加活动修订。
- 界面展示多个暂定读法、参与者、引用版本、暂缓区域和逐项修订记录。来源材料可以点击回读。相同措辞的不同 ID 不合并。
- 发现并修复模型 Formation 自动覆盖独立 situation 补充的问题：兼容投影只写入空槽或此前的投影槽，用户补充保持独立。编辑个人补充不再默认填入模型最后一句话。
- 全量 184 passed, 7 subtests passed；前端语法和 diff 检查通过。API 检查并存、暂缓、来源身份、界面投影与下一模型输入一致，以及历史版本不伪装成当前投影。
- 扩展基础 Chrome 检查，实际验证并存→暂缓→查看修订→刷新保持→用户补充未覆盖；原基础路径、重试和移动布局同时通过。证据在 `work/ui-multiple-readings-2026-09-21/`。fixture 已正常关闭。
- 没有据此声称这些读法更正确或认知能力提高；用户直接单项控制及未知遗漏材料发现仍需推进。

### Packet K8 · 真实构造迁移及界面核对（2026-09-21）

- `work/cognos-transfer-learning-kn07jryx.json`：从真实旧运行复建 XOR 差值构造；新表是明确标注的人工输入。三轮模型活动分别另建并采用迁移检查、读取来源后建立含交互项的新构造、面对实际结果解释并暂停。
- 快照验证采用方式改变了后续实际输入；第二轮主动回读仍把旧来源带回。源活动和旧构造均未改写。详见《Cognos-跨活动构造试用-2026-09-21》。
- 不能宣称自动发现迁移机会、Formation 字段产生因果作用、长期学习或优于单模型。任务提示明确要求检查适用性。
- 基础活动 Chrome 贯通检查通过，覆盖导入、采用与修订、实际操作结果、重试去重和移动布局；它使用隔离脚本参与者。
- 修正结果显示：原始发生的区间回读不再显示 undefined 条记录；计算结果、跨活动带入分别标明来源性质。
- 183 passed, 7 subtests passed；前端语法与 diff 检查通过。总体未完成事项见《Cognos-v3-当前完成边界》。

### Packet K7 · 同项目跨活动带入（2026-09-21）

- 材料区新增“从另一活动带入材料或构造”。用户选择源活动、材料、带入方式及可空的适用说明；不必手写标识。
- material 保留指定版本快照；construction 先纯求值检查实际依赖，复制对应版本并重绑定本地 read/call。字面数据不当作代码重写，同一源同时被读取为数据和调用为程序时分别保留。源活动已采用的程序版本绑定继续有效。
- 带入只增加候选，不自动采用。来源材料不可改写；本地程序定义可修订并明确采用。来源活动不会被修改，后来的源修改也不改变已带入的版本。
- 来源清单保存源活动/修订、每份原文/版本/原发生引用，以及参与者的适用说明；说明不视为已经验证的方法结论。真实结果可作为 material 带入，其原计算发生可循清单回查。不会自动带入整段聊天、模型身份或未选内容。
- API 与模型操作共用通路。inspect_activities 可显式发现同项目活动/材料目录；import_activity 按明确版本带入。直接界面请求具有持久去重标识、修订冲突检查与项目范围检查。
- 全量 183 passed, 7 subtests passed。覆盖依赖引用重绑定、采用程序的版本固定、数据/代码区别、无效构造、源修改隔离、本地修订、重复请求、跨项目拒绝与过期目标。
- 隔离 Chrome 浏览器检查通过：选择构造→带入三份材料（构造、依赖、来源说明）→未自动采用→用户采用→页面实际值 21；无 pageerror。fixture 已正常关闭，没有调用模型或修改生产数据。
- 仍缺：模型在新处境下判断适用性并修订构造的真实实例、自动推荐、跨项目共享、长期能力变化。此批不能称为已实现自主学习。

### Packet K6 · 实际计算结果成为材料（2026-09-21）

- evaluate 的实际 value 直接保存为不可变 JSON 文档；返回 document 标识，后续组合可以 read/select，无须模型转写。
- 追加式计算记录保留表达式、输入活动版本、实际读取的材料版本、调用的程序版本及请求发生标识。材料指回这条记录。程序计算仅验证表达式的执行，不验证模型选择的假设或转写输入。
- 修改原输入不触发旧结果重算或覆盖。新结果不会自动替换采用方式；计算失败不生成成功结果材料。输入与结果仍可能在其他活动中失效，不能因不可改写而视为永真。
- API 回归覆盖结果的实际值与来源、下一调用可见、旧输入改动后的结果稳定性、结果被另一计算读取、失败及重复请求。179 passed, 7 subtests passed；前端语法和 diff 检查通过。
- 界面将这类文档标为“程序结果 · 不可改写”。服务已重启，health=ready，新标签资源已确认加载。完整浏览器交互验收仍缺。
- 活动内的结果复用已成立；跨活动带入、方法适用范围与推荐机制仍未完成。
- 实模型三轮试用 `work/cognos-learning-organization-vg09l7n1.json`：第一轮保存 XOR 四个计算值；第二轮真实 read/select 该结果第 1 版计算差值 −2；第三轮解释后暂停。没有预置响应，没有 Formation，也没有采用新组合。此证据支持结果构造的连续性，不支持跨活动学习或独立思考能力增益。

### Packet K5 · 采用方式兑现到输入与真实学习构造（2026-09-21）

- 发现 adopted 组合虽然能 slice/select/replace，Context 仍在 active_materials.entries 重新注入依赖全文，削弱实际选材作用。
- 已采用时正文以 compiled 输出为准，依赖条目保留版本、来源和明确 read_operation；未采用时保留原全部材料输入。全目录、用户输入、实际结果与显式回读独立可达。界面区分“来源引用”与全文材料。
- 三轮 API 回归验证采用局部 → 下一输入只展开局部 → 显式回读原版本进入结果；原始材料未改，采用方式不阻挡结果。全量 177 passed, 7 subtests passed；前端语法与 diff 检查通过。
- 真实隔离学习证据：`work/cognos-learning-organization-dxozfvm7.json`；源是用户此前提供的聊天式学习材料。模型两轮选择 XOR、提出可计算表达式、接收程序结果，再区分分步计算与独立可加分解。没有 Formation 更新，没有采用新的组合。普通聊天状态未创建，生产数据未写入。
- 计算输入的真值表是模型从文本转写的 literal，程序核验的是表达式计算，不是独立验证模型转写无误。实际响应模型未报告，不记成 GPT-5.6 high。
- 因此真实试用支持构造/执行/回读的接续，不支持“采用方式提升理解”、跨活动学习或架构超越单模型的结论。下步需要将实际构造结果更便利地作为可引用材料，并继续考察组织方式的可修改性。

### Packet A · Context Assembly 与快照

- 新增 `web/context_assembly.py`，把 active materials、formations、recent encounters、retrieved history、affordances、user input、environment、participant、runtime 分桶。
- 新增 `activity_context_snapshots` 持久表，保存实际渲染提示、上下文、SHA-256、活动修订、run 和调用序号。
- `ActivityService` 在每次模型调用前保存快照，并在 run event 中记录快照 id/hash。
- 保留旧 prompt 与旧 state 字段，当前没有把单一 situation 强行迁移成多 Formation。
- 提供 `GET /api/activities/{id}/contexts` 与详情接口。

### Packet B · 开放传输信封

- `parse_activity_reply` 支持 `message`、`formation_updates`、`move(text/refs/operations)`、`pause`。
- message、formation、move、pause 都可缺省；Move 可以没有 operations。
- 旧 `answer/situation/commands` 与 `move.intent/execution` 兼容读取。
- 旧的空 commands 仍表示暂停；新信封可以明确提出无操作 Move。
- 当前单一 `state.situation` 只作为兼容投影；事件保留全部 formation_updates。
- Packet D 修复跨轮连续性：历史事件中的全部 formation_updates 会重新进入下一轮 Context；引用另行记录验证状态，不把引用自动当作事实。

### Packet C · Runtime Profile 与界面修正

- 新增 `web/runtime_profile.py`，公开 provider、endpoint、model、reasoning_effort、capabilities、fallback 和连接状态，不暴露密钥。
- Participant、`/api/models`、Activity Context Snapshot 都能看到运行时 profile。
- 活动界面把“当前理解”改为“正在形成”，能显示新 `message` 与 `move_proposed` 状态。

### Packet E-1 · 实际材料正文与上下文查看

- `active_materials` 现在同时保留材料目录和本轮编排实际读取的带版本正文，并记录 `included_by`、来源 occurrence 与作者；目录不再冒充本轮实际材料。
- 活动页新增“本轮实际上下文”入口：默认显示调用摘要，展开后查看分桶内容，实际渲染提示按需读取。
- 定向测试 `24 passed`；完整回归需在同步实际项目后再次运行。

### Packet F-1 · 从组织发生启动 Activity

- 新增 `POST /api/projects/{project_id}/activities/from-occurrence`，只允许从当前项目的发生开始 Activity，并把原始正文作为不可变材料保留来源。
- 项目工作台的每条发生增加“从此开始活动”入口，直接进入对应 Activity；不会自动创建决定、工作项或项目事实。
- 回写组织环境的确认操作尚未实现，下一批再处理草稿/提议到项目事实的明确提交。
- 定向测试 `18 passed`、前端 `app.js` 语法检查通过；最新全量回归 `144 passed`。

### Packet F-2 · Activity 结果明确返回 Organization

- 新增 `POST /api/activities/{id}/publish`，只在用户明确确认后，把选定材料返回为项目消息、开放工作项或“提议中的决定”。
- 返回对象带有 Activity、材料版本和发布请求引用；重复请求返回原对象，不重复创建。
- Activity 页面增加“返回项目（需确认）”入口，模型输出仍只停留在 Activity，发布前可编辑正文。
- 定向测试覆盖三种返回与幂等行为；前端 `activity.js` 语法检查通过；隔离副本最新全量回归 `149 passed`。
- 尚未实现自动发布、文件系统写入或未经确认的项目事实升级；这些继续禁止。

### Packet H-0 · 创作材料低成本回归

- 新增回归：从几个未解释的创作片段开始，参与者可以提出“先续写这一幕”的无操作 Move 并暂停。
- 验收确认：没有强制生成计划、目标或新材料；协议保留提议本身，Activity 材料不会因为模型表达自动增加。
- 定向活动测试 `25 passed`；当前隔离副本全量回归 `147 passed`。该回归目前是协议级测试，尚待与 GPT-5.6 high 的真实创作试用合并记录。

### Packet I-0 · 三实例比较复核

- 新增《Cognos-v3-实例比较复核》，区分两个 GPT-5.6 high 真实实例、一个协议级创作回归，以及它们各自能支持和不能支持的结论。
- 三个实例目前共同支持：可选 Formation、薄 Move、真实 Result 回流和 Context 快照；没有足够重复证据新增 Space、Subject、Lens 或长期 Memory。
- 当前仍未完成：F-2 实际项目同步、浏览器端完整路径、第三个 GPT-5.6 high 创作试用和跨活动长期作用验证。

### Packet G-1 · Runtime 提供者与适配器分离

- Runtime Profile 新增 `adapter` 字段，并优先记录运行时的线协议 `provider`；历史 `DeepSeekRuntimeAdapter` 类名继续兼容。
- OpenAI-compatible 端点接入 GPT-5.6 时，不再把模型身份误写成 DeepSeek；密钥仍不进入公开 profile。
- 定向 Runtime 测试通过；隔离副本最新全量回归 `149 passed`。

## 最终交付门（当前未通过）

以下条件全部满足后，才可以把本轮重构称为实际项目完成：

1. 将同步清单中的文件写入实际项目，并保留同步前备份；
2. 在实际项目运行全量回归，结果覆盖隔离副本新增的 F-2/G-1 测试；
3. 实际运行一次“项目发生 → Activity → Context → 用户确认 → Organization 返回”的路径；
4. 在当前可复核的 Runtime Profile 下完成开放处境、学习构造两个真实实例，并保存 Context 与结果；若使用 GPT-5.6，必须同时保存可复核的运行回执；
5. 创作实例至少完成一次真实或明确标注为协议级的回归；
6. 更新实例比较复核，确认没有证据要求新增 Space、Subject、Lens 或长期 Memory。

此处为早期阶段记录；以文末追加复核为当前状态。旧 GPT-5.6 证据已在另一个隔离工作目录找到，见下文链接。

同步脚本 `work/sync-packetF.ps1` 已支持参数化源/目标目录，并在隔离临时目标验证：10 个文件复制成功，预先存在的 `activity_api.py` 会先进入带时间戳的备份目录。

## 当前真实验收

- 当前实际项目全量回归：162 项通过；旧隔离副本数字保留在历史施工记录中，不作为当前状态。
- 新增测试覆盖：Context Snapshot 幂等/hash、空形成、开放信封、多个 formation、无操作 Move、旧协议兼容、Runtime Profile 无密钥泄露。
- 2026-09-19 的 GPT-5.6 high 试用记录已在隔离工作区找回。之前仅因实际项目目录未找到而撤下验收表述，搜索范围不足；它支持旧版试用事实，不替代本轮状态投影的实模型验收。
- 2026-09-20 使用本机已配置的 Gemini `gemini-3.7-flash` 完成一次隔离真实试验；第一轮没有跳到学习计划，而是提出开放 tension，并在下一次 Context Snapshot 中保留。第二次运行时失败，失败边界被如实记录。
- 学习构造有旧版 GPT-5.6 high 隔离试用记录和程序回归；本轮新增的停用/恢复能力目前只有程序回归。
- 试用暴露的边界：开放活动的 active materials 仍以材料目录与编排结果为主，原文在渲染提示中可见但没有在快照分桶中单独呈现完整正文；下一轮需要区分“材料索引”和“实际纳入的材料内容”。学习活动第一轮停在形成/暂停，说明协议能够容纳不行动，但 UI 与回归记录还需要更清楚地显示“没有 Move”与“暂停”的差别。
- 当前判断：第一条开放活动脊柱值得保留，暂不引入 Space、Subject、Lens、长期 Formation 等新本体。下一步应优先增强 Context 可见性、组织来源启动路径和跨实例比较，而不是继续增加概念模块。

### 真实试用证据

可复核的真实试用证据：`work/cognitive-runtime-gemini-trial-2026-09-20.json`，以及 [旧 GPT-5.6 隔离试用](<C:/Users/lnp/Documents/ChatGPT/New project/work/gpt56-trials-2026-09-19-v2.json>)。文件分别保存在实际项目和旧隔离工作区，不应混为同一版本的验收。

## 下一批顺序

1. 浏览器实测当前 Context 查看入口，并检查长提示按需展开、快照摘要与真实调用一致。
2. 浏览器实测从项目消息启动、查看 Context、编辑材料并返回项目的完整路径。
3. 建立两个真实极小回归：开放现实处境、学习/构造；创作只作第三个低成本回归。
4. 比较三个实例中重复发生的作用，再决定是否抽象长期 Formation、Subject 或更深层结构。

## 明确禁止

- 不建立 Space、Lens、Subject、Judgment、Memory 等最终本体模块。
- 不把 Activity 变成唯一环境；Organization/Project/Conversation/Files 继续是现实材料环境。
- 不把所有历史默认塞进 prompt；Context Assembly 必须可解释、可回看。
- 不要求每轮都有 Situation、Move、Result，不要求下一轮解释为何没用上一结果。
- 不引入自动 Agent 对吐、round-robin、always-on、自主循环、全局知识图谱或 benchmark 扩张。
- 不把 API key 写入代码、Vault、raw occurrence、Context Snapshot 或结果回执。

## 继续工作时的汇报格式

每个工程批次都报告：

1. 实际改动的文件与承载的关系；
2. 保留的兼容行为；
3. 新增或修改的测试；
4. 真实活动中观察到的偏移；
5. 仍未解释的机制；
6. 是否值得进入下一批。

### Packet J-0 · 外部检索底座（第一批）

- 检索仍保持为可选能力，但查询 URL 现在经过编码；搜索跳转 URL 会清理明显追踪参数并保留可回指地址。
- 搜索来源增加 provider、原始 query、取得时间；发送给模型的检索材料仍明确标注为候选材料，不自动升格为事实。
- 增加可选的来源正文提取：只有显式打开 `SEARCH_FETCH_CONTENT=1` 时才访问前几个候选页面；正文、空页面和读取失败分别记录，失败不会伪装成正文。
- 用户原始发生现在同时保存本次检索来源与实际注入模型的材料快照；模型调用失败时，检索材料不会随之丢失。
- 定向检索与服务测试通过；当前项目全量回归 `131 passed`（pytest；浏览器 fixture 可启动，但自动化浏览器依赖未安装）。
- 这只是可靠接触的底座，尚未完成查询规划、多方向探入或 Activity 中的外部 Encounter。下一批应先把检索从消息附加项接入 Activity，而不是继续增加搜索引擎数量。

### Packet J-1 · Activity 外部探入

- Activity Envelope 新增 `retrieve` 操作，可一次提出 1 至 8 个查询候选，并保留每个候选的 `purpose` / `axis`；不会把复杂表达强行压成一个关键词。
- 检索执行结果作为不可变 JSON 材料进入 Activity，并追加一条外部检索发生；每个查询独立保存成功、空结果或失败，来源、正文提取状态和失败信息保持可回指。
- 下一轮 Context 会看到新材料和真实检索遭遇；检索结果不会自动修改 Formation，也不会自动返回 Organization。
- Activity 界面现在能显示外部检索结果的查询计数、失败计数和进入活动的材料标识；Context 材料计数兼容新的分桶结构。
- 检索结果会随当前操作结果带回下一轮 Context；完整 payload 仍保存在不可变材料中，下一轮只接收有界摘要，避免把原始页面无限注入模型。
- 来源正文保存抽取状态、响应长度、哈希和有限原文片段；原始片段不进入模型提示，页面脚本或隐藏文本不会自动变成认识材料。
- 新增来源指纹、正文提取和 Activity 回流测试；当前项目全量回归 `131 passed`。
- 当前仍未完成：查询方向由模型如何形成的真实 GPT-5.6 试用、外部正文提取在当前网络/中转环境中的实测、来源之间冲突的显式比较视图。当前机器对 Bing/DuckDuckGo 的直连实测失败，不能把中转站的模型可达性当成搜索可达性；这些应作为下一批验收，不应假装已解决。

### Packet K-0 · 认知运行时的最小开放边界

- 新增 `web/cognitive_runtime.py`，只整理已有事件中的 Formation、可选 tension、最近遭遇和 Move，不宣称已经定义“理解”或认知操作本体。
- Activity 信封增加可选 `tension_updates`；它是参与者指出的尚未安顿内容，可为 `open`、`changed` 或 `resolved`，不要求每轮生成，也不转换成分数。
- 每次 Context Snapshot 增加 `cognitive_conditions`：未解决张力、最近遭遇的未吸收候选、重新开放通道和条件指纹。原始材料、用户、外部世界、构造和多个形成始终保留重新进入的路径。
- 这使“形成改变未来生成条件”第一次有了可回看的运行位置，但尚未证明模型因此获得了超出单次 prompt 的新能力；下一步必须用真实活动检验张力是否改变后续 Move 和遭遇选择。
- 新增信封、Context 与跨轮 Activity 测试后，实际项目全量回归为 `134 passed`；当前服务重启后健康状态为 `ready`。
- 2026-09-20 使用本机已配置的 Gemini `gemini-3.7-flash` 完成一次隔离真实试验，证据保存在 `work/cognitive-runtime-gemini-trial-2026-09-20.json`。模型第一轮没有生成学习计划，而是提出了“理想与数学是否为眼下要务之间的不确定感”这一开放 tension；该 tension 在下一次 Context Snapshot 中保留。第二次运行的 RuntimeUnavailableError 也被如实保存，没有伪造后续 Formation 或 Result。
- 试验同时暴露并修复了传输边界：模型返回的单个 JSON 代码围栏、`description` 别名和 `id/status` 元数据现在会被解析并保留原文；协议错误不再吞掉这类有效开放活动表达。

### Packet K-1 · 当前理解与历史版本分开

- 修复 Formation 状态丢失和旧版本重复激活；同 ID 按事件顺序更新，dormant/active 真正改变当前视图，历史和材料引用保留。
- 同 ID tension 的 resolved 更新不再被旧 open 版本抵消；失败 Encounter 继续作为可面对的材料。
- 实际项目全量回归 162 passed；新增脚本测试仅证明程序接续，不宣称模型认知能力。
- 找回旧隔离目录 GPT-5.6 试用文件，纠正前一轮“只有 Gemini 证据”的判断。
- 下一处实际缺口：运行适配器会附带会话历史，活动快照还不是完整模型输入；先处理输入范围，再做模型对照，不扩建实验平台。

### Packet K-2 · 明确 Activity 的模型输入范围

- Gemini/OpenAI-compatible 的 Activity 调用使用独立临时状态，既不附加普通聊天历史，也不改写原聊天状态。
- 在网络发送前记录请求正文、固定指令、尝试序号和哈希；重试和失败也留痕，认证信息不记录。快照详情及高级界面可查看。
- 调用前快照不再被调用后模型信息改写。兼容端点未返回模型身份时明确未知。
- Codex/其他仅支持 send 的运行时仍可能附带历史，profile 和界面明确披露，不算作完成隔离。
- 全量回归 168 passed, 2 subtests passed；前端语法、差异检查通过；本地 8765 服务 ready，新资源可读。未作本轮实模型效果验收，未声称完成思考系统。

### Packet K-3 · Codex 独立活动与真实接续

- Codex Activity 每次创建 ephemeral thread；普通聊天绑定不读、不写，SDK 调用前保存入参。界面说明额外运行上下文仍未完整捕获。
- 实测找到 SDK 自带旧 CLI 导致默认模型不兼容。依赖从 0.144.4 更新至配套 SDK/CLI 0.155.1，不通过换模型绕过。
- 更新后真实创作两轮成功：无 Formation → 实际创建续写材料 → 新会话从 Activity Context 读到结果 → 暂停。证据 `work/codex-activity-scope-trial-2026-09-20-v2.json`，旧失败文件保留。两次 thread 不同，原聊天绑定不变。
- 全量 173 passed, 2 subtests passed。实际响应模型仍未知，不宣称 GPT-5.6 high 验收，也不把一次创作接续称为已完成认知学习。
# Packet K4：原始发生回读与作用机制设计（2026-09-20）

- 新设计见 `Cognos-v3-理解与活动的作用机制.md`。明确模型、程序与人的贡献，使用现有可采用组合实现理解对活动的作用；保留未知遗漏发现与跨活动发展为未完成工作。
- 发现项目取材只返回最多 6,000 字节选，活动没有按原始发生 ID 继续读取后文的路径。
- 增加 `read_occurrence`：当前项目内按原始记录 ID、字符起点和长度回读；给出总长度、区间、next_start，结果成为不可变活动材料并返回下一调用。不需修改现有取材定义。
- 验证长中文/Unicode 原文分页重建、项目范围、非法区间、真实 API 接续、原始日志前缀不变和重复请求不重复追加。
- 全量测试：175 passed, 7 subtests passed；diff 检查通过。此轮未重启正在运行的服务，未进行实模型效果或浏览器验收。
- 这证明原材料重新进入的程序通路，不能证明模型自动识别了遗漏或提高了思考能力。

### Packet K-5 · 跨活动经验候选的显式发现（2026-09-22）

- 新增 `discover_experience_candidates`，只从同项目其他 Activity 中暴露存在实际使用痕迹的候选：当前被采用的构造/取材定义，或真实执行结果产生并仍存在于来源 Activity 的材料。没有实际使用痕迹的普通草稿不会仅因存在于历史里就进入候选目录。
- 候选目录只含来源 Activity、来源修订、文档与版本、候选类型、有限的实际使用/结果痕迹，以及 `unverified_candidate` 适用性标记；不自动注入来源正文，不把参与者说明升级为客观因果。
- Activity 新增显式 `browse_experience` 操作。只有模型/用户主动浏览时，候选目录才会作为一份新的不可变材料进入当前 Activity，并留下原始发生记录；随后如要真正使用，仍必须走已有 `import_activity`，且带入后仍不会自动 adopt。
- 新增只读 API `GET /api/activities/{id}/experience-candidates`，用于界面/人工检查候选，不改变当前 Activity。Activity 工作台增加“看看过去活动里实际用过什么”入口：这里只列候选并显示来源/痕迹，仍需人工明确带入；普通“从另一活动带入”路径继续保留，可浏览全部来源材料。
- 回归覆盖“未使用草稿不进入候选”“采用构造与真实结果进入候选”“显式浏览后目录进入当前 Activity”“浏览不会自动导入来源文档或采用构造”。后端定向回归 `42 passed, 5 subtests passed`；接入界面后前端语法检查通过，Activity/项目相关回归 `50 passed, 5 subtests passed`；本批后端全量回归 `191 passed, 7 subtests passed`。
- 这一步让过去第一次能够作为后来活动的**可发现可能性**进入，而仍保留重新判断的空间。它还不是长期 Memory、策略学习或自动推荐：系统尚不会判断哪个旧经验对当前处境真正相关，也不会根据一次成功自动提高全局优先级。

### Packet K-12 · Canonical ActivityView 收敛与认知消融入口（2026-09-23）

- 修复 `parse_activity_reply()` 的 Tension 修订语义：有明确 id 的 status-only / text-only 更新不再提前补入空 text、空 refs 或默认 open。端到端回归覆盖 parser → run event → ActivityView，确认 resolved 后再改文字不会被意外重新打开，旧正文/引用也不会被擦掉。
- 模型 prompt 不再同时接收 top-level Formation/Tension、完整 `activity_view` 和 `cognitive_conditions` 中的同义副本。完整 ActivityView 继续保留在 Snapshot/API 供复核；模型只收到一份当前投影，`cognitive_conditions` 只保留真正派生的未吸收候选、reopening channel、recent move 与 fingerprint。
- Activity UI 已直接消费 canonical `activity_view`；`/formations` 同时返回 ActivityView，旧 `understanding` 暂时只作为兼容 adapter。页面不再自行读取 `formation_projection` 判断真假状态。
- 新开放信封中的 Formation 不再继续写回 `state.situation` 兼容镜像；legacy `situation` 仍维持旧行为。历史镜像数据继续可读，但不再让新事件制造第二份可能过时的当前状态缓存。
- 新增显式实验 prompt profile：`baseline / minimal / full`。三者共享同一 Activity、工具执行与可恢复信息池；full 使用当前认知结构，minimal 保留材料/结果/来源/采用与历史访问底座但移除 Formation/Tension/cognitive conditions，baseline 再移除 Cognos 的采用/Move 组织，改给中性的 current material view 与同一 catalog/affordances。
- 新增隔离 runner `web/tests/run_cognos_ablation.py`。每次只运行一个 condition × scenario，避免把六组模型试验同时打成请求尖峰。第一批场景是：`hidden-history`（当前表面叙事遗漏较早关键条件）与 `transfer`（过去用过但错误/部分正确的构造，对照从未采用但当前正确的构造）；runner 机械记录 browse/read/import/evaluate 等行为指标和原始证据。
- 当前工程全量回归 `195 passed, 7 subtests passed`；runner `py_compile` 与 CLI 入口通过。当前环境没有配置 `COGNOS_TRIAL_API_KEY`，因此本批**没有**产生新的真实模型认知效果证据，也不把实验框架完成误写成 Cognos 已经提高推理。
- 施工过程同时暴露 Codex 并行 agent 的运行成本：先触发 agent thread limit，随后多条新 agent 请求出现 capacity / stream disconnected。Cognos 与 continuity 的 `AGENTS.md` 已加入低并发规则：主线程默认工作、通常最多 1 个 subagent、批量提问、失败后不快速补开。这个改动是施工稳定性措施，不属于 Cognos 认知能力证据。

### Packet K-13 · 第二次进入的可见接续点（2026-09-24）

- 没有新增 Memory、Summary 或另一份持久状态。Activity 页面直接从现有 canonical `ActivityView`、Move、Result 与 focus 派生“上次停在这里”，把等待用户回答、未解决 tension、正在形成的 Formation、最近实际结果与当前关注点作为继续线索。
- 当用户只通过 `/activities?project=<id>` 返回项目、没有显式指定 Activity 时，后端原有“按最近实际修订排序”的规则继续选择最新 Activity；首页入口也会显示 `继续活动 · <最近活动名>` 并携带该 Activity id，减少重新定位和重新交代背景的摩擦。
- 新增回归确认“最近”按真实最近修订/更新时间决定，而不是最初创建顺序；最终相关前端语法检查通过，`git diff --check` 通过，当前工程全量回归为 `196 passed, 7 subtests passed in 96.32s`。
- 使用隔离 `activity_ui_fixture.py` 与本机 Chrome headless 做第二次进入实测：创建活动“回来继续的真实活动”，留下 focus“继续昨天还没想完的东西”和 Formation“可能是停顿”，结束为 paused；随后只用 project URL 重进，页面自动选中该 Activity，并实际渲染“上次停在这里 / 可以直接继续，不必重新交代背景 / 正在形成：可能是停顿 / 此刻关注：继续昨天还没想完的东西”。截图保存于 `work/return-point-smoke-2026-09-24.png`。
- 这批证据证明的是**恢复与可见性**：已有连续状态能够在第二次进入时重新落到人的工作界面。它还不能证明用户会因此回来第二次，也不能证明 Cognos 在第二次进入后做出了更好的判断；下一步必须让第二次进入引入改变旧条件的新事实，观察旧 Formation/Tension 是否被正确使用、修订、暂缓或拒绝，并观察下一 Move 是否随之改变。

### Packet K-14 · 第二次进入后旧认识真的发生修订（2026-09-24）

- 新增确定性的两次进入回归：第一次留下 Formation/Tension，随后重建 `ActivityService` 模拟新的服务会话；第二次只给出改变条件的新事实。测试要求旧 Formation/Tension 与新事实必须同时进入下一 Context，随后同一 Formation 被修订/暂缓、Tension 被解决，并使下一 Move 换向；原始第一轮事件保持不变。该回归把“能恢复”推进到“恢复内容实际参与后续行为”。
- `web/tests/run_cognos_ablation.py` 新增 `return-change` 场景，可分别运行 `baseline / minimal / full`，机械记录旧 Formation/Tension 是否被修订、是否被解决、Move 是否引用旧状态以及 Move 文本。runner 仍一次只跑一个 condition × scenario，避免并发请求掩盖证据。
- 当前没有 `COGNOS_TRIAL_API_KEY`。runner 尝试复用本机已配置的 OpenAI-compatible `deepseek-v4-flash`，但端点返回 HTTP 403；该次记录只证明 provider/access 失败，不计作模型行为结果，也不把全 0 指标解释成模型拒绝修订。
- 随后用项目自带 `phase0/.venv` 与 `CodexRuntimeAdapter` 做隔离真实两次进入。第一次材料只有“昨晚窗还亮着。”；真实 Codex 留下一个 active Formation（亮着可能意味着某种状态尚未结束，但不能确定背后有人/事件）与一个 open Tension（亮灯只是物理状态还是指向人/未结束事件尚未安顿），随后暂停，没有 Move 或伪造结果。
- 真实调用同时暴露了传输层脆弱性：Codex 两次把 JSON 键/数组写成 Markdown 风格 `\_`、`\[`、`\]`，第一次还出现 tension `active` 与 `pause.text`。原先这些会让整轮变成 `protocol_error`，即使认知内容本身可用。`parse_activity_reply()` 现在只对这些已经真实出现的表面偏差做窄修复：Markdown 转义还原、tension `active → open`、`pause.text → pause.reason`，并把每项修复写入 `transport_repairs`；原始回复仍保留，不做静默语义改写。对应 parser/API 回归已补齐。
- 修复后重新从干净隔离状态运行：第一次真实调用 `paused`；第二次通过同一 Activity 输入新事实“这是无人值守的定时灯，当晚屋内没有人”。第二轮 Context 明确包含第一次 Formation/Tension 的稳定事件 ID。真实 Codex 用原 Formation ID 将其修订为“亮灯首先表示定时机制仍在运行，不能再支持屋内有人/某件事未结束”，用原 Tension ID 将其设为 `resolved`，并提出新的无操作 Move：“检查定时灯实际设定时段是否覆盖观察时刻”，Move 同时引用旧 Formation/Tension。用户无需重新解释第一次背景。
- 这是一条真实、方向性的行为证据：**旧认知状态经过第二次进入重新成为下一次判断的输入，新现实使同一状态发生修订，并改变下一步。**它仍不是因果消融证明：当前只完成 full 协议下一例真实 Codex 运行；`CodexRuntimeAdapter` 没有报告实际模型身份，且 SDK 只能完整记录调用参数，不能声称捕获了运行时内部全部上下文。是否由 Formation/Tension 结构本身带来增益，仍需 baseline/minimal/full 同可达世界下重复比较。
- `run_activity_live.py` 现在把每轮 `formation_updates / tension_updates / move / pause / transport_repairs` 与最终 `ActivityView` 直接写入试用报告，后续不需要手工翻 SQLite 才能判断连续性行为。最终 `py_compile` 通过，当前工程全量回归 `199 passed, 7 subtests passed in 111.18s`，`git diff --check` 继续作为收尾门。

### Pi 最小异构 runtime 探针（2026-10-01）

- 可选 `pi` participant 默认关闭；RPC 固定禁用工具/扩展，普通 session 仅保存隔离绑定，Activity 使用 `--no-session`。未迁移历史或改写已有 occurrence/参与者 session。
- 离线 Pi 测试 `21 passed, 11 subtests passed`；runtime/profile `52 passed, 13 subtests passed`；全量回归 `220 passed, 18 subtests passed`，`py_compile` 与 diff 检查通过。配置与验收命令见 `phase0/README.md`、`web/README.md`。
- 当前 PATH 未找到 Pi，未作真实 CLI/provider 往返验收。本次仅有工程及引用追踪证据，复核后无新的行动层认识需要修订，行动思想保持原样。

### 组织产品化 O1 · 登录写边界、多人作者与项目成员入口（2026-10-06）

- 从已有未提交的账号/界面改动继续增量施工；既有 runtime、错误脱敏和消融工作树改动保留，没有 reset/checkout/clean 或无关重写。身份后端由用户指定的 GPT-6.1 Sol high Codex 子任务执行，主线程完成前端、E4 CLI、部署说明和整体验收。
- 修正账号依赖与集成：通过 FastAPI Request 获取当前 app 的 AuthStore，按 cookie 查询公开账号；密码为 PBKDF2-SHA256 哈希和盐，数据库只保存 token 哈希。session 仅通过 `cognos_session` HttpOnly/SameSite=Lax cookie 传递，支持 14 天过期、注销、轮换和 app 重建；`COGNOS_AUTH_COOKIE_SECURE=1` 仅提供 Secure 标志，不提供 HTTPS。认证输入验证错误也不回显密码/输入值。
- 明确开放协作试用边界：所有真实业务 POST/PATCH，包括 Activity 与 handoff 创建/结果回流，均要求登录；health/bootstrap、页面和业务 GET 仍公开，账号目录 GET 需登录。已登录账号可协作现有项目；邀请凭证、角色权限、项目隐私、注册限流和密码找回尚未实现，不把登录检查说成权限隔离。
- 普通/项目/频道消息、探究提问与尝试、Activity 发言及操作，从 session 注入 `display_name` 与稳定 `author_account_id`，请求体 author 不决定作者。AI 回复仍归 AI；外部执行结果保留代理作者，同时记录登录提交者。幂等请求检查包含操作者/活动范围，避免多人相同 request_id 取到他人发生。
- 账号通过可空 account_id 关联普通组织成员投影。创建组织/项目、项目发言和活动操作登记协作者；也可显式添加已注册账号。没有按同名认领或迁移历史：账号成员只读取匹配账号 ID 的新 raw；旧无账号成员仍按历史作者名读取，旧 raw 不回写。
- 项目中央新增“成员”页签：显示成员卡片，添加已有组织成员或已注册同学，直接打开原 `/api/members/{id}` 主页；返回会恢复成员页签。修复登录函数被嵌入成员渲染导致启动未定义、hidden 弹层失效、带空格作者导致 DOMTokenList 错误，以及项目标签占据内容伸缩行的布局问题。Activity 页可原地登录/注销并共享 cookie。
- E4 `handoff.result_poster` 适配强制认证：CLI 使用 `--username`，密码从终端隐式读取；同一个 HTTP client 先登录、带 cookie 回流、随后注销，认证材料不进入结果 payload。匿名结果回流不再被豁免。
- 部署事实：默认监听保持 `127.0.0.1`。`start.ps1` 转发 run.py 参数；从 web 目录可执行 `.\start.ps1 --host 0.0.0.0 --port 8765` 或 `..\phase0\.venv\Scripts\python.exe run.py --host 0.0.0.0 --port 8765`，同学访问 `http://<本机局域网IPv4>:8765`。参数转发与 PowerShell 解析已验证；隔离 loopback 服务 health 返回 200。正式本机 8765 检查为 ConnectError/未监听，本批没有启动正式服务、修改防火墙、暴露公网或提供 HTTPS，也没有另一台电脑的连接证据。完整说明在 `web/README.md`。
- 工程证据：后端定向 `97 passed, 5 subtests passed`；补齐旧处境/Pi 测试登录并保留其原验证后，完整 `web/tests + phase0/tests` 为 **230 passed, 18 subtests passed in 63.99s**；收尾认证/E4 定向为 `13 passed`。Python compile、三份前端 JS `node --check`、PowerShell parse、`git diff --check` 收尾通过。
- 界面/可追踪证据：隔离本机 Chrome 检查真实登录、HttpOnly cookie 对 JS 不可读、中央成员入口、右栏隐藏下打开主页/返回、添加第二账号、带空格显示名发言，以及 Activity 匿名写入触发登录后成功创建。没有 pageErrors。脚本替身不调用模型；报告和截图在 `work/productization-ui-report.json`、`work/productization-members-mobile.png`、`work/productization-activity-login.png`，可复跑 `node work/productization_ui_smoke.js`。
- 数据保护证据：施工前对可读取的 raw/state 文件记录 SHA256，收尾比较 **214 个既有文件，0 个变化**（含真实 raw；旧 ui-check 下一个目录无读取权限，不宣称覆盖该目录）。所有测试/浏览器数据与默认 app 导入路径隔离；测试还验证旧 raw 字节前缀不变、两个独立用户及同名账号归属、密码/hash/salt/token 不进入响应。
- 行动思想复核：本批补齐的是工程身份边界与人的项目入口，适用既有第 8 条“证据分开”和第 13 条“重新进入人的行动”。尚无真实同学试用或认知效果证据，不据测试通过制造新的行动层结论，`Cognos-v3-行动思想.md` 保持原样。下一步可用真实同学注册→共同项目发言→打开成员主页→第二次返回来验证使用效果；正式启动与跨设备连接仍需实际验收。

### 产品旅程与开源底座 P1 · 2026-10-07

- 从 Pi 原会话恢复来路后，按用户要求建立 PRODUCT.md、MODEL.md、JOURNEYS.md（16 条核心旅程）、ACCEPTANCE.md；全部状态依据当前代码，未沿用“功能存在=完成”。
- 当前任务链检查发现：project_api 的 PATCH 把缺省字段转为 null，可能清掉 assignee/decision；任务负责人无项目校验，事件独立提交，前端无个人任务入口。本批仅登记，未在用户改选开源底座后继续默认自写基础模块。
- 用户明确选择“自部署开源底座，在上面扩展 Cognos”。核查 Nextcloud/Huly 社区版/Zulip/Plane/Twake 官方资料。原 Huly 仓库已声明冻结、托管关闭，后续开发在 Platform Collective；Twake 旧仓库为 legacy，不能直接按旧官网评价维护状态。详见 开源底座选型-2026-10-07.md。
- 首选 Nextcloud 隔离试用，Huly 社区版作对照；这是试用顺序而非未经旅程验收的最终替换决定。新增 deploy/nextcloud-trial 独立 compose、准备/启动/bootstrap 及统一单行入口。只监听 127.0.0.1:8780，独立 volumes，不挂载旧 Vault/runtime/业务数据库。
- 独立 read-only agent 审查后修正三个部署问题：安装失败后原地重试、cron 与 Web 的 Redis 配置一致、读取 installed/maintenance/needsDbUpgrade 有界等待初始化。PowerShell 源采用 UTF-8 BOM，Windows PowerShell 5.1 实际 PrepareOnly 成功。
- Registry 实际查询三镜像 manifest HTTP 200，已固定 digest。Compose 配置解析及 PowerShell/Python AST 通过。check_tasks.py 只是真实 Deck API 验收脚本，尚未运行；报告明确不计作浏览器/状态生命周期/历史/完整 J07 通过。
- 真实部署边界：用户手动启动 Docker 后，引擎管道存在但当前沙箱连接 permission denied；权限工具不能表示 named pipe 路径。用户首次命令丢引号导致 CommandNotFound，统一入口已给出，等待普通 PowerShell 的启动结果；不得标记容器或旅程完成。
- 本批新增/修订文档和隔离部署源码；没有修改原 raw/session/当前业务状态，也未运行模型或迁移历史。验收要求与实施路线已成为权威文件，而非只留在聊天计划中。
- 行动思想复核：用户把完成判据明确为完整旅程，适用既有第 8 条证据分开及第 13 条重新进入人的行动。本批不据静态配置或开源官网产生认知效果主张，不制造新的哲学结论。下一步必须检查本机服务真实可达、应用版本和双成员活动，再修订路线。

### 开源底座 P1 后续 · 镜像代理故障与 J07 验收准备（2026-10-07）

- 用户在主机 PowerShell 正确运行统一入口。引擎返回 29.2.1，镜像拉取遭遇 Registry HTTP CONNECT unexpected EOF，尚无部署成功证据。本轮检查 127.0.0.1:8780 未开放；没有把用户运行命令误写成部署完成。
- 此前 HTTP 客户端通过本机 Clash 7897 可访问 Registry，无认证 401 是可达证据，不是 Docker daemon 拉取证据。Docker 官方文档确认镜像拉取受 Containers proxy 设置约束；README 增加针对这次故障的 Desktop 手动 HTTP/HTTPS 与 Same as host 设置方法。沙箱无法控制主机 Docker，修复后的真实结果仍待取得。
- check_tasks.py 增加待处理→进行中→已完成三列流转、每次移动检查负责人保留、项目成员指派非成员必须拒绝且负责人不变。三列不是个人任务列表，也不是原生 done 标记或时间线证明；未把这些范围混为完整 J07。
- 试用凭据保存改为临时文件后替换；服务就绪前不保存账号记录；已有记录时拒绝再次创建，避免重跑覆盖旧账号凭据。已有试用使用 --verify；失败后的对象保留，不隐式删除。
- 纠正 ACCEPTANCE J07 公开读取豁免：新底座私有项目需要成员读取边界。旧公开读取继续记录为缺口。
- 验证：Python AST 通过；git diff --check 通过。实际运行 check_tasks.py 返回 ConnectError，evidence/task-create.json 明确 api_result failed、其他旅程门槛 unverified；随后 Test-Path 确认 task-journey.json 未创建。这只验证服务不可达时未产生试用账号记录，不证明运行中的 Deck 旅程成功。
- 未改业务服务或原始 raw/session，无模型认知效果试验；此轮无需把旧服务的全量测试当作 Deck 验收。行动思想复核仍适用证据分开与产品连续性原则，没有新认知结论。下一步必须取得实际镜像下载和应用启动证据，再运行真实旅程。

### 开源底座 P2 · 真实服务与任务 API 初验（2026-10-07）

- 用户主机报告四个独立容器运行，DB/Redis 健康。实际 status.php HTTP 200：35.0.1 installed=true，无维护/升级等待。代理故障已被实际服务证据越过，不再把旧不可达结果当当前状态。
- 只读管理员 OCS 查询确认 deck/spreed/collectives/groupfolders 全部启用；Deck 1.19.0，API 1.0/1.1。无旧 Cognos 数据迁移。
- check_tasks.py 真实创建三个隔离账号和一个看板，八项检查通过；负责人跨描述/列移动保留，A/B 新客户端一致，非法指派无负责人变更，非成员板读取/任务写入拒绝。task-create.json 与随后新 Python 进程 --verify 的 task-verify.json 是局部 API 证据，无密码。
- 未重启容器；新客户端/新脚本不等于服务重启。个人任务列表、历史、邀请、完整浏览器/移动错误恢复、跨四应用权限尚缺，J07 不关闭。
- 实际 Chrome headless 调试端点可发现，但 Page.enable 超时；原生和既有 loopback WebSocket 两次尝试均未取得 UI 证据。工具链故障不算用户界面产品结论，报告保留 failed；后续继续处理控制连接。
- ACCEPTANCE 已更新，脱敏 API 证据复制到本会话 outputs。未修改原始 occurrence/session/runtime，未进行模型效果试验。本批推进的是工程与可观测证据，没有新行动思想结论。

### 开源底座 P3 · 浏览器任务详情与完成/历史（2026-10-07）

- Chrome 的 Browser.getVersion 可响应但 renderer Runtime.evaluate 超时；与项目既有成功浏览器脚本对照，采用其 headless 渲染器启动参数后 1+1、登录与 Deck 页面可运行。只访问隔离本机试用环境。
- 初次截图显示介绍遮罩。自动检查若只找 DOM 标题会错误声称用户可见；实际点击跳过/关闭后重新截图并检查任务板。此处是验收方法修正，没有把遮罩判作后端故障。
- B 的真实浏览器进入任务详情，实际点击标记为已完成和活动标签。界面展示创建/指派/修改/两次移列/完成的作者和时间。A/B 新客户端确认 done 有时间值、负责人和列保留，证据保存在当前会话 outputs，无密码。
- 移至已完成列并不改变原生 done，个人 upcoming 仍包含该任务。这个事实改变状态适配假设：列位置与完成标记必须分清并对齐，不能因列名是已完成就通过完成验收。
- upcoming 首屏未显示位于后部无到期分组的任务；正文存在与可见/可发现仍是不同证据。J07 未关闭，待个人列表过滤、全程 UI 写入、手机操作、错误恢复和服务重启。
- ACCEPTANCE 已按证据修订。未更改原始日志/session、未引入模型调用；行动思想仍适用工程/效果证据分开和回到人的行动，无新哲学结论。

### 开源底座 P4 · 真实界面创建到移动完成的可重复验收（2026-10-07）

- 使用隔离 Chrome，A 通过真实表单/提交控件创建卡片 22，再从成员选择器选 B。B 登录 upcoming 后看到任务，滚动使其位于屏内，在 390px 详情中完成，并看到创建/指派/完成历史。返回 upcoming 后不再包含完成卡片；重新导航的历史保留。
- 将成功链路固化为 check_tasks_browser.cjs 与 browser-shell.cjs，读取已有隔离凭据和看板 ID。工作区版本创建卡片 23 通过；纳入项目的最终版本创建卡片 24，六项检查再次通过。报告仍 full_journey/service_restart/error_recovery unverified，不冒充所有 J07 门槛。
- 截图人工视觉复核：手机历史作者和行为可见。初次创建直接 requestSubmit 未取得卡片；按真实提交控件并等待响应后成功，没有把验收时序偏差写成产品 bug。
- README/ACCEPTANCE 更新运行方式和剩余门槛，脱敏报告和截图复制 outputs。没有改旧业务服务或原始历史，没有模型效果主张。行动思想适用证据分开及回到人的行动，无需制造新哲学条目。

### 开源底座 P5 · 断网草稿恢复与首个 Cognos 应用适配（2026-10-07）

- 真实 Chrome 完全断网时，Deck 创建 POST 被浏览器报 ERR_INTERNET_DISCONNECTED；草稿保留，另一个 HTTP 客户端确认零创建，恢复后原输入重试恰好创建一张任务并清空。页面无可见失败提示，六项中可见错误 false，整体 failed；截图复核与检测一致。
- 新增 cognos-app：公开 BeforeTemplateRenderedEvent 注册加载，仅适配当前 Deck 同源创建端点/XHR 和中文表单。请求期间可见 pending/禁用提交，失败显示结果未确认、保留输入、先检查列表再重试；成功清除状态。没有改 Deck 下载源码、原始日志、账号存储或建立第二套任务数据。
- 原生失败证据及源码注入通过证据分别保存。注入真实页面后六项 passed；注入不是正式 Nextcloud 加载，不能宣称扩展已部署。
- Compose 新增只读 app/cron 挂载，bootstrap 加入两 PHP 文件 lint 后启用本地 Cognos app。Compose PrepareOnly、JS 语法、PS AST、XML 解析通过；PHP/Nextcloud 框架实际加载未完成。再次实际 docker info 仍管道拒绝，最后部署需主机执行统一入口，卷/密钥保留。
- 首轮错误文本检测脚本有字符串转义错误，作为验收脚本失败修正；修正后原生与注入对照均留下真实结果，没有把工具错误归为产品缺陷。
- 完全断网不等于响应丢失后事务未知；当前无跨请求幂等保证，未把 retry 一次成功推广成任意故障零重复。J07 继续未完成；行动思想仍适用证据分开与人的反馈，没有新认知效果主张。
- P5 截图收口：反馈最初挤在输入同行，已改为独占下一行并在成功后恢复原布局；按最终源码再跑真实断网六项 passed，仍为源码注入证据。

### 开源底座 P6 · 正式扩展部署、提交反馈及重启/权限（2026-10-07）

- 当前自动审批允许访问主机 Docker。实际读取引擎 29.2.1，运行既已授权统一入口，重建独立 app/cron，保留 DB/Redis/卷。PHP 两文件 lint 通过，cognos 0.1.0 enabled；不再让用户承担本会话已经能执行的命令。
- 正式加载后断网六项通过（无注入）；全程浏览器六项通过，卡片 31。提交延迟 1500ms 下 pending 可见、提交禁用，三次原生点击只发一 POST/创建一张卡片，成功恢复控件；不是跨设备或未知响应幂等证明。
- 实际 restart 四服务，inspect 状态与启动时间单独记录。随后 API --verify 通过，新 Chrome 读重启前卡片 31 的创建/指派/完成历史及作者，扩展自动加载；没用重启后新建任务冒充旧历史恢复。
- 新独立权限板试验：匿名401；同显示名非成员不能发现/读/写；编辑成员不能分享或授予管理；撤销成员后此前指派不绕过访问边界，所有者任务仍在；临时显示名恢复。这里只证明 Deck，未把它升级为跨 Talk/Files/Collectives 的项目退出保证。
- ACCEPTANCE 与 README 修订正式状态/命令/剩余门槛，脱敏证据复制当前会话 outputs。J07 仍有决定关联、取消指派和全状态 UI、事件事务、未知结果以及独立验收缺口。没有原始日志/session 修改或模型认知效果主张，行动思想无需新增条目。


### 开源底座 P7 · 创建事务与历史故障（2026-10-07）

- 原生 Deck API 实测：创建同时指派项目外成员，HTTP 400，但新客户端仍读到一张未指派任务；原子性失败，不把先前单独指派拒绝当成多步创建也安全。
- 新增 Cognos OCS POST /apps/cognos/api/v1.0/cards。真实 Deck 页面创建改用该入口，仍调用 Deck 1.19 的权限/任务/指派服务及原有 PostgreSQL 存储。创建、标签、负责人关联同一事务，提交前确认稳定作者的原生创建历史存在。直接访问原生 Deck API 未被改写，其他状态变更没有因此获得原子性。
- 修复后同一失败路径：先指派有效 B 再指派非成员，HTTP 400，零任务、零创建/指派历史残留。有效创建与 B 指派成功，B 新客户端读到任务；数据库有创建/指派历史。非成员从新入口创建仍拒绝。
- 独立库临时故障触发器只拒绝一个随机测试标题的活动 INSERT；请求 HTTP 500，刷新零任务、零活动残留。finally 删除触发器并确认不存在，没有改旧业务库或旧发生。
- 网页初验出现 503，不能以 API 通过声称 UI 可用。定位到预校验使用 StackService.find 将全列卡片写入 StackMapper 的内存对象缓存，活动随后序列化同一列而超过参数长度，被 Deck 忽略。改用原生 PermissionService + StackMapper 不加载整列卡片；实际页面六项重验通过（卡片 48）。后续验收脚本还要求网络中 Cognos 创建 POST 恰好一次且 HTTP 200。
- 新入口下断网草稿恢复六项、延迟/重复点击七项通过；只证明已测试的断网与普通重复点击，不证明未知响应结果或跨设备幂等。
- 新源码经真实 PHP lint，浏览器脚本语法、Python AST、git diff --check 通过。仅修改独立 Nextcloud 应用及验收脚本，不把旧 FastAPI 测试作为新 PHP 事务的证据。
- J07 仍未完成：全生命周期写入/历史一致性、决定关联、未知结果幂等及独立整体验收待完成。原生活动保留/清理语义尚不能直接冒充永久 Event。MODEL 已将旧 SQLite 与试用 PostgreSQL 分清，没有迁移或重写旧历史。

- 行动思想复核：这是任务工程一致性与证据边界修正，没有新的模型认知效果证据，不制造哲学结论；行动思想保持原样。

- P7 最终源码重载后：复用脚本验证创建并指派 B、加标签成功，B 新客户端读取一致；数据库实际创建/指派/标签各两行（A/B 各自活动副本），被拒绝任务零活动残留。最终真实浏览器卡片 62，网络明确 POST Cognos 创建入口一次、HTTP 200，六项通过；原有双成员 API --verify 也通过。
- 提交事务阶段若抛异常，服务返回“结果未知，先检查任务列表”，不把 COMMIT 异常武断解释为零写入。尚未注入响应丢失/提交连接丢失，幂等和未知结果恢复仍未完成。


### 开源底座 P8 · 创建结果恢复与幂等（2026-10-07）

- Cognos 0.2.0 已实际升级，新增独立 PostgreSQL 的 cognos_task_requests。键为稳定登录 UID + requestId，保存输入摘要、既有 cardId 与时间，任务/关联/历史/请求映射同事务提交，不建立第二份任务或复制 raw。请求 ID 必填；同 ID 改变已接受内容返回 409；已回滚失败不占用 ID。
- PostgreSQL ON CONFLICT 做事务内预留，冲突请求等待原事务结束后再读取。check_task_idempotency.py 八项真实 HTTP 通过：六并发客户端得到一个 ID（五次 replay）、服务器一张任务、不同输入 409、重放不覆盖之后编辑、回滚后可修正重试、缺 ID 拒绝、同 ID 按不同账号独立、撤销成员后不能重放读取。
- 页面在提交前保存账号/输入的 SHA256 摘要及随机操作 ID到 sessionStorage，未保存标题、正文、凭据。响应未确认时保留 ID与输入；同一页签重新导航后可用原 ID核对，收到有效成功 JSON 后清除恢复键。记录范围是同一次带 ID 的创建，关闭页签/丢弃恢复键后不能声称自动恢复相同意图。
- 真实 Chrome Fetch 在服务端 HTTP 200 后截断响应交付，Network 报 net::ERR_FAILED，服务器实际一张任务。错误与草稿可见；重新导航后重新输入同一操作并重试，线上两 POST 使用同 ID，第二次 HTTP 200 / X-Cognos-Replayed true，数据库/页面仍一张，恢复键清除。九项通过。此处不声称草稿本身经过刷新保存：刷新后的输入由测试重新填写，以验证操作 ID恢复。
- 数据库只读核对：响应丢失卡片 67、六并发卡片 63，各两行创建活动（A/B 副本），每个接收者只有一条创建记录。未用活动行数为二误判两次操作。临时随机标题的历史写故障四项通过：任务、活动、恢复预留全回滚，故障钩子清除。
- Cognos 0.2.0 全程浏览器六项通过（卡片 69），断网六项、延迟重复点击七项通过。实际 restart 独立四服务后，保存的请求在新进程仍返回卡片 63及 replay 标志；既有 API --verify、新浏览器读卡片 69历史通过。重启不是新客户端的别称。
- 升级暴露 bootstrap 两个真实问题：PowerShell Stop 把 occ 的待升级 stderr 提示当失败；就绪等待要求 needsDbUpgrade=false 导致未执行升级。已允许“已安装且不维护”进入 PHP 校验/upgrade，再严格核对升级后 installed/maintenance/needsDbUpgrade；保留退出码失败门。真实升级与原地重跑通过，源码仍 UTF-8 BOM。
- 验证：真实 PHP lint 五文件、JS/PS/Python/XML 检查与 diff 检查；旧业务完整回归 web/tests + phase0/tests + phase1/tests + test_phase1.py 为 233 passed、18 subtests passed（74.16s），默认 Vault/state 定向本会话独立工作目录。旧回归不替代新 PHP/浏览器证据，也不证明认知效果。
- J07 继续未完成：状态更新/取消指派与所有事件的原子性、稀疏字段保留、决定关联、原生事件永久保存/结构化前后值、完整独立验收仍缺。原生 Deck API 不因 Cognos 创建协议自动具有幂等；COMMIT 连接中断、进程崩溃等故障没有本轮直接注入证据，不把 HTTP 响应丢失推广成全部故障已验证。
- 行动思想复核：本次是普通软件事务与人的重试连续性证据，适用既有证据区分和回到人的行动；没有模型认知效果试验，不制造哲学结论，行动思想保持原样。


### 开源底座 P9 · 完成、重开与独立业务记录（2026-10-07）

- Cognos 0.3.0 已实际升级。独立试装新增 cognos_task_events，保存稳定操作 UID/当时显示名、任务/看板 ID、时间、操作种类及结构化前后值；创建、完成、重开与任务/恢复映射一起事务提交。业务表不是 raw occurrence，不导入旧历史。应用仅追加、没有编辑/删除 API；尚无数据库管理员不可删除的保证。原生 Deck 的其他写入口和旧记录未获得本协议保证。
- LifecycleController 调用原生 Deck 权限/任务服务，以看板和任务行锁串行化自身状态操作。重复完成无新事件，同 ID 重放返回当前任务、不重新施加旧意图。完成遵循已配置完成列；重开从已记录完成前列恢复，旧任务无已知原列时要求用户明确选择，不凭空猜测。
- 实际 API 八项通过（卡片 80）：A 创建记录、B 完成、说明/标题/负责人保留、完成列一致、前后值、no-op/replay 无重复、重开返回原列、旧记录不改、旧完成重试不再完成、另成员一致及撤销后历史/重放拒绝。先前路由使用 action 占用了框架参数，真实 400 后改为 operation 并重验，不把 API 初验失败藏在最终通过里。
- 双成员原生浏览器六项通过（卡片 78）；专项页面发现历史 fetch 缺 requesttoken，返回 HTTP 412。补请求令牌后独立状态记录在 390px 可见，刷新后创建/完成两条记录正确。
- 配置完成列的原生 Deck 页面没有可用重开按钮，已新增明确重开入口。以服务器当前完成状态控制可用动作，不能用旧业务事件快照冒充当前状态。成功后显示确认及“查看更新后的任务”链接，不自动刷新丢掉未提交说明；当前仍通过明确导航刷新原生编辑表单，尚未完成原生编辑器就地状态同步。
- 临时 PostgreSQL 故障钩子仅拒绝随机测试卡片 completed 业务记录 INSERT；六项实测通过，最终卡片 81：HTTP 500、任务完成标记/列/说明回滚、自身事件不变、原生活动回滚、恢复预留回滚；移除故障后同操作成功且仅一条完成事件。finally 清理并确认钩子不存在。
- 实际 restart 独立 app/cron/db/redis，记录服务启动时间；新 Chrome 读取重启前卡片 80 的四条事件，再通过 390px 明确重开入口成功、原列/说明保留、一次 PUT 200、明确导航后五条记录可见。没有把新客户端当成服务重启。
- Chrome 在重开 HTTP 200 响应阶段截断交付：服务器已重开而页面收到失败；错误可见、按钮恢复；再次点击同 requestId，200/replayed，事件完全相同且无第二条重开记录。三项通过。没有据此声称 COMMIT 连接中断/进程崩溃均已验证。
- 最终 JS 语法、真实 LifecycleController PHP lint、diff 检查通过；旧完整回归 233 passed、18 subtests passed（72.33s），Vault/state 定向独立 work/p9 路径。旧回归不替代 PHP/实际浏览器验收。
- J07 继续未完成：进行中/阻塞等完整状态、稀疏更新/取消指派原子性、决定关联、原生编辑器就地同步、事件分页/加载失败边界、旧任务选列浏览器验收和独立整体验收待收口。其他十五条旅程的原范围保留。
- 行动思想复核：本次修正工程一致性和人的可见重试/重开入口，没有模型认知效果试验，既有行动思想适用；不制造新哲学结论。


### 开源底座 P10 · 指派与取消指派事务（2026-10-07）

- Cognos 0.4.0 已实际升级并启用；新增 AssignmentController OCS 入口，仅调用已安装 Deck 的指派/取消服务，不用整项更新任务来取消负责人。新增可空 response_json 保存原生指派确认结果，和任务/事件/恢复键同事务提交，原有创建/状态恢复记录无需回填。
- 同样使用看板/任务锁、稳定 UID + requestId、内容摘要冲突检查和当前编辑权限。已存在指派/已取消状态是 no-op，不增事件。重放检查当前是否仍满足原操作结果；之后被别人改变时返回 409 + confirmed 标志，不重新执行旧指派，也不向页面回放过期成功状态。当前响应缓存只用于本操作确认，不是另一份任务当前状态。
- 实际 API 八项通过：原生指派返回/重放一致、已有指派 no-op、任务已完成时指派保持 done/列/标题/说明/标签/期限/颜色、项目外人拒绝、B 取消自身指派、取消重放/无指派 no-op 无重复、旧指派请求在取消后不能重新指派、另一成员刷新一致/输入冲突/退出后重放拒绝。类型使用 Deck 原生用户/组/团队常量；本轮实际数据仅验证用户，不声称组/团队 UI 全面通过。
- 正式注册 task-assignment.js，实际原生 OCS assign/unassign 请求改用 Cognos 事务入口，保留原生返回结构。请求待处理、失败及同操作重试提示可见，sessionStorage 仅保留输入摘要/随机 ID；不保存凭据或任务正文。重放已确认但当前状态改变时要求查看最新任务，不默默重新应用过时操作。
- 双成员浏览器六项通过（卡片 86）：Alice 可见表单创建/指派，Bob 个人待处理列表发现、390px 完成、原生活动与刷新保留。单独手机取消三项通过：原生负责人移除只发一次 Cognos PUT/200，说明/状态/列等字段不变，重新导航显示未指派和新的取消事件。
- 浏览器取消初验两次超时，网络实际取消已 200；探针在历史加载前读 DOM 条数，误把新增事件的期望数算小。改为取服务器事件数作基准，允许复用隔离测试卡片准备明确指派，再实际重验通过；不是靠改断言隐藏取消失败。
- 数据库故障六项通过（测试卡片 85）：只拒绝这个新测试任务的 unassigned 事件 INSERT，负责人仍在、任务字段/自身记录/原生活动/恢复预留共同回滚；清理钩子后同 ID 重试成功且只有一个取消事件，finally 确认故障钩子不存在。
- 升级 PHP 十文件 lint 通过，最终控制器 lint、JS 语法及局部 diff 检查通过。旧业务完整回归 233 passed、18 subtests passed（72.16s），Vault/state 使用 work/p10 独立目录；旧测试不替代新 PHP 的实际 HTTP/数据库/浏览器证据。
- J07 继续未完成：整项/稀疏编辑仍有省略 done 会清完成标记的原生风险，全状态、决定关联、就地编辑器同步、指派响应丢失/失败页面恢复、事件分页边界及独立整体旅程验收仍待做。本轮只重载 app，没有重做四服务重启证明；既有 P9 重启证据保留原范围。
- 行动思想复核：工程层验证和人的取消/重试入口修复，没有新的模型认知效果证据；行动思想保持原样。主线程继续施工，未因并行可能更快而自动多开 agent。


### 开源底座 P11 · 稀疏编辑与输入恢复（2026-10-07）

- 运行中的原生 Deck OCS 整项编辑已实测复现：完成后编辑说明但省略 done，返回任务 done=null。新 Cognos 0.5.0 已实际升级，EditController 只接受 title/description/duedate/startdate/color 明确字段，不从省略字段推断清除，不通过普通编辑混入指派/状态/跨列动作。
- 编辑在看板/任务锁和原生权限检查下合并到当前 Card，完成标记始终以 OptionalNullableValue 保存当前 DateTime；负责人/标签/所在列/顺序/所有者均保留。编辑、自身 edited 前后记录和恢复键同事务。显式 null 可清除可选日期/颜色，空说明可显式提交；空标题/非法日期/未知字段拒绝。传入看板不匹配时零写入。
- 支持对提交字段的 expected 原值校验，避免同字段过时编辑覆盖另一成员；不同字段可独立合并。重复接受的 requestId 返回当前任务并标记 replay，不重新写旧正文。新增期望参数可选，直接调用者不传期望代表明确覆盖意图，浏览器适配始终使用加载时字段原值。
- 实际 API 七项通过：原生缺陷复现、说明单字段保留完成/非空负责人及标签/期限/颜色等、真实作者/前后值/no-op/replay、B 先编辑后的 A 旧说明冲突、旧请求不覆盖新说明且独立标题改动合并、非法字段/日期/重复 ID 改内容拒绝、显式清空可选值/旧事件不改/退出后编辑拒绝。
- task-edit.js 正式加载，从原生已加载 Card 响应保留表单基线；将原生整项保存转换成 changed/expected 字段，不把未操作但过时的属性作为新意图提交。只存字段摘要和 requestId，不存凭据或正文。普通改名单独 RenameController 返回原生 raw Card 形状，OCS 编辑仍返回 OCS 包装；HTTP 两项通过，改名保留其他字段/传错看板拒绝。初验发现用 OCSController 承载普通路由仍包裹返回值，已拆普通控制器并实际重验。
- 手机真实原生富文本编辑三项通过：390px 输入说明，已完成标记、非空负责人、标签及其他字段保留；网络明确 Cognos PUT 包含 changes/expected；重新导航内容和历史一致。正式扩展全双成员创建/指派/发现/完成/刷新浏览器六项通过（卡片 93）。
- 专项首次以最新旧事件 after_state 当“当前任务”作比较，成员退出触发 Deck 原生取消指派，旧快照仍有旧负责人。已改为在权限校验后独立返回 currentState，以当前快照验保留；不改旧事件来伪装现在。为非空负责人页面验证恢复隔离测试成员和明确指派，再实测通过。原生成员退出尚不写入自身业务事件，仍属跨旅程缺口，不声称历史覆盖所有 Deck 写入口。
- PostgreSQL 临时钩子只拒绝随机新任务的一次 edited INSERT；六项通过，最终测试卡片 92：说明/其他字段、自身历史、原生活动、恢复预留全部回滚；移除钩子后同 ID 成功且仅一条 edited，finally 验证钩子清除。
- Chrome 在编辑 HTTP 200 响应阶段截断交付，服务器有一条编辑事件，页面错误可见且富文本草稿仍在。原生自动保存与手动重试可能同时再发请求；全部同 ID，至少一次 200/replay，历史不再增、其他字段保留、恢复键清除，刷新内容一致，三项通过。修复手动确认提示因原生自动保存替换节点而消失；没有把合法自动重放硬限成必须恰好两请求。此处不证明 COMMIT 连接中断/进程崩溃均已验证。
- 实际 PHP/JS 与局部 diff 检查通过；旧完整回归 233 passed、18 subtests passed（70.98s），默认 Vault/state 定向 work/p11 独立目录。旧回归和 PHP/浏览器/数据库证据分开，未改旧 raw 或 runtime 状态。
- J07 继续未完成：open/in_progress/blocked/done 完整状态、决定关联、原生编辑器就地同步、指派恢复边界、日期/颜色/改名全部页面操作、冲突界面恢复与分页/空状态边界、成员退出业务记录及独立整体验收仍待收口。其他十五条核心旅程未删减；本轮只重载 app，不把旧重启证明扩大到新编辑协议。
- 行动思想复核：纠正的是工程语义和验收中“过去快照/当前状态”的证据类别；既有区分原则适用，没有新模型认知效果实验，不制造哲学结论，行动思想保持原样。


### 开源底座 P12 · 四状态及只读边界（2026-10-07）

- Cognos 0.6.0 已实际升级。独立 PostgreSQL 新增 cognos_task_progress，只保存 open/in_progress/blocked 的未完成阶段；done 仍以原生 Deck 完成标记为准，不保存第二个完成标记或第二份任务。没有旧阶段行时当前未完成任务默认 open，旧事件没有 status 的部分显示“未记录”，不回填、重写或推断旧历史。
- 新 state 操作沿用稳定 UID/requestId、看板/任务锁与原生编辑权限。状态、原生完成/重开及列位置、自身 status_changed 前后值、阶段行和恢复映射一起事务提交；无变化不增事件。可用 expectedStatus 拒绝过时新操作；重放优先核对已接受操作并返回当前状态，不重新应用旧意图。旧完成/重开协议摘要保持兼容。
- 独立当前状态控件在真实 Deck 详情页提供未开始/进行中/阻塞/完成，pending 禁用、失败恢复选择与提交、响应未确认保留同一次操作 ID；明确核对当前状态入口保留选择。成功更新自身状态和历史。原生编辑器和看板卡片就地同步、个人列表四状态筛选仍待做，不用详情控件通过关闭整条任务旅程。
- API 八项通过：B 开始/阻塞保留非空负责人、标签、日期和列；无变化/非法/过时期望处理；旧开始请求重放不覆盖新阻塞；完成标记和配置完成列一致；旧二态重开恢复此前阻塞与原列；旧事件不改、另成员当前状态一致、退出后重放拒绝。390px 实际浏览器四项通过，顺序更新四状态，每步真实 PUT 和事件作者/前后值正确，重新导航一致。
- 临时故障钩子仅拒绝随机新卡片的 status_changed INSERT；七项通过：HTTP 500，原任务字段/列、自身历史、原生活动、恢复预留和新增阶段行均回滚；移除钩子后同 ID 成功并仅一条新状态事件，finally 确認钩子清除。此证据直接覆盖 open→blocked 分支，新 state→done 分支的专门故障注入未做，既有 P9 完成回滚证据保留原范围。
- 真实 Chrome 在状态更新 HTTP 200 后截断响应，服务器已 blocked 而页面收到错误；选择保留、提交恢复。再次点击使用同 ID，200/replay，事件不重复、恢复键清除、重新导航一致，三项通过。仅证明响应交付丢失，不推广为提交连接中断或进程崩溃全部已验。
- 原生权限服务仅查 ACL、不单独拒绝归档，新增阶段写入不能绕过原生归档规则。状态/编辑/指派入口均明确拒绝已归档任务或看板，包括旧请求重放；恢复映射在当前权限及归档检查之后读取。当前 canEdit 独立于业务事件快照，供界面禁用控件。
- 只读与归档 API 四项通过：只读成员能读当前状态及历史，写状态/编辑/指派均403且无变更；归档任务及看板写入409、阶段和历史不变；解除归档保持阶段。390px 只读浏览器两项通过：状态/历史可见，选择和提交禁用、明确解释，点击不发写请求。
- 最终运行容器中 PHP 源码语法通过；旧完整回归233 passed、18 subtests passed（71.68s），Vault/state 定向 work/p12 独立目录。旧业务测试不是新 PHP 或 UI 证据。本包未重新启动四服务，新增阶段的实际服务重启恢复仍需直接验证。
- J07 继续未完成：决定关联、项目动态整合、个人列表状态筛选与原生卡片就地同步、分页/空状态/冲突恢复、旧完成任务选列浏览器、指派未知响应恢复、成员退出业务事件及独立整体验收仍缺；状态恢复键跨后续不同意图的边界需核查。其余十五条核心旅程未删减。
- 行动思想复核：本次修正普通软件状态一致性和权限边界，适用已有证据类别与人的重试连续性原则；没有模型认知效果试验，不增加哲学条目。旧 raw、SQLite 与 runtime 状态没有迁移或重写。


### 开源底座 P13 · 个人任务四状态列表（2026-10-07）

- Cognos 0.7.0 已实际升级并启用。新增 GET /apps/cognos/tasks，在原生 Deck 当前可读看板内，按稳定登录 UID 的直接用户指派筛选，不把未指派任务或其他负责人的任务混入“我的任务”。查询同时排除已删除/归档任务、列及看板，支持全部/未开始/进行中/阻塞/完成和按任务 ID 的分页。完成判断仍使用原生 done，其余使用已保存阶段，没有建立第二份任务。
- 原生“即将到来的卡片”包括未指派且排除完成，不能直接当个人任务。正式 Deck 页面在该视图加入“我的任务”及状态筛选，任务链接打开真实详情，返回列表后读取最新状态，完成任务仍可发现。当前个人列表覆盖直接用户指派，未把组/团队指派当作已经验证。原生看板卡片和编辑器就地同步仍缺，不用新增个人视图代替此项。
- 新列表提供 loading、禁用刷新、错误/重试和空列表下一步提示；切换筛选中止旧请求，避免慢响应覆盖新筛选。初验发现 Nextcloud selectDistinct 只接字段数组，原调用只返回 ID/状态，字段缺失；已修正并真实重验。施工脚本首次默认 GBK 读取中文 JS 失败，修正 UTF-8 并完成剩余部署；没有把中间未升级状态算作完成。
- 模板加载按实际 TemplateResponse app=deck 和已登录判断，所有适配脚本允许 /apps/deck 根路径。新浏览器从 Deck 根入口核对正式扩展已加载；不依赖用户最初恰好打开带末尾斜线的路径。
- API 五项通过：稳定 UID 的本人指派可见，另负责人/未指派不可见；四状态过滤与完成可发现；游标严格筛选较旧 ID，非法条件拒绝；归档看板及撤销后新客户端不返回；匿名401。这里只验证游标边界与已测试数据，超过100条的“更多任务”整体验收仍需直接覆盖。
- 同显示名额外两项通过：隔离测试第三账号暂时改为 B 的显示名并授予只读，可读共享任务但个人列表不返回 B 指派；B 原账号仍可发现。测试 finally 恢复显示名并移除仅本次新看板的临时成员权限，不改旧项目身份。
- 390px 真实浏览器六项通过：根入口加载、完成状态筛选、打开详情并重开为阻塞后返回列表一致、1200ms真实延迟下 loading/禁用可见、完全断网错误和恢复重试、另一账号空列表解释。截图已检查，个人列表宽度在视口内。
- 保存既有卡片100 的字段、阻塞阶段、列表行及历史摘要后，实际 restart 独立 app/cron/db/redis，记录四个启动时间；新 HTTP 客户端三项通过，当前快照/列表行一致、旧事件摘要不变。新 Chrome 手机两项通过，读同一重启前任务的列表与详情阻塞状态。本次直接补足新增阶段的服务重启证据，不把新建任务当恢复旧任务。
- 原有双成员页面链路最终六项通过（新卡片103）：A 创建/指派、B发现/390px完成、历史及新导航一致，完成任务离开原生 upcoming 且仍在个人列表。原探针“整个页面不含已完成标题”因新增完成个人列表失效，已限定到原生 upcoming 子列表并新增个人完成发现断言，不以错误断言改变产品行为。
- 最终 PHP/五个 JS 语法、Python AST 和 diff 检查通过；旧完整回归233 passed、18 subtests passed（70.34s），默认 Vault/state 指向 work/p13 独立路径。旧测试不是新 PHP/浏览器或认知效果证据；旧 raw/runtime/SQLite 无迁移。
- J07 继续未完成：决定关联、项目动态整合、原生看板和编辑器就地同步、完整分页与冲突/空历史恢复、旧任务返回列、指派未知响应、成员退出事件和独立整条旅程验收仍缺；个人列表不等于所有跨应用项目退出保证。其余十五条旅程保持原范围。
- 行动思想复核：这是人的任务发现、当前状态和恢复连续性工程证据，适用已有原则，不增加模型认知效果主张或哲学条目。


### 开源底座 P14 · 项目任务动态与历史权限（2026-10-07）

- Cognos 0.8.0 已实际升级并启用。在原生看板加入可展开“项目动态”，聚合同一看板已保存的创建、状态、指派取消及编辑业务事件；可见稳定作者、时间、任务链接、结构化前后变化。直接读取现有业务表，没有复制事件或用原生活动冒充自身全部记录。详情与动态共用事件显示器，补充标签/期限/开始时间/颜色变化显示。
- 新 GET /apps/cognos/boards/{id}/events 使用原生当前看板 READ 权限，匿名401、非成员及退出成员403；归档看板授权历史仍可读。事件按 ID 倒序，查询51条后返回50条，确有更多才给游标；卡片历史同步使用该读取服务，非法游标400。删除或移出看板的任务保留原记录，派生 task_url 为空，不给用户失效任务入口。链接不是存储事件的一部分。
- API五项通过，卡片104：新项目空动态、A创建与B状态/编辑、55次真实操作两页50+5无重复/无虚假末页、详情与项目一致/旧前后值不改、非法游标、双成员一致/退出及非成员拒绝。界面手机六项通过：实际50+6记录、作者/时间/指派前后、延迟loading与禁用、断网重试、原生可见创建即时刷新已展开动态、B改状态后A新客户端看到且可打开当前任务。
- 历史保留三项通过：只删除新建的可丢弃测试任务，原作者/时间/前后值不改，原链接撤下；归档看板动态不改；另一新空看板不混入旧项目记录。空列表手机一项通过，说明创建/更新为下一步，没有残留行或更多按钮。截图已检查，动态面板在390px内，独立滚动不挤掉任务操作。
- 复用既有独立审查 agent，限定只读检查权限/保存/分页/SPA恢复。审查发现真实边界：当前任务权限不能授权全部过去看板事件。随后以全新私有/共享看板、合成测试说明、真实原生跨看板更新复现：B能读公开当前说明，同时收到旧私有说明。不是以静态猜测声称缺陷已复现。
- 修正后卡片历史仍先检查当前任务权限，再按每个事件原看板的当前 READ 权限在SQL分页之前过滤。旧记录原文不改，不把移到共享看板当成自动公开过去；不可见事件不影响页长或游标。实际五项通过（卡片108）：B看当前任务但旧私有事件/游标不可见；新共享事件可见；A仍读全部55条50+5且旧私有项目记录不改；明确授权原看板后可见，撤销后再次隐藏。首次修正立即读到旧PHP缓存，核查实际opcache.revalidate_freq=60；源码已挂载且lint通过，缓存重验证后验收通过，最终又重启四服务核对。
- 审查 agent 复核上述两文件与五项JSON后关闭本次发现；它未执行主线程的测试，不把只读复核冒充独立完整旅程操作。此次只证明Cognos历史入口的原看板权限边界，原生Deck所有入口及跨应用退出仍不据此完成。
- 保存重启前项目动态和移入任务受过滤历史摘要，实际restart独立app/cron/db/redis并记录启动时间；新HTTP三项通过，项目记录摘要不变、公开任务/唯一共享事件不变、旧私有事件仍隐藏。新Chrome手机两项通过，同一任务状态与唯一可见记录正确，共享项目仅显示已保存共享事件。
- 最终 PHP/JS/Python AST 与diff检查通过。旧完整回归233 passed、18 subtests passed，Vault/state定向work/p14-final独立路径；此证据不代替PHP/浏览器/模型效果。旧raw/runtime/SQLite没有迁移或改写。
- J07继续未完成：决定关联、原生看板卡片/编辑器就地同步、个人列表大页与更多恢复边界、旧完成返回列、指派未知响应恢复、成员退出事件及独立完整旅程验收仍缺。项目动态本包聚合任务变化，没有扩大为讨论/文件/决定/执行的全项目时间线。其他十五条核心旅程保持原范围。
- 行动思想复核：审查使工程权限模型具体修订为“当前对象权限不能自动授权原项目历史”；已有来源与证据纪律适用，没有模型认知效果试验，不制造新的哲学结论。

### Hermes 外部多账号 sandbox 首轮探索（2026-10-08）

- 未新增 Cognos `Space` 或自主 Agent 本体；为 Hermes 建立 `cognos-sandbox-exploration` skill，规定它作为外部参与者/审计者使用隔离服务和多账号探索，不在发现阶段改源代码或调用 Activity publish。
- 使用临时根目录 `/tmp/cognos-sandbox-Ydtdnz` 隔离 Vault、state、auth、org、activity 等数据，在 `127.0.0.1:8876` 启动独立服务；模型调用为 0，生产数据未使用。脱敏报告：`work/hermes-sandbox-exploration-2026-10-08.json`。
- 三个独立账号注册、独立 cookie 登录和 `/api/auth/me` 身份区分通过；组织创建、项目创建、双邀请、双接受及组织成员投影通过。
- 真实边界：接受组织邀请不会自动成为项目成员；必须再由发起者 POST `/api/projects/{project_id}/members`，之后项目消息中的成员投影才包含 A/B。该行为尚未判定为缺陷，需结合 J03 和界面是否明确第二步继续验收。
- 未认证 GET `/api/projects/{project_id}/timeline` 返回 HTTP 200（sandbox 项目无事件），未认证项目消息写入返回 HTTP 401；项目读取边界是否符合 J04 尚需用有私有内容的 authenticated outsider 场景确认。按路径直觉调用 GET `/api/projects/{project_id}/members` 返回 405，当前成员读取嵌在项目响应中而没有该 GET 路由。
- 本批是工程/用户旅程探索证据，没有模型认知效果证据；不据此新增行动思想条目。下一步先完成 authenticated outsider、J05/J07 双成员刷新/重新登录，再决定是否修产品或只修验收路径。

### 空间与认知架构工作稿（2026-10-08）

- 用户明确把下一阶段施工顺序改为：先做可实际使用的笔记与会话空间，对齐 Obsidian 的基本承载能力；再把个人笔记、GPT/Claude/Opus/Hermes 等会话和历史材料迁入，实际使用后再判断空间架构怎样改进。
- 用户提出三个重要候选结构：主体空间、对象/存在空间、世界空间。当前只记录为结构视角，不冻结为三个固定菜单或三个数据库本体。
- 当前工作原则：主体自己决定空间、视角和关注；Agent 只有用户点击或 `@` 明确召唤时才进入；笔记、会话和历史保留来源边界；整合通过关系和引用发生，不把所有历史压平为一份摘要。
- 已建立工作稿 `docs/Cognos-空间与认知架构-工作稿.md`，记录来源、当前形成内容、未决问题、第一阶段实践目标及与现有 Organization/Project/Activity 的关系。
- 这次改变了下一步行动顺序，但尚未有真实笔记空间使用证据，也没有证明三空间是最终本体；本批不修改行动思想。下一步应围绕笔记/会话迁入和真实使用建立最小可用回路。

### 笔记与会话迁入第一批盘点（2026-10-08）

- 确认真实来源存在三类：本机 Obsidian Vault、`ChatGPT/New project` 交接/试用文档、当前 Cognos 仓库文档与工作证据。只读规模盘点约为 Vault 728 个文本/结构文件、ChatGPT project 602 个、Cognos 仓库 784 个；文件发现数不代表内容已阅读或适合迁入。
- 建立 `docs/Cognos-笔记与会话迁入-第一批.md`，选择 5 个跨来源样本：带 frontmatter 的 Markdown、项目 Markdown、Agent 交接文档、结构化 JSON、当前空间架构工作稿。第一批明确不全量导入、不自动总结、不调用模型、不修改原文件。
- 新增只读工具 `tools/build_note_import_manifest.py`。它只记录来源根、相对路径、稳定来源 ID、SHA-256、mtime、文件类型/长度、可解析 frontmatter 和显式 `[[wikilink]]`；不把正文写入清单、不覆盖源文件。
- 实际运行生成 `work/note-import-manifest-2026-10-08.json`：5 个样本全部存在，`missing=0`，`content_included=false`，5 个记录均无 `raw_content`；Python compile 与独立清单断言通过。
- 本批只证明来源识别和最小 provenance 记录可行，不证明笔记空间 UI、链接语义、会话整合或认知架构成立。下一步应选定笔记空间承载层，再实现“打开原文→用户建立空间→手动连接→刷新可回看”的第一条真实闭环。

### 笔记空间第一条闭环原型（2026-10-08）

- 在现有 Web 服务中新增独立笔记空间投影：`web/notes_store.py`、`web/notes_api.py`，通过 `COGNOS_NOTES_DB_PATH` 隔离 SQLite；不把笔记塞进 Activity，也不读取/覆盖本机原始 Vault。
- 新增 `/notes` 工作台及 `notes.html`、`notes.css`、`notes.js`：可建立空间、建立/编辑笔记、保存来源元数据、显式建立笔记连接、回看空间内笔记与连接；Agent 只显示明确召唤入口，本批没有自动调用。
- API 写入均要求登录；来源字段由用户提交并保留 `source_root/source_path/source_kind/content_hash/immutable` 等信息。跨空间连接拒绝，同一连接重放不重复。
- 窄测试 `web/tests/test_notes_api.py`：`uv run ... pytest -q web/tests/test_notes_api.py` 实际 `1 passed`。项目 `phase0/.venv` 原生扩展缺失导致直接 pytest 收集失败，未将环境错误混写成代码测试失败。
- 独立隔离 HTTP 服务 `127.0.0.1:8877` 实测：注册 → 创建空间 → 创建两份带来源笔记 → 建立显式连接 → 新请求重新读取，返回 200，读取到 2 notes / 1 link，来源元数据保留。浏览器 `/notes` 页面实际加载，标题和 `notes.js` 正常，无 console error；本轮浏览器检查为未登录首屏，尚未做登录态点击验收。
- 首次登录态浏览器验收发现空间列表没有按账号隔离：新账号能看到另一个测试账号的空间。已修复 `owner_account_id` 隔离、旧表幂等迁移及 API 所有者检查；之后干净端口 `8878` 验证新账号只看到自己建立的 1 个空间。
- 登录态浏览器复验实际通过：注册隔离账号 → `/notes` 登录态显示空间 → 点击“＋笔记” → 输入“主体空间与对象空间先在这里相遇。” → 保存 → 页面显示笔记且刷新后仍可回看。页面没有 console error。该闭环仍未接入第一批真实文件正文和 Agent 调用。
- `node --check web/frontend/notes.js`、`git diff --check` 与窄测试 `1 passed` 通过。该批证明的是笔记空间的工程闭环、账号边界和来源留痕，不证明三空间架构、地图视图或 Agent 介入已经成立。下一步是用第一批真实样本导入正文，再让用户实际使用并记录结构缺口。

### 笔记/项目 Agent 对话与多账号模拟（2026-10-08）

- 修正前一批原型的真实缺口：笔记空间原来只有材料/连接，没有对话；项目工作台原来虽有项目消息 API 和 AI 成员数据，但没有明确的团队讨论页和 Agent 发送控件。
- 新增笔记空间 `note_messages` 投影和 `/api/notes/spaces/{space_id}/messages`：消息绑定空间，可选绑定当前笔记；用户明确选择 participant 后调用既有 `ConversationService`，回复写回空间；不启用后台自主 Agent。
- `/notes` 增加 Agent 选择、空间消息列表和发送表单；点击 `@ Agent` 聚焦输入框。项目工作台增加讨论渲染和发送表单，复用既有 `/api/projects/{project_id}/messages`、participant 注册表与 AI 成员机制。
- 定向测试：`web/tests/test_notes_api.py` + `web/tests/test_org_api.py` 实际 `12 passed`；新增笔记对话测试与项目讨论到 Agent 的测试。Python 编译、`notes.js`/`workbench.js` 语法检查通过。
- 多账号模拟实际通过：创建 Founder、Member、Observer 三个账号；Founder 建立组织/项目和 Gemini AI 成员，Member 接受邀请并在项目讨论中发言，Gemini 回复；Founder 建立笔记空间和起点笔记并选择 Codex 对话，Codex 回复；Observer 看不到 Founder 的笔记空间；两个 runtime 各被真实调用 1 次。
- 这次模拟确认的产品行为：Agent 不是自动加入所有地方，而是需要被加入组织/项目或在笔记空间中被明确选择；“项目团队讨论”和“围绕笔记对话”是两条不同作用域。尚未把笔记对话回复自动转为项目任务/决定，也尚未做真实模型浏览器端到端验收。

### Agent 对话入口复核（2026-10-08）

- 修复笔记空间创建时对新 `owner_account_id` 字段的显式列写入，避免旧式 `INSERT ... VALUES` 在表结构扩展后错位。
- 窄测试与语法复核再次通过：`web/tests/test_notes_api.py` + `web/tests/test_org_api.py` 为 `12 passed`；`notes_store.py`、`notes_api.py`、`server.py`、两份测试 Python compile 通过；`notes.js`、`workbench.js` `node --check` 通过。
- 登录态浏览器实际看到 `/notes` 的 Agent 下拉（Gemini、Gemini-B、Codex、DeepSeek）、输入框和“发送给 Agent”；项目页签源码确认含“讨论”，工作台脚本含讨论列表、Agent 选择和发送表单。
- 多账号 ad-hoc sandbox 复验通过：Founder/Member/Outsider 三账号，项目消息 `Member → Gemini`，笔记消息 `Founder → Codex`，两个 runtime 各调用 1 次，Outsider 笔记空间数为 0。临时验证脚本已在进程结束后清理。
- 尝试用浏览器直接导航项目根页时，浏览器工具对该 HTML 返回了 UTF-8 解码错误；同一服务的 `/notes` 浏览器页和 API 均正常，项目根页本轮未声称完成浏览器端到端验收。该问题应作为下一轮 UI 验收项，而不是用 API 通过替代。

### 按用户意图进行的关键环节模拟（2026-10-08）

- 模拟目的从机械 CRUD 改为逼近用户可能做的事：先放入未定型材料、不急着变任务；要求 Agent 区分事实/可能理解/待查内容；补充和修正材料后继续；再尝试把来源带入团队讨论；Member 与 Founder 看到一致上下文；Observer 不越权。
- 三账号隔离 API 模拟实测：未定型原文进入个人笔记空间成功；选择笔记后对 Agent 发问成功；编辑补充后继续对话，历史 4 条、原来源标记仍保留；Member 可进入项目讨论，但个人笔记列表为空；项目消息无 `source_note_id`，没有可用的笔记→项目分享路由；两个个人/项目消息分别能调到不同 runtime。
- 模拟发现并修复关键上下文 bug：原 `/api/notes/spaces/{id}/messages` 虽保存了 `note_id`，却只向 runtime 发送用户输入，Agent 实际看不到所选笔记。现在调用上下文明确包含空间标题/说明、当前笔记标题与正文、最近 20 条空间对话和本轮输入；对话 UI 仍只显示用户原句，避免把拼装上下文伪装成用户发言。单测断言 runtime 实际收到笔记正文、空间标题和用户输入。
- 负向模拟发现 Agent runtime 故障会冒泡成未处理 500；现改为 HTTP 502，明确“消息已保存在笔记空间，但 Agent 暂不可用；可重试”。测试验证原笔记不变且用户输入仍可读。此次修复保存已发生的用户输入，不声称 runtime 超时后具备模型级幂等。
- 最终定向验证 `web/tests/test_notes_api.py web/tests/test_org_api.py web/tests/test_server.py`：`24 passed`；前端 `notes.js`、`workbench.js` 语法检查与 notes Python compile 通过。
- 保留为产品边界而非工程擅定：个人笔记进入团队时究竟共享同一可修订原件、创建带来源的团队副本，还是分享指定版本；团队成员看到哪些笔记/笔记对话；后续修订是否回流。当前没有分享 API，因此不宣称该路径成立；模拟也没有自动复制或公开个人笔记。
- 还需真实浏览器检验项目讨论页与笔记 Agent 消息 UI 的错误重试/上下文显示；本批 UI 级证据只确认 `/notes` Agent 控件存在，语义证据来自隔离 fake-runtime API，而非真实模型认知表现。

### “创立空间”作为核心动作的当前分析（2026-10-08）

- 用户指出创立空间是大核心，要求继续思考其做法与含义。结合空间架构工作稿和最近模拟，新增工作稿中的暂定假设：创立空间是主体为一件值得继续面对的事给出可返回的连续位置；不要求先确定其类型/终点。其实际影响必须落在连续性、来源/参与边界、可见性、参与者/Agent 授权和后续可修订性，而非只增一个空间 CRUD 表单。
- 提出待验证的最小动作链：从空白处或既有对象发起 → 保留来源起点 → 暂定名称/当前关注可跳过 → 明确个人/团队可见范围 → 可选加入人/Agent（默认 Agent 不进场）→ 展示起点与最近变化，允许继续/离开/修订。
- 已明确当前原型与核心问题的差距：它从标题创建私有空间，没有“从笔记/会话/项目对象开始”、可见性选择、创立理由/关注、分叉/暂停或回访继续线索。故现有 API/browser CRUD 证据不能证明“创立空间”已成立。
- 当前仅形成架构假设和可推翻条件，未有用户长时间实际建立/重返不同类型空间的证据；按 AGENTS.md 不把概念推导伪装成过程结晶，不修改行动思想。下一阶段应在真实样本上测试“从对象开始 vs 空白开始”“名称/关注可跳过”“个人/团队边界可见”“第二次回来能否继续”，并观察用户何时需要分叉或关闭。

### Cognos 运行环境收拢（2026-10-08）

- 唯一主代码仓库确认是 `C:\Users\lnp\Documents\Playground\cognos`，当前分支 `master`；`C:\Users\lnp\Documents\ChatGPT\New project\work\cognos-development` 是无当前未提交改动的开发副本，不作为运行入口。
- 保留唯一正式本机入口 `http://127.0.0.1:8765`。8765 健康检查返回 `{"status":"ready"}`，根页面和 `/notes` 均 HTTP 200。
- 停止并清理本轮临时端口 `8876/8877/8878/8879` 的服务；临时 `/tmp/cognos-*` sandbox、浏览器 profile、临时验证脚本已清理。仓库源码改动、`work/` 证据、部署日志、施工台账和测试报告未删除。
- 公开入口 `https://choir-bestsellers-calls-labor.trycloudflare.com/` 在收拢复核时仍 TLS handshake 失败（HTTP 000），本机没有活动 `cloudflared`。部署状态文件只证明它曾指向本机 8765，不能证明隧道现在仍存在。
- 当前整理后的事实边界：一份主代码、一份正式本机运行实例、无活动临时验证实例；公开实例已断开，尚未部署本地未提交改动。后续如恢复公开访问，必须从主仓库 8765 明确启动单一 tunnel，并记录新的公开 URL 与启动证据。

### 外部工作目录与“最新 Cognos”同步核对（2026-10-08）

- 用户进一步指出：其他地方可能有主项目没有的改动。本轮只读比较了主仓库、`cognos-development`、`cognos-live-audit`、历史 delivery 目录、`Documents/cognos` 和 `cognos-workspace`，没有盲目覆盖文件。
- `cognos-acquisition-delivery/receipt.json` 明确记录 11 个文件已 `applied` 到 `C:\Users\lnp\Documents\Playground\cognos`；`cognos-delivery/receipt.json` 明确记录 18 个文件已 `applied` 到同一 target。这两个目录是历史交付包/备份，不是尚未同步的源码分支。
- `cognos-development` 是 2026-09 的旧底座，缺少当前主仓库的 `web/notes_store.py`、`web/notes_api.py`、`web/frontend/notes.*`、新认证等文件；不能反向覆盖主仓库。
- `cognos-live-audit` 没有主仓库缺失的路径；共同文件有 44 个内容不同，但主仓库对应文件普遍时间更新、尺寸更大，live-audit 是较早审计快照。其 `README` 也描述的是旧的 phase0/web 层，不包含本轮笔记空间系统。
- `Documents/cognos` 是更早的 `models.py/reader.py/store.py/rhythm.py` 原型；`cognos-workspace` 是旧前端/桥接实验。它们没有可直接合并到当前 Web 主项目的增量实现。
- 因此本轮没有需要从“其他地方”同步进主仓库的源码改动；同步动作是零，不复制旧文件、不覆盖当前未提交工作。主仓库实际 `/notes`、`notes.js`、`workbench.js` 均由 8765 返回 HTTP 200，当前本地最新改动已在目标主项目中。
- 尚未同步到公开实例的不是“漏掉的源码”，而是公开 Cloudflare Tunnel 已断、没有运行中的部署进程；公开部署需要另一个明确的发布动作，不能通过复制历史目录解决。

### 主仓库最新版本公开访问（2026-10-08）

- 用户确认需要把最新主仓库继续公开。核对发现旧 `deploy/nextcloud-trial/start-public.ps1` 同时启动 Cognos、webhook proxy、Nextcloud/Talk 两条 Quick Tunnel；其中旧状态里的 `choir-bestsellers-calls-labor.trycloudflare.com` 是 Nextcloud members URL，不是 Cognos 首页 URL。此前把它称为 Cognos 公开首页是错误的，已纠正。
- Docker Desktop 当前不可用（Docker API pipe 不存在），因此没有执行旧的 Nextcloud/Talk 公开脚本，也没有启动那套外部底座。
- 直接从主仓库 `C:\Users\lnp\Documents\Playground\cognos` 使用官方 `cloudflared` Windows amd64 2026.10.0，将唯一正式本机服务 `http://127.0.0.1:8765` 建立单一 Quick Tunnel。新公开 URL：`https://reuters-orlando-prefix-passing.trycloudflare.com/`。
- 实际验证：新 URL 首页 HTTP 200，`/notes` HTTP 200；浏览器打开标题为 `Cognos · 第一发生场`，实际读取到主仓库第一发生场内容。该 tunnel 只转发 Cognos 首页/assets/API，包括 `/notes`，不包含 Nextcloud/Talk。
- 状态记录：`tools/public-tunnel-state.json`；cloudflared 会话为 `proc_779eb3aa6a66`，connector id 已记录。Quick Tunnel 是临时地址，进程停止或机器重启后 URL 会失效，不应作为长期生产部署。

### 空间起点与 Activity 接续探针（2026-10-08）

- 本阶段没有把“空间”直接新增为三种本体；先对比三种起点：空白建立个人笔记空间、从笔记进入持续活动、从项目发生进入 Activity。
- 隔离探针实际结果：空白笔记空间创建 HTTP 200，带来源笔记创建成功；项目发生 → `/api/projects/{project_id}/activities/from-occurrence` HTTP 200，返回 `source_occurrence_id`；当前笔记 → Activity 没有对应入口，尝试的候选路由返回 404。
- 这不是“空间功能失败”的泛化结论，而是一个明确的连续性断点：当前系统允许“材料空间”和“问题活动”分别存在，但尚不能让用户从一份个人笔记/会话起点明确创立并持续进入 Activity；也不能因此决定应共享原笔记、创建快照，还是只把笔记内容作为不可变 Activity 材料。
- 探针第一次因临时脚本未加入 `web/tests` 导致 `ModuleNotFoundError`，未产生产品结论；修正测试路径后同一探针通过并取得上述 HTTP 结果。临时脚本已清理。
- 当前阶段的工作判断：创立空间的核心不只是空白空间 CRUD，而是“起点 → 持续展开 → 返回/修订”的接续语义。下一步先比较三种明确动作的用户含义和来源边界，再实现最小接续，不自动把所有笔记公开到项目或复制成第二份权威材料。

### 第一项真实施工：笔记起点进入 Activity（2026-10-08）

- 用户确认当前整理已足够开始行动。本批没有继续扩写 Space 本体，而是施工最小接续动作：在笔记编辑器选中一份笔记后显示“围绕此起点继续”，必须选择一个已有项目；服务端创建 Activity，并将笔记当前正文作为不可变起点快照带入。
- 新入口：`POST /api/notes/{space_id}/notes/{note_id}/activity`，要求登录、当前账号拥有笔记、目标项目存在；创建后返回 Activity、`source_note` 和来源摘要。没有自动发送 Agent 消息、没有自动创建任务/决定、没有自动公开笔记给团队。
- Activity 材料现在持久保存 `source_ref`：`kind=note`、`space_id`、`note_id` 和原始 source 元数据，同时保留不可变正文快照。之后修改原笔记不会改写已启动 Activity 的起点；测试覆盖该版本边界和其他账号拒绝访问。
- 前端 `/notes` 在选择笔记后读取当前账号的组织项目，显示目标项目选择与“围绕此起点继续”；成功后进入 `/activities?project=...&activity=...`。这只是个人笔记到项目 Activity 的明确用户动作，不等于团队已经看见笔记正文或 Activity 已自动发布项目事实。
- 定向验证：`web/tests/test_notes_api.py web/tests/test_org_api.py web/tests/test_server.py` 实际 `26 passed`；`notes.js`、`workbench.js` 语法检查通过；相关 Python compile 与 `git diff --check` 通过。
- 公网真实 HTTP/浏览器前置验证：新注册隔离账号 → 建立笔记空间/来源笔记 → 建立组织/项目 → 调用笔记起点 Activity 接口 HTTP 200，Activity 返回正文快照和来源笔记 ID；随后重启正式本机 8765，公网 tunnel `https://reuters-orlando-prefix-passing.trycloudflare.com/notes` 仍 HTTP 200。公网 Activity 页面导航工具出现 UTF-8 解码错误，因此本批不声称 Activity 页面点击级浏览器验收完成。
- 当前阶段重新暴露的下一问题：Activity 起点已能回指笔记，但“Activity 的多次展开结果如何回到笔记、如何由用户确认进入项目、个人笔记怎样以原件/快照/指定版本分享给团队”仍未决定，不能通过本批实现推导出完整空间架构。

### 主线切换：Nextcloud 底座的第一项 Cognos Activity 起点切片（2026-10-08）

- 用户指出上下文过长导致我把 FastAPI 行为原型误当成最终最新 Cognos；精简交接后重新确立：Nextcloud 自部署底座是产品主线，FastAPI 只提供已验证行为证据。
- 检查 Nextcloud `cognos-app`：当前已有 Deck 任务创建、事务、状态、个人任务、任务事件和权限适配，但还没有 Cognos Activity/来源快照对象。没有把 FastAPI notes/auth/org SQLite 复制到 Nextcloud。
- 开始迁移第一个窄切片：Nextcloud 用户从有读取权限的 Deck 卡片创建 Cognos Activity 起点快照。新增 `cognos_activities` migration `Version000700Date20261008000000.php`、`ActivitySourceService`、`ActivityController`，路由 `POST /index.php/apps/cognos/activities/from-card/{cardId}` 与 `GET /index.php/apps/cognos/activities`；扩展版本从 `0.8.0` 升为 `0.9.0`。
- 快照保存 `board_id/card_id`、操作者、标题、描述、Deck 列、完成状态和时间；调用 Deck 原生读取/权限，不修改 Deck 卡片。该切片验证的是“底座对象 → Cognos 起点来源”，尚未接 Agent、笔记迁移、团队共享或 Activity 多轮运行。
- 静态检查：`info.xml` XML 解析通过、20 个 PHP 文件均有 PHP 起始标记、`git diff --check` 通过。真实 PHP lint、Nextcloud migration、权限 HTTP 和浏览器验收被当前环境阻断：本机没有 PHP CLI，Docker Desktop Linux engine pipe 不存在。不能把源码写入或静态检查称为 Nextcloud 功能完成。

### Nextcloud Deck 起点用户入口（2026-10-08）

- Deck 卡片详情页现由 `TaskFeedbackListener` 注入 `activity-start.js`。在真实 Deck 卡片页上预留“围绕此任务继续”入口，调用 Cognos Activity 起点路由；它只保存快照并显示 Activity ID，不伪造尚未实现的 Activity 详情链接。
- 该入口把“底座对象 → Cognos 起点”的动作放回用户实际工作的 Nextcloud 页面，不再要求用户手动调用 API。它仍未接 Agent、多轮 Activity UI、Activity 列表/详情页或返回项目确认。
- 静态验证：`node --check activity-start.js`、`info.xml` XML 解析、`git diff --check` 通过；PHP lint、Nextcloud migration、Deck 浏览器点击验收仍因 PHP CLI/Docker Desktop 缺失而阻断。

### 意图驱动多账号探索：第一轮与恢复轮（2026-10-08）

- 探索目标不是 CRUD 通过，而是模拟 founder、member、outsider、AI participant 的实际意图：保留未定型材料、召唤 Agent 读当前笔记、修订后进入 Activity、加入团队但不自动获得私有笔记、在项目中明确召唤 AI、猜测 URL 越权、失败后恢复。
- 第一轮隔离 FastAPI sandbox 结果：未定型材料保存通过；Agent runtime 实际收到当前笔记内容且刷新后有两条对话；笔记→Activity HTTP 200 且保存 `source_ref`；成员加入后私有空间数为 0；项目中 AI 消息 HTTP 200 且 runtime 被调用；非成员读取项目消息意外 HTTP 200，而读取私有笔记 HTTP 404。
- 第一轮唯一窄修复：`GET /api/projects/{project_id}/messages` 现在要求登录并核对组织成员和项目成员，不满足返回 HTTP 403；没有改变写入路径或私有笔记边界。现有组织/笔记/服务器定向回归 `27 passed`，修复后隔离重跑得到项目消息 HTTP 403。
- 恢复轮实际结果：同一笔记对同一项目连续两次启动 Activity 原先生成两个不同 ID；新增按 `note_id + space_id + project_id + source` 的来源引用查找并回用已有 Activity，第二次返回同一 ID、`reused=true`。定向测试与隔离重跑均通过。
- 恢复轮另发现：Agent 失败后再次发送相同内容会保存两条用户消息。暂不自动去重，因为重复发送可能是用户有意重试；下一轮先验证 UI 是否明确显示“用户消息已保存但 Agent 没有回复”，再决定重试/复用协议。
- 当前证据仍只属于 FastAPI 行为实验，不能提升为 Nextcloud 已通过；Nextcloud 主线的真实多账号旅程需 Docker/PHP 恢复后复跑。

### Agent 失败后的可见恢复（2026-10-08）

- 第二轮沿着真实用户意图测试：用户明确召唤 Agent，runtime 失败，刷新/重新进入空间，再决定是否重试。后端实际保留用户消息并返回 HTTP 502，刷新后消息仍在；没有把失败伪装成 Agent 回复。
- 发现的 UI 断点：`notes.js` 原先只在 catch 中显示错误，不重新读取当前空间，因此用户看不到已保存的消息，无法判断重试是否安全。
- 单一窄修复：HTTP 错误保留 status；502 时重新打开当前空间，再显示“消息已保存在笔记空间，但 Agent 暂不可用；可重试”的恢复提示。没有自动重发和自动去重。
- 当前验证：`node --check web/frontend/notes.js`、`notes_api.py` compile、`git diff --check` 通过；组织/笔记/服务器定向回归 `27 passed`。第一次验证脚本错误地把 JS 交给 `py_compile`，该验证失败属于脚本错误；更正命令后重新验证通过。
- 未决：重复发送同一文本可能是用户有意的新尝试，也可能是点击重试；下一轮应模拟新用户、刷新/重新登录和明确重试动作，再决定是否引入“重试上一条”而非普通新消息。

### 多 agent 目标审计与权限/版本修复（2026-10-09）

- 按用户要求以目标模式并行审计三个独立面：Agent 失败恢复、新用户/成员生命周期、笔记→Activity→项目连续性。审计均使用临时隔离 ASGI app/数据库，未修改共享主仓库；此前全量基线为 `195 passed, 1 skipped, 5 subtests`。
- 审计发现高风险连续性缺口：项目消息虽已对非成员返回 403，但 Activity 详情、预览、上下文、文档、历史等读取接口仍可绕过项目成员边界；`join_project()` 同时承担授权和自动加入，非成员可通过 Activity 写接口被自动加入项目；笔记 PATCH 未校验所有者；笔记修订后 Activity 复用键没有版本身份。
- 本轮统一修复：Activity 读取/写入先核对既有项目成员，不再以 `join_project()` 授权；笔记起点进入 Activity 要求目标项目成员；笔记 PATCH 绑定空间所有者；Notes 增加持久 `revision`/`content_hash`，来源引用含版本身份，修订后同项目生成新 Activity，旧 Activity 快照保持不变。
- 新增回归覆盖：非成员 Activity 详情/预览/上下文/历史/经验候选/继续均返回 403；非所有者修改笔记返回 404；同一笔记修订后产生新 Activity 且新旧正文分别持久保存。相关测试 `61 passed, 5 subtests`；全量 `web/tests` 实际 `197 passed, 1 skipped, 5 subtests`。
- 独立多账号旅程实际结果：修订后新 Activity HTTP 200、ID 不同；旧快照为“第一版”、新快照为“第二版”；非成员 Activity 详情/继续均 HTTP 403。一次独立旅程脚本先后出现客户端生命周期错误和临时脚本语法错误，均未产生产品结论；改为分行脚本后成功完成。
- 行动层复核：这次说明“某一入口已校验成员”不能代表整个连续性链路安全；以后按用户旅程检查同一来源、读取、继续、修订、发布的全链路权限和版本身份，而不是只验一个写 API。该认识属于工程/权限/来源证据，不宣称认知效果。
- 下一步：在 Docker/PHP 恢复后对 Nextcloud Cognos 扩展复跑同等权限矩阵；FastAPI 结果仍是行为原型证据，不是 Nextcloud 已验收。

### Nextcloud Notes 真正迁入与来源快照（2026-10-09/10）

- 纠正旧的伪接入：此前 Nextcloud `/notes` 控制器直接读写 `.cognos-state/notes.db` 和 `activities.db`，既是第二套存储，也没有可靠复用 Nextcloud 当前账号权限；不能算融入底座。
- Cognos app `0.11.2` 新增 Nextcloud schema：`oc_cognos_note_spaces`、`oc_cognos_notes`、`oc_cognos_note_links`；`oc_cognos_activities` 增加 `source_type/source_id`。Notes 控制器/服务改用 Nextcloud `IDBConnection` 和 `IUserSession`，源码不再包含 PDO、SQLite 或 `.cognos-state` 读取。
- 材料入口注册为同一个 Nextcloud Cognos 导航项：`材料`。页面支持当前账号建立材料空间、创建材料、搜索、查看材料；详情页提供“围绕此材料继续”。未登录 Notes 页面、Notes API、Activity API 均现场返回 401。
- 从材料创建 Activity 的快照保存 `note_id/space_id/title/content/revision/content_hash/captured_at`。同一账号同一材料同一版本重复请求复用原 Activity；材料修订后 revision/hash 改变，建立新 Activity，旧快照不变；来源列表只返回当前账号的 Activity。
- 实际升级：`occ upgrade` 将 Cognos `0.10.0 → 0.11.2`，`maintenance=false`、`needsDbUpgrade=false`；数据库查询确认上述三张 Notes 表及 Activity 来源字段存在；Notes/Activity/服务控制器 PHP lint、Notes JS syntax、XML 和 diff check 通过。
- 仍未迁入：第一发生场/raw bootstrap、FastAPI auth/org/project/inquiry 模型、完整认知 Activity runtime/context/participant、Pi/Codex/Gemini/DeepSeek runtime 调用。Nextcloud `continueActivity` 仍只保存 Activity run；认证后的真实浏览器创建/修订/快照旅程尚需补验。



- 纠正前面“所有 FastAPI 改动都已在 Nextcloud 上”的说法：这不准确。Nextcloud `cognos-app` 是同一主仓库内的自定义应用，但并不会自动包含 `web/`、`phase0/` 的功能和数据。
- 本轮把 Cognos Activity 注册为 Nextcloud 原生导航项：`appinfo/info.xml` 的 `Cognos → cognos.activity.listPage`；Deck 卡片“围绕此任务继续”成功后显示直接打开 Activity 的链接。应用版本为 `0.10.1`。
- 现场验证：`occ upgrade` 将 app `0.10.0 → 0.10.1`；`status.php` 为 installed/maintenance=false/needsDbUpgrade=false；公网登录页 200；未登录 Activity API 401；前端脚本 200；XML、JS、PHP lint 通过。
- 已真正接入 Nextcloud 的范围：Deck 任务创建/状态/指派/编辑/个人任务/项目动态，以及从 Deck 卡片建立 Activity 来源快照和 Activity run 记录。
- 尚未迁入 Nextcloud 的范围：FastAPI Notes/SQLite、第一发生场/raw bootstrap、FastAPI auth/org/project/inquiry 数据模型、完整认知 Activity runtime/context/participant 体系和 `phase0` 的 Pi/Codex/Gemini/DeepSeek runtime 适配。Talk webhook 是集成边界，不等于这些能力已经成为 Nextcloud 原生能力。
- 当前 Nextcloud `continueActivity` 只持久化用户输入为 Activity run，没有调用 Pi 或其他模型 runtime；不能把它称为 Nextcloud 内已完成 AI 继续。下一次迁移必须逐项给出 Nextcloud 数据、权限、浏览器和来源持久化证据。



- 用户指出遇到环境阻碍必须明显报告并尝试解决，不能只在总结中写“被阻断”。重新检查发现 Docker Desktop 已恢复运行；主机仍没有 PHP CLI，因此改用 Nextcloud app 容器内 PHP，不再把“主机无 PHP”当作无法验证的终点。
- 实际底座处理：`docker compose ps` 确认 app/cron/db/redis 运行；容器内 PHP lint 通过；执行 `docker compose exec -T app php occ upgrade`，Cognos 从 `0.8.0` 升至 `0.9.0`，数据库迁移、code integrity check、maintenance mode 开关成功；`occ status` 的 `needsDbUpgrade=false`、`maintenance=false`；`occ db:schema:check` 报告 live schema matches expected；Nextcloud login/status HTTP 200。
- Agent 失败恢复不再只提示用户重新发送：新增明确 `retry` API 与 UI“重试上一条”。重试复用原用户消息，不增加第二条用户消息；成功回复通过 `reply_to` 关联原消息；重试失败仍保留原消息并允许再次重试。新增测试后 FastAPI 全量回归为 `198 passed, 1 skipped, 5 subtests passed`，JS syntax 通过。
- 当前仍未声称 Nextcloud 完整产品验收：真实 Nextcloud 已升级和 schema 验证通过，但 Deck 卡片点击、多账号权限矩阵和 Nextcloud Activity 列表/详情 UI 尚需继续用底座页面验收。

### 目标模式：FastAPI 权限基线第二轮收紧（2026-10-09）

- 多 agent 隔离复现了此前未覆盖的泄露/越权：匿名 bootstrap/instructions/项目决定均可读；非项目成员可读项目决定并可写入决定。旧的 198 全量测试没有覆盖这些路径。
- 本轮修复：`bootstrap`、`instructions` 要求登录；组织列表按账号过滤、组织详情和成员详情要求组织成员；项目 conversations/messages/decisions/work-items/timeline 要求既有项目成员；创建决定由旧 `require_project` 改为 `require_project_member`，非成员不再进入写入路径。
- 重新隔离验证实际输出：匿名 `[401, 401, 401]`；非成员决定读取/写入 `[403, 403]`。定向回归 `70 passed, 5 subtests`；相关 Python compile、git diff check 通过。
- 这次改变了下一步行动：不能用“项目消息已经 403”代表整个 FastAPI 权限安全；必须对同一项目的 conversations、decisions、work-items、timeline、inquiries 和 Activity 做完整矩阵。该结论属于工程/权限证据，不宣称认知效果。
- 当前直接 FastAPI Quick Tunnel 仍关闭；本轮没有重新公网开放。Nextcloud 仍是最终底座，FastAPI 只是安全和行为迁移基线。

### 运行环境与公网入口全面审计（2026-10-08/09）

- 只读审计确认：当前源码仓库只有一份，即 `C:\Users\lnp\Documents\Playground\cognos`；不存在第二份正在作为产品入口的源码。运行态分为 `primary-local`（8765，主 raw/主 SQLite）、`trial-nextcloud`（隔离 `.cognos-state/.cognos-vault`，当前未运行）和历史/临时测试目录，不应把它们称成多个源码版 Cognos。
- 当前只有 `127.0.0.1:8765` 监听；`8775/8776/8780/8876–8879` 均未监听；Docker Desktop Linux engine pipe 当前不可用。临时 FastAPI Quick Tunnel 已停止，公网 URL 返回不可达；本机 8765 仍返回 health 200。停止只结束了已核对的仓库内 cloudflared 进程，没有停止主 Cognos。
- 审计报告 `work/cognos-instance-audit-2026-10-08.json` 只记录实例名、目录存在性、raw 行数/哈希、端口和 API 健康元数据；修订后确认不保存 bootstrap 消息正文。主 raw 93 行哈希 `50274c21b527234e012b578e120d40436c2e49353f4c934310844d36a0e884e0`；隔离试用 raw 3 行、独立哈希；未迁移、覆盖或删除任何 raw/runtime。
- 全量回归在隔离临时目录中通过：`248 passed, 18 subtests passed`；Python compile、48 个 JS 语法、17 个 PowerShell AST、Compose config 通过。PHP lint 未执行，因为主机没有 PHP CLI，Docker 当前不可用；不能把这部分标为通过。
- 发现直接 FastAPI tunnel 曾公开 `/api/bootstrap`、`/api/orgs`、页面和历史读取；因此已停止，不再把“首页公网可达”当成安全试用证据。若重新公开，必须使用受限 webhook proxy 或先收紧所有公开读取权限。
- 发现两套本机 Python 依赖不一致：隔离 venv 为 FastAPI `0.141.1`，当前 Windows Python 为 `0.115.7`，`uv run` 又解析到 `0.143.0`；测试结果不能自动代表所有启动路径。后续应锁定依赖版本并让启动脚本、测试和线上使用同一环境。
- `web/.env` 含 Gemini API key；本次读取过程中该值曾进入本地诊断输出，虽未写入仓库文件，但应立即在 Gemini 控制台撤销/轮换，并避免任何后续命令打印完整环境配置。该项是安全处置，不把 key 写入交接、报告或 raw。
- 工作树仍有大量未提交改动：本轮不 reset/checkout/clean，不删除 `work/`、临时隔离数据或未跟踪交付文件。全量测试曾直接使用默认进程的 SQLite，导致 `activities.db/auth.db/org.db` 与施工前哈希不同；因此“测试未污染默认状态”不能继续作为当前事实，后续测试必须显式设置隔离环境变量，并在测试前后核对保护清单。
### 目标模式：FastAPI 权限完整矩阵与自动入组移除（2026-10-09 续）

- 上一轮结束时有三个路径只被"点名"而未被验证。本轮用**逐路由独立账号**的隔离矩阵重测（每个路由一个全新账号，避免前一次写入污染后续测量）：早期版本因顺序执行让首个写入自动入组，导致其后所有读取返回 200，看上去"全通过"；改为每路由独立账号后才得到真实结果。
- 真实复现的剩余缺口（修复前）：匿名可读 `GET /api/projects/{id}/inquiries` 与 `GET /api/inquiries/{id}`（返回问题正文与关联发生正文）；**已登录非成员可读并且可写** inquiry 列表/详情/尝试、项目消息、组织成员增删、项目成员增删、成员职位修改。根因是 `join_project()` 同时承担授权与自动加入：非成员只需写入一次就获得项目成员身份，随后所有读取变为 200。
- 本轮修复：`auth_middleware` 新增两个**从不自动加入**的共享守卫 `require_org_member()` / `require_project_member()`；inquiry_api 全部四条路由、org_api 的组织/成员/项目成员/项目创建/项目消息写入、以及 activity_api 与 project_api 中重复的本地守卫统一指向同一规则。`join_project()` 只保留在创建项目时的引导路径，并在 docstring 中明确它不再是授权检查。
- 修复后逐路由实测：25 条路由对匿名账号全部 401；对已登录非成员全部 403（含此前返回 200 的 inquiry 读取与组织成员写入）。"写入一次后仍能读取"的越权链条已消除（写入后复读仍为 403）。
- 合法入组路径经端到端实测仍然完整：Alice 建组织与项目 → Bob 未入组时读取/写入 403 且不能给自己开位子（403）→ Alice 邀请 → Bob 接受成为组织成员 → 仍是项目外身份（403）→ Alice 把 Bob 加入项目 → Bob 读取/写入/发起探究全部 200。第三方 Charlie 在所有入组路径上均被拒。
- 全量回归从 `193 passed / 6 failed` 修正为 **`199 passed, 5 subtests passed`**，无失败、无 skipped。六个失败逐个定性而非批量改绿：其中 3 个断言的是上一轮已收紧的旧契约（`/api/bootstrap`、`/api/instructions`、`/api/orgs` 匿名可读），改为断言新的登录要求并保留原有的"枚举整个 router 的写接口必须 401"检查；另外 3 个依赖被移除的自动入组（第二个账号未入组即写入项目），改为走正式的组织邀请/加入与项目加入路径，并新增"未入组写入必须 403"的断言。
- 归因由 git 证明而非记忆：HEAD 版本的 `bootstrap()`/`list_orgs()` 均无 `account` 参数，匿名可读是**已提交**的旧契约；`require_login` 出现在未提交的工作树改动里（来自被恢复的那一轮）；本轮只编辑 5 个源码文件与 3 个测试文件，未触碰 `server.py`。
- 仍未解决、不并入本轮结论：`/api/bootstrap` 对**任何已登录账号**仍返回完整 raw 历史（含其他项目的私有消息正文）与参与说明文件内容及服务器绝对路径；`/api/instructions` 同样对已登录账号返回说明全文与路径。二者现在至少要求登录，但"登录即见全部项目内容"仍不是项目级隔离。这是下一轮最优先项，公网 tunnel 继续保持关闭。
- 工作树中 `activity_api.py`、`org_api.py`、`inquiry_api.py`、`project_api.py` 等文件在磁盘上是 CRLF 而 HEAD 为 LF，因此 `git diff` 显示整文件改动、`git diff --check` 会报大量尾随空白。经逐行核对，本轮新增行本身没有真实行尾空白；这是仓库既有的行尾不一致，不是本轮引入，也未被本轮修正。

