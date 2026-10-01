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
