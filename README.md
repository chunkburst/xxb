# XXB (X-X-Bot)

**嘻嘻比** 是一个基于 PHP 开发的高性能 Telegram AI 聊天机器人项目。它支持多种主流 AI 模型（如 Kimi, Gemini 等），具备智能决策、上下文管理、知识库集成及图片解析等功能。

## 🚀 核心特性

- **多模型支持&标签化配置**：支持 Kimi, Gemini 等多种 AI 模型，可根据不同任务（判定、回复、专业回复等）配置不同模型，同时依靠标签化配置，可以(在某个渠道的Token燃尽的情况下)快速切换其他模型使用（避免重复修改配置）。
- **智能决策 (Judge)**：引入专门的判定逻辑，AI 会根据当前对话上下文自动判断是否需要回复，有效避免群聊中的无效干扰。
- **反提示词注入 (Anti)**：对于提示词注入，长时间运行的AI可能因为各种上下文而开始出现问题，而本架构在judge阶段直接引入drop掉原消息以牺牲上下文关联性来增强安全性。
- **异步处理架构**：采用 Webhook 接收消息并异步分发任务的架构，确保对 Telegram 的快速响应，将耗时的 AI 生成过程放在后台执行。
- **上下文管理**：具备完善的上下文记忆与自动清理功能，支持设置最大上下文长度，确保对话的连贯性（你的模型越强大，嘻嘻比也会越强大）
- **知识库集成**：支持永久知识库（`permanent_knowledge.md`）和群组特定知识库，让 AI 能够基于特定背景知识进行回复。
- **图片识别**：支持对发送到群组的图片进行解析和描述，使 AI 能够理解并讨论图片内容。
- **定时任务 (Cron)**：内置定时报告、主动打招呼等功能，增强机器人的互动性和可维护性。
- **多存储驱动**：支持本地文件（JSON）和 Redis 两种存储方式。
- **【饼】 扩展工具 (Tools)**：内置并行工具执行引擎，支持搜索引擎 (SearxNG)、网页内容抓取 (Fetch)、IP 质量查询及定时器管理等功能。（这个功能原本是有的，网关稳定性太差和部署难度暂时移除）

## 🛜 快速开始

- Tips：由于安全性隐患（你也不想Bot会彻底疯狂毁掉你的环境吧?）和稳定性考虑，暂时没有实现tool具体逻辑

- 配置 `config.php`
- 修改 `system_prompt/`(系统提示词,如果有需要) 和 `prompt.txt`(人设提示词)
- 开始食用

## 🛠️ 技术架构

- **语言**：推荐 PHP 8.2+ (我的版本是8.4.7)
- **核心逻辑**：
  - `main_chat.php`: Webhook 入口，负责初步验证和任务分发。
  - `request_chat.php`: 核心业务逻辑处理器，处理消息、调用 AI、发送回复。
  - `src/Core/`: 包含日志、Curl 工具等基础组件。
  - `src/Business/`: 包含上下文管理、人设管理、知识库管理等核心业务逻辑。
  - `src/Services/`: 封装了 Telegram API、AI 服务、工具执行等外部交互。
- **存储层**：通过 `src/Database/` 提供统一的 KV 存储接口。

## 📦 安装与配置

### 1. 克隆项目
```bash
git clone <repository-url>
cd xxb
```

### 2. 环境准备
- 确保已安装 PHP 8.2 或更高版本。
- (可选) 安装并配置 Redis（JSON版不用管）。

### 3. 配置文件
复制 `config.php.example` 为 `config.php`：
- 设置 `telegram.bot_token` 和 `bot_username`。
- 在 `ai_labels` 中配置你的 AI 模型 API 密钥和端点。
- 在 `worker.url` 中设置你部署的 `request_chat.php` 的公开访问地址。
- 根据需要调整 `database` 和 `business` 设置。

### 4. 设置 Webhook
将你的 Webhook 地址指向 `main_chat.php`：
```text
https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://your-domain.com/main_chat.php
```

## 📂 文件结构简述

- `src/`: 源代码目录，遵循命名空间结构。
- `storage/`: 存储目录，包含上下文数据、缓存和知识库文件。
- `system_prompt/`: 系统提示词模板，包括判定逻辑、图片描述等。
- `logs/`: 日志记录目录。
- `cron_handler.php`: 定时任务触发器。
- `cron_report.php`: 运营数据报告脚本。

## 运行与维护

- **日志监控**：通过 `logs/` 目录下的日志文件监控运行状态。
- **定时任务 - **：建议设置 crontab 每15分钟执行一次 `cron_report.php`
- **定时知识库整理任务**： 设置 `php cron_long_term.php`

示例配置:
```shell
cd /opt/xxb/ && php cron_long_term.php >> /dev/null 2>&1
```

## 📝 许可证

本项目遵循 MIT 许可证。

## 📦 Support

请CCB喝杯咖啡！【支持：ETH链 / 币安链(BSC) / Poly链等】

收款地址: `0x34ec2df7a44dfb252ed549a12b329eebfa016117`

![usdt](https://crimson-rear-ladybug-723.mypinata.cloud/ipfs/bafkreig4bunhrakykko3bsjgnrnencxjvxsxv3r7bbtgfvtwvm4nbwware)
