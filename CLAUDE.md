# Cognos 项目指南

## 对话协议

本项目遵循"理解优先"协议，详见：~/.claude/agent-base/understanding-first-protocol.md

重点规则：
- 实质性操作前必须先输出理解
- 表达不确定性（百分比）
- 设计任务必须参考成熟产品

## 项目概览
- **名称**: Cognos - Nextcloud Agent 增强应用
- **技术栈**: PHP 8.2, Nextcloud App Framework, JavaScript (Vanilla), CSS
- **开发服务器**: http://127.0.0.1:8780
- **项目路径**: /c/Users/lnp/Documents/Playground/cognos

## 项目结构
```
cognos/
├── deploy/nextcloud-trial/
│   ├── cognos-app/           # 主应用代码
│   │   ├── lib/              # PHP 后端
│   │   │   ├── Controller/   # 控制器
│   │   │   ├── Service/      # 服务层
│   │   │   └── Migration/    # 数据库迁移
│   │   ├── templates/        # PHP 模板
│   │   ├── js/               # 前端 JavaScript
│   │   ├── css/              # 样式文件
│   │   └── appinfo/          # 应用元数据
│   └── docker-compose.yml
└── CLAUDE.md (本文件)
```

## 开发工作流
1. **启动服务**: `cd deploy/nextcloud-trial && docker-compose up -d`
2. **查看日志**: `docker-compose logs -f nextcloud`
3. **重启服务**: `docker-compose restart nextcloud`
4. **进入容器**: `docker exec -it nextcloud-trial-nextcloud-1 bash`

## 代码风格
- **PHP**: PSR-12, Nextcloud 框架约定
- **JavaScript**: ES6+, 无框架 (Vanilla JS)
- **CSS**: 现代深色主题，Token 化设计系统
- **命名**: camelCase (JS), kebab-case (CSS), PascalCase (PHP 类)

## 当前任务
正在重构"创立空间"功能，实现现代化的 AI 对话界面。

## 注意事项
- 使用中国大陆的 API 中转站: https://api.a6api.com
- 所有对话界面需支持 Markdown 渲染
- 优先考虑深色主题和可访问性
