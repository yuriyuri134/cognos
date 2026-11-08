# ChatGPT 聊天端接入状态

更新于 2026-11-09，Cognos 0.37.3。

## 当前结果：真实自动往返已通过

用户已明确授权连接现有 Chrome、使用聊天端额度并发送验证消息，且已开启远程调试、允许本次连接。2026-11-09 在原有 dkwo / Plus 登录中，Cognos 对话 589 的消息 5887 经真实 ChatGPT 以 high 强度处理，回复作为消息 5907 回到同一对话，署名 ChatGPT，原文为「Cognos 聊天端收发验证通过。」

运行编号：`ce6c0f444bddc58808c2cd0401fd421d`。回执 state=complete、provider=chatgpt_chat、reasoning_effort=high。实际在登录后的 Chrome 页面核验了回复、GPT（聊天端）选择、强度「高」和连接状态。验证入口：`http://127.0.0.1:8780/index.php/apps/cognos/creations/589`。

本轮修复：等待账号、输入框与强度选择完成加载；将空输入框自带的换行按空白处理；失败页面保留用于诊断；等待回复的署名读取实际运行者。离线回归覆盖空白换行输入框。

使用时保留原 Chrome 登录和本机服务运行；浏览器重新启动或断线后可能需要重新连接并允许浏览器提示。没有验证系统重启后的自动恢复。验证当时使用临时聊天页；随后用户要求改为普通聊天，现已移除临时聊天参数。失败页面保留；历史失败消息保留，不删除来伪装成功。

## 已实际完成

- 恢复官方浏览器插件缓存、本机连接文件与 Chrome 登记；现有 Chrome 和 Edge 均可连接。
- 按用户明确授权启用并保留 Clash 系统代理，恢复两个浏览器对 ChatGPT 的访问。
- 在既有 dkwo Plus 登录中看到 111 项目；真实 Chat 模式选择 High 后收到「聊天端验证通过」。这是网页验证，并非 Cognos 自动收发验证。
- Cognos 对话区提供 Claude、GPT（聊天端）、Codex。聊天端独立保存默认、低、中、高；Codex 保留原有六档强度和独立偏好。
- 0.37.3 更新静态资源版本，解决普通刷新仍使用旧长缓存的问题。

## 接入方式

`tools/cognos-space-mcp/chatgpt_browser_service.cjs` 是独立于 Codex 会话的本机适配器，使用 Google 官方 Chrome DevTools MCP 的 `--autoConnect`，只连接原有 Chrome，不启动浏览器或创建新登录目录。依赖锁定在 `chatgpt-browser/package-lock.json`。

适配器只在本机回环地址监听，沿用现有桥内部口令；前端拿不到该口令。只有明确点连接才会启动适配器。Google 用量统计、性能查询及无关工具类别关闭。

消息进入 Cognos 后沿现有运行登记和结果回流路径处理：携带对话全文与入口、新建属于适配器的普通 ChatGPT 聊天页、核对可见账号和实际强度、发送一次、等完整可见回复，再回到原 Cognos 对话。不会回退到 Claude 或 Codex。用户原有标签页和草稿不参与自动操作。

运行回执在忽略目录 `deploy/nextcloud-trial/evidence/chatgpt-browser-runs/`，按 run_id 和输入摘要绑定。发送前持久记一笔；发送后断线或超时保留网页，不自动重发。完整回复回执保存正文和来源链接。普通聊天遵循账号现有的历史记录与个性化设置。当前每次运行新开一条普通聊天，并携带 Cognos 对话上下文；尚未实现同一 Cognos 对话复用一条 ChatGPT 历史会话。此前临时聊天的历史不能通过修改入口追溯转换。

授权记录已按用户明确授权写入本机 `.secrets/chatgpt-browser-enabled`，不进入 Git。未授权的其他安装仍保持拒绝连接。启用方式和影响见「GPT-聊天端持久连接方案.md」。

## 11 月 7 日基础验证（历史）

- 7 项 Python 检查通过：ChatGPT 与 Codex 执行隔离、非法输入、运行编号及强度传递。
- Node 离线检查通过：仅连接现有 Chrome、授权门槛、页面归属、账号变化拒绝、完整回复回流、同一运行去重以及不确定发送不重试。未启动 Google 浏览器连接。
- 官方 Google 发布包 CLI 参数检查通过（仅 `--help`，不连接浏览器）。PHP / JS 语法及 `git diff --check` 通过。
- 8780 真实 API 验证通过：默认/低/中/高保存、旧扩展值拒绝、旧偏好回退默认、Codex high 偏好保留、其他账号不能读取验证对话的连接状态；未授权连接不接触浏览器。
- 通过官方已连接 Chrome 核验首页、Initium、Norm、项目页，桌面及 390px 共 8 个视图正常，无页面错误或横向溢出；另核验验证对话 589 的新选择区与手机布局。旧 `check_smoke.cjs` 自建无头浏览器，因此本次用同一组路由和断言在官方浏览器工具中执行，没有额外创建登录环境。
- 桥重载后唯一实例，Claude/Codex 仍可用，原有 commands 开关保留为 true。0.37.3 升级成功、maintenance=false、needsDbUpgrade=false；升级报告已有的 federated_invites 缺表及 space_traces judgment 列差异，本次未修改这些表。

11 月 7 日尚未完成自动往返；11 月 9 日已完成，详见本文开头。最新证据是 `chat-roundtrip-success-2026-11-09.json` 和 `cognos-chat-roundtrip-success.png`，历史失败记录继续保留。

当前证据位于本机 `Documents/ChatGPT/111/chat-end-work/` 的 `chatgpt-web-verification.json`、`cognos-official-browser-smoke.json` 与 `cognos-chat-prepared*.png`。运行结果不入 Git。

## 11 月 9 日：从使用角度补的几处（claude/project-thread-77aevs）

真实往返跑通以后，按「天天用」来看还差这些，这次改了前五条：

1. **每回一句就在 ChatGPT 里新开一条聊天**：侧栏被刷屏，每次都把入口和整段对话粘进去，越聊越长。现在一段 Cognos 对话接着用同一条 ChatGPT 聊天，只发它没看过的：别人（Claude、Codex）后来说的、你这次说的，入口变了才再给入口。前面的话被改过、删过，或那条聊天在 ChatGPT 里被删了，就退回新开一条、带全文。对应关系记在 `evidence/chatgpt-browser-runs/threads/`（不入库）。接着的聊天里上一条回复一直在页面上，所以要等出现新的一条才算回完，不会把旧回复抄回来。
2. **回复只抄纯文字**：标题、列表、代码块、表格、链接、公式都丢了。现在照网页上的结构抄成 Markdown，抄不出来退回纯文字。回答末尾带「在 ChatGPT 里打开这条聊天」。
3. **开页抢焦点、标签越堆越多**：现在默认在后台开页，回完关掉自己开的那页（聊天还在 ChatGPT 记录里）；没回完、出错的页照旧留着。要看着它打字，起适配器前设 `COGNOS_CHATGPT_FOREGROUND=1`。后台页能不能正常出回复，要在 8780 上实测。
4. **没连上就发，页面报「状态未知，勿重复派发」**：Cognos 原来把桥回的 428/503 当成不知道开没开工。现在算没发出去，直接说原因；桥在拒绝之前先去连一次 Chrome（最多等 15 秒），Chrome 弹出提示点允许，再点「再叫一次」。
5. **ChatGPT 以为自己有 Cognos 的工具**：Norm 空间的入口写着「read_space 读得到原话」，它其实用不了。入口里现在照实说它只看得到给它的文字，要读原话、要改东西请换 Claude。

还没做的：

- 额度：Cognos 的额度显示里没有 ChatGPT 聊天端的。
- 回复只抄最后的正文，ChatGPT 的「思考过程」和它在网页里调用的工具结果不带回来。
- 一直依赖你那个 Chrome 开着、远程调试开着；Chrome 重开后要重新允许。这条路本身的风险（OpenAI 条款、远程调试对整个浏览器开放）没变。
- 和这条路并行的另一条：`http_server.py`（端口 8645）让你在 ChatGPT 里聊天时用连接器读写 Cognos，不操控浏览器。
