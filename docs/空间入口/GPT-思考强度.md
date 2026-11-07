# GPT 回复的接入与思考强度

2026-11-07：对话里的 GPT 经本机 `codex exec` 回复，使用本机已有的 ChatGPT 登录与 Codex 额度。它不是普通网页聊天，也不是单独的 API key 计费入口。界面现标为 `GPT（Codex）`。

在 Initium 或 Norm 对话框上方选择 GPT 后，可以选择默认、低、中、高、很高、最高。设置属于当前账号的当前会话；切换到 Claude 或刷新页面不会清掉它。较高强度通常增加耗时和用量。

默认不覆盖 Codex 自身的模型默认强度；其余值通过 `model_reasoning_effort` 传给这一次执行，不改全局 Codex 配置。GPT 对话仍保持原来的只读工具范围。没有开放需要自动分工的 Ultra。

验证：14 项 Python 检查通过；账号隔离、非法值拒绝、刷新与切换保留通过；真实 GPT high 请求在会话 588 回答“思考强度已接通。”；登录后的页面与 390px 截图已检查。证据为 `deploy/nextcloud-trial/evidence/gpt-reasoning-settings.json` 与 `gpt-reasoning-phone.png`，不入库。

全站 smoke 的首页、Initium、Norm 入口检查通过，随后在既有 Norm 项目的 `#cg-stages li` 等待处超时；没有据此宣称全站 smoke 通过，也没有在这一块修改阶段列表。

2026-11-07 后续：界面现在分别标为 Codex 与 GPT（聊天端）。以上 Codex 记录保留为当时验收；聊天端使用独立的默认、低、中、高设置，当前持久适配器已准备，尚待浏览器授权与真实往返验证，见《GPT-聊天端接入状态.md》。
