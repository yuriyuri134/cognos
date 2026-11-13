# Cognos v0.4 · 第一条跨探究复用

2026-11-10，本机 Windows，Nextcloud 35.0.1，Cognos 0.37.3，Node 24.14.0。

## 改动落在哪里

核对了 GitHub 的 `master`（`cdccb11`）、草稿 PR #62（`71c266d`）及本机更新的产品分支（`899c507`）。#62 只增加实验，产品继续沿已有路径运行。Initium 提供独立对话，ReaderService 的任务是阅读与放置，Norm 保留空间及关联原话，SpaceService 的痕迹与反对、ContinuationService 的未完事项和 PushBackService 的队列各有自己的作用。消息仍经原有派发进入桥，结果沿 RunService 回到原对话。E 接在 #62 的现有控制器、事件账本和只读 GPT 入口上。

操作者给 E 登记一份 D 账本和一条已采纳作候选的修订号。E 的新对话只收到自己的问题和工具说明。模型主动查询时，以当前探究账号重新读取源对话，核对原提示、原回复、回复关系及工具反例。执行新批次时，工具要求引用先前实际发现的候选，并再次核对可见性与内容。恢复后，发现和批次证据随目标账本接续。已发现的来源失效时，下一次发送会暂停并保留原因，不占新的模型调用。

## 实际走过的两项探究

源探究 D 在对话 628 查 Node 解析的运行行为。账本事件 5 记录 `2025-02-29` 被归一成 `2025-03-01`。GPT 在回复 6147 中据此提出严格匹配 YYYY-MM-DD、再检查公历月日与闰年。目标 E 的工作是决定另一批供应商导出的行能否入库，使用新样本测试该方法。

| 组别 | 对话与 GPT 回复 | 发现、执行与结论 |
| --- | --- | --- |
| 复用组 | 634，回复 6159、6164、6167 | 事件 5 发现 `creation:628/message:6147/revision:10`，事件 9 带引用选择 `calendar_bounds`，事件 10 执行批次，事件 15 同时引用发现与执行证据。 |
| 对照组 | 635，回复 6158、6162、6166 | 事件 5 返回无匹配，模型自行选同一策略。事件 10 执行批次，事件 15 引用执行证据。 |

两组起始请求逐字相同，使用同一现有 GPT 桥和 `low` 推理设置，最多各四次调用，实际各三次。两组都接收了 `2000-02-29` 与 `2026-04-30`，拒收 `1900-02-29`、`2026-04-31` 与 `2026-4-01`。对照组拥有相同的工具说明。本次证明历史方法进入了新决策并被实际执行，同时保留了来路。对这批数据，准确率和调用次数相同。

8780 上逐条重新核对了六条目标回复及源回复，独立试用账号的源查询返回不可访问，工具结果没有来源正文。11 项 Node 测试通过，含重开账本、引用伪造、回执不符及发现后撤权。也用原始账本重跑了 PR #62 的五个场景证据检查。

首轮 D（632）和 E 对照（633）在桥的模型运行结束后仍等不到回执。D 632 的一条待发结果经原接口按原编号重送，HTTP 201。正式对照沿用前一日已有真实回执的 D 628，并仅对 634、635 的待发结果做同样重送。六条结果全部回到 Cognos，未重新调用模型。自动回送失效的根因仍待核查。试用实例没有重新部署，应用变更的入口是实验命令行。

## 本机复验

在本实验分支的仓库根目录运行。原始 SQLite 账本位于本机忽略提交的 `experiments/cognitive-advancement/out/`。已有源账本可直接用于复验。另起一轮时请换输出文件名。

```powershell
$e = 'experiments/cognitive-advancement'
$env:COGNOS_TRIAL_SECRETS = 'C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial/.secrets'
node --test "$e/controller.test.cjs" "$e/reuse.test.cjs"
node "$e/verify-reuse.cjs" "$e/out/v04-e-control-02.sqlite" "$e/out/v04-e-reuse-02.sqlite" --live
node "$e/run.cjs" inspect "$e/out/v04-e-reuse-02.sqlite"

# 新一轮。从已有的源探究 D-02 的修订 10 开始。
node "$e/run.cjs" start E "$e/out/e-control-new.sqlite"
node "$e/run.cjs" start E "$e/out/e-reuse-new.sqlite" "$e/out/D-02.sqlite" 10
node "$e/verify-reuse.cjs" "$e/out/e-control-new.sqlite" "$e/out/e-reuse-new.sqlite" --live
```

要重新产生源记录，先运行 `node "$e/run.cjs" start D "$e/out/d-new.sqlite"`，再用 `inspect` 找到基于探针反例、提出格式和公历范围检查的修订，取代上面命令的账本和修订号。若有回执超时，先在桥的待发目录和原对话核对现有运行，避免再次派发相同请求。目标在 `pause` 或 `ask` 时可用 `node "$e/run.cjs" resume <目标账本> "<新增条件>"` 接着做。

当前只开放操作者登记的单个候选及固定的只读批次。权限依据是 Cognos 对源对话的当前可见性，本地账本由操作者保管，撤权会阻止再次使用，已写入的证据和既有对话仍用于审计。生产目录发现、产品页面、真实断电及跨模型复验未覆盖。
