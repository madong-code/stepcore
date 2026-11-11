# StepCore Demo · 运行说明

Web 一体演示：宿主页通过 `<iframe>` 嵌入自研 UI 设计器（`/ui/`），端到端演示
「可视化设计 → 导出 v2 JSON → 引擎 `FlowGraphParser` + `FlowTransitionResolver` 解析」闭环。

> 设计器是纯静态站点（`packages/stepcore/ui/`），画布基于 LogicFlow（UMD 包本地内置在 `ui/vendor/`，
> 通过 HTML `<script>` 直接引入，无需构建、无需联网），可脱离本 demo 被任意宿主以 iframe 远程嵌入。

## 方式一：PHP 内置服务器（零依赖，推荐）

文档根目录设为包根（这样 `/ui/` 才能被当作真实静态文件直接托管）：

```bash
cd packages/stepcore
php -S 127.0.0.1:8000 -t . demo/public/index.php
```

打开浏览器访问 <http://127.0.0.1:8000/> 即可。

- `/`             → 宿主页（左侧 iframe 设计器 + 右侧导出 JSON / 引擎解析结果）
- `/ui/`          → 自研 UI 设计器（静态托管）
- `/api/parse`    → POST v2 路线 JSON，返回校验问题与引擎走查序列
- `/api/schema`   → 返回 v2 JSON Schema

## 方式二：php-fpm + Web 服务器（nginx / apache）

设计器静态资源 `/ui/*` 由 Web 服务器直接 serve，其余路径转发给 `demo/public/index.php`。

### nginx（参见 `demo/nginx.conf.example`）

```nginx
server {
    listen 80;
    server_name stepcore.demo;
    root /var/www/packages/stepcore;          # 指向包根
    index demo/public/index.php;

    location / {
        try_files $uri $uri/ /demo/public/index.php$is_args$args;
    }

    location ~ ^/demo/public/index\.php$ {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/demo/public/index.php;
    }
}
```

### apache（参见 `demo/.htaccess`）

```apache
DirectoryIndex demo/public/index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ /demo/public/index.php [L]
```

## 目录结构

```
demo/
├── public/index.php   # 前端控制器（/ → 宿主页，/api/* → src/api.php；/ui/* 由服务器静态托管）
├── src/
│   ├── bootstrap.php  # PSR-4 自动加载（madong\stepcore → ../../src）+ 引擎装配
│   └── api.php        # /api/parse、/api/schema
├── views/host.php     # 宿主页：iframe + postMessage 接线 + 结果展示
├── nginx.conf.example # php-fpm + nginx 配置样例
├── .htaccess         # apache 重写样例
└── README.md
```

## 通信机制

宿主页与设计器通过 `postMessage` 双向通信：

- 设计器加载完成 → 父页收到 `flow.ready` → 父页可发送 `flow.load` 载入示例
- 设计器模型变化（或点击「保存并推送父页」）→ 父页收到 `flow.save` → 调用 `/api/parse`
- `origin` 可由 `window.DESIGNER_TARGET_ORIGIN` 覆盖，默认 `*`（同源演示）

详见 `../docs/13-ui-designer.md`。
