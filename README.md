# 云湖 OAuth2 登录 for WordPress

**简体中文** · [English](README.en.md)

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](https://www.gnu.org/licenses/agpl-3.0.html)
[![Version](https://img.shields.io/badge/version-0.1-green)](readme.txt)

让 WordPress 站点支持「使用云湖账号登录」。基于云湖 OAuth2 授权码模式 + 强制 PKCE S256，登录后按 `user_id` 建立本地账号，并把昵称、头像（含防盗链处理）同步到站点。

> 本项目是第三方社区插件，非云湖官方产品。

## 特性

- **标准 OAuth 2.0 授权码流程**，强制 PKCE S256（云湖不支持 `plain`）
- **state 防 CSRF**：64 位随机串，绑定浏览器会话，10 分钟过期、一次性消费
- **自动注册**：首次登录创建本地账号，可指定默认角色
- **账号绑定**：老用户可在个人资料页手动绑定 / 解绑，一个云湖账号只能绑一个站点账号
- **头像本地化**：云湖图片有防盗链，登录时下载到 `uploads/yunhu-avatars/`，站内不再 403
- **两种回调实现**：REST 路由（默认，地址无查询参数）/ `admin-post.php`（REST 被禁用时）
- **短代码**：`[yunhu_login]` 可放在任意页面
- **无令牌落地**：access token 仅用于拉一次用户信息，不写入数据库，不涉及刷新流程
- **可观测**：内置调试日志开关，请求/响应写 `error_log` 且自动脱敏

## 环境要求

| 项目 | 要求 |
|---|---|
| WordPress | 5.8 及以上 |
| PHP | 7.4 及以上 |
| 站点协议 | **生产环境必须 HTTPS**（云湖仅允许 `127.0.0.1` / `localhost` / `::1` 走 HTTP） |

## 安装

### 方式一：后台上传

1. 从 Releases 下载 `0.1.zip`（附件名即版本号，安装包内目录为 `wp-yunhu-oauth/`），或自行打包本目录
2. WordPress 后台 → 插件 → 安装插件 → 上传插件 → 选择 zip → 立即安装
3. 启用「云湖 OAuth2 登录」

### 方式二：手动部署

```bash
cd wp-content/plugins
git clone <repo-url> wp-yunhu-oauth
```

然后在后台启用插件。

## 配置

### 1. 创建云湖应用

打开云湖 OAuth2 授权机器人（机器人 ID `45781752`），按提示填写应用名称与回调配置，拿到 `client_id` 与 `client_secret`。

### 2. 登记回调地址

云湖按**完整 URL 精确匹配**，协议、主机、端口、路径、查询参数、末尾斜杠任一不同都会失败，长度上限 255 字节。

插件默认使用 REST 路由，请把下面这一行原样填进云湖应用配置（设置页顶部也会显示，可直接复制）：

```
https://你的域名/wp-json/yunhu-oauth/v1/callback
```

> 若站点禁用了 REST API（部分安全插件会拦截匿名 `/wp-json/` 访问），在设置页把「回调实现方式」改为 `admin-post.php`，并把新地址重新登记。

### 3. 填入凭据

「设置 → 云湖登录」填写 Client ID / Client Secret，保存。

推荐把密钥放进 `wp-config.php`，避免入库：

```php
define( 'YUNHU_OAUTH_CLIENT_ID', '你的 client id' );
define( 'YUNHU_OAUTH_CLIENT_SECRET', '你的 client secret' );
```

### 4. 验证

退出登录，在登录页点击「使用云湖账号登录」，走完授权后应自动登录并跳回原页面。

## 登录流程

```mermaid
sequenceDiagram
    autonumber
    participant U as 浏览器
    participant W as WordPress
    participant Y as 云湖 oauth2.jwzhd.com

    U->>W: 点击「使用云湖账号登录」
    W->>W: 生成 state + PKCE verifier，存入 10 分钟瞬态
    W-->>U: 302 → /oauth/authorize（带 code_challenge）
    U->>Y: 邮箱密码登录 + 同意授权
    Y-->>U: 302 → /wp-json/yunhu-oauth/v1/callback?code&state
    U->>W: GET 回调
    W->>W: 校验 state（hash_equals）并取出 verifier
    W->>Y: POST /oauth/token（code + verifier + client_secret）
    Y-->>W: access_token
    W->>Y: GET /api/userinfo（Bearer）
    Y-->>W: user_id / nickname / avatar_url
    W->>W: 按 user_id 建号或匹配，下载头像到本地
    W-->>U: Set-Cookie 登录态 + 302 回原页面
```

## 设置项

| 设置 | 默认 | 说明 |
|---|---|---|
| 启用 | 开 | 关闭后前端不显示入口，回调也会拒绝 |
| 登录页显示 | 开 | 在 `wp-login.php` 表单下方插入按钮 |
| 按钮文案 | 使用云湖账号登录 | 也可用短代码单独指定 |
| Client ID / Secret | — | 支持 `wp-config.php` 常量覆盖 |
| 授权端点 | `https://oauth2.jwzhd.com/oauth/authorize` | |
| Token 端点 | `https://oauth2.jwzhd.com/oauth/token` | `application/x-www-form-urlencoded` |
| 用户信息端点 | `https://oauth2.jwzhd.com/api/userinfo` | |
| Scope | `openid profile` | `openid` → `user_id`；`profile` → `nickname`、`avatar_url` |
| 客户端认证方式 | `client_secret_post` | 返回 `invalid_client` 时可试 `client_secret_basic` |
| 回调实现方式 | REST 路由 | 备选 `admin-post.php` |
| 自动注册 | 开 | 关闭则只允许已绑定用户登录 |
| 同步资料 | 开 | 每次登录同步昵称与头像 |
| 本地化头像 | 开 | 见下节 |
| 头像请求 Referer | `https://chat-go.jwzhd.com/` | 防盗链放行的域名 |
| 唯一 ID 字段名 | 空（自动识别） | 云湖返回 `user_id` |
| 调试日志 | 关 | 排查时开启，上线请关闭 |

## 头像与防盗链

云湖图片启用了防盗链，第三方站点直接 `<img src="云湖地址">` 会因 Referer 不符被 403。插件默认把头像下载到本地：

```
wp-content/uploads/yunhu-avatars/{md5(user_id|url)}.{png|jpg|webp|gif|avif}
```

- Referer 按顺序重试：设置值 → `https://chat-go.jwzhd.com/` → `https://www.yhchat.com/` → 图片自身域名 → 不带 Referer，**仅在 401/403 时**换下一个
- 用 `getimagesizefromstring` 校验真实图片字节（不信 `Content-Type`），上限 2 MB，走 `wp_safe_remote_get`（顺带阻断内网地址）
- 头像地址未变则复用本地文件；地址变化时重下并删除旧文件
- 下载失败不影响登录，退回远程地址，并在 `<img>` 上补 `referrerpolicy="no-referrer"`
- 解绑云湖账号时删除该用户的本地头像文件

## 短代码

```php
// 默认按钮
[yunhu_login]

// 自定义文案与登录后跳转
[yunhu_login label="用云湖登录" redirect="/account/" class="my-btn"]
```

已登录用户不会看到按钮。

## 钩子

### Filters

| 钩子 | 参数 | 用途 |
|---|---|---|
| `yunhu_oauth_settings` | `$settings` | 运行时覆盖任意设置 |
| `yunhu_oauth_authorize_args` | `$args` | 修改授权请求参数 |
| `yunhu_oauth_authorize_url` | `$url, $args` | 整体替换授权地址 |
| `yunhu_oauth_token_request_body` | `$body` | 修改换取令牌的表单体 |
| `yunhu_oauth_http_args` | `$args, $url, $method` | 调整请求（代理、超时、证书等） |
| `yunhu_oauth_raw_user` | `$data` | 处理 userinfo 原始返回 |
| `yunhu_oauth_user_profile` | `$profile, $raw` | 加工归一化后的资料 |
| `yunhu_oauth_avatar_request_args` | `$args, $url, $referer` | 调整头像下载请求 |
| `yunhu_oauth_avatar_referers` | `$candidates, $url` | 替换 Referer 候选链 |

### Actions

| 钩子 | 参数 | 触发时机 |
|---|---|---|
| `yunhu_oauth_user_created` | `$user_id, $profile` | 首次登录自动建号后 |
| `yunhu_oauth_login` | `$user_id, $profile` | 每次登录成功、跳转之前 |

示例——给新用户加前缀并落入自定义用户组：

```php
add_filter( 'yunhu_oauth_user_profile', function ( $profile, $raw ) {
	$profile['nickname'] = '云湖·' . $profile['nickname'];

	return $profile;
}, 10, 2 );

add_action( 'yunhu_oauth_user_created', function ( $user_id, $profile ) {
	update_user_meta( $user_id, 'member_group', 'yunhu' );
}, 10, 2 );
```

## 数据存储

| 用户元数据键 | 内容 |
|---|---|
| `yunhu_oauth_uid` | 云湖 `user_id`，账号关联的唯一依据 |
| `yunhu_oauth_avatar` | 云湖返回的原始头像地址 |
| `yunhu_oauth_avatar_file` | 本地缓存文件（相对 uploads 的路径） |
| `yunhu_oauth_avatar_source` | 生成缓存时的原始地址，用于判断是否需要重下 |
| `yunhu_oauth_managed` | 该账号由插件创建（只有这类账号会被同步覆盖昵称） |
| `yunhu_oauth_bound_at` | 绑定时间戳 |

选项：`yunhu_oauth_settings`（数组）。卸载时删除选项与残留瞬态，**保留用户绑定关系**，避免重装后账号失联。

## 安全设计

- `state` 通过 `hash_equals()` 比对，瞬态一次性读取并删除
- 回调参数除 `$_GET` 外还会扫描原始 `REQUEST_URI` 兜底（兼容平台拼参异常）
- 授权码兑换使用发起授权时保存的原始 `code_verifier`，绝不重新生成
- `redirect_to` 经 `wp_validate_redirect()`，外部跳转仅限授权端点
- Client Secret 永不回显到页面 HTML，留空即保持原值
- 不写入 access token / refresh token；不依赖昵称等可变字段做关联
- 云湖不返回邮箱，因此**不提供**「按邮箱合并账号」，避免邮箱未验证导致的账号接管

## 故障排查

| 现象 | 可能原因 | 处理 |
|---|---|---|
| 提示「登录状态校验失败」 | Cookie 被禁、切域名、授权页停留超过 10 分钟 | 重新点登录；检查站点 Cookie 设置 |
| 提示「换取访问令牌失败」 | Secret 错误 / 回调地址不一致 / 认证方式不符 | 开调试日志看 `error` 字段；`invalid_client` 时换 `client_secret_basic` |
| 提示「未拿到云湖用户 ID」 | scope 缺 `openid` | 保持 scope 为 `openid profile` |
| 回调 404 | REST API 被安全插件拦截 | 切换回调方式为 `admin-post.php` 并重新登记 |
| 授权时页面直接返回 400 JSON | 参数不合法（回调不匹配、PKCE 缺失等） | 核对登记的回调 URL 与插件显示的是否逐字符一致 |
| 头像不显示 | 防盗链未通过 | 看日志中「头像请求返回 HTTP xxx」；调整「头像请求 Referer」 |

云湖在参数不合法时会直接返回 HTTP 400 + JSON，不会跳回站点，因此这类问题只能从日志或云湖侧排查。

## 目录结构

```
wp-yunhu-oauth/
├── wp-yunhu-oauth.php                     # 插件入口、常量、激活钩子
├── includes/
│   ├── class-yunhu-oauth-settings.php     # 选项、Settings API、后台设置页
│   ├── class-yunhu-oauth-client.php       # 授权 URL、令牌交换、userinfo、字段映射、PKCE
│   ├── class-yunhu-oauth-avatar.php       # 头像下载、缓存、Referer 重试、清理
│   ├── class-yunhu-oauth-login.php        # state、回调、建号/绑定、登录、短代码
│   └── class-yunhu-oauth-profile.php      # 资料页绑定/解绑、头像输出
├── assets/css/yunhu-oauth.css
├── uninstall.php
├── LICENSE                                # AGPL-3.0 全文
├── readme.txt                             # WordPress 插件市场格式说明
└── README.md
```

## 开发

```bash
# 语法检查
find . -name "*.php" -exec php -l {} \;
```

代码遵循 WordPress Coding Standards 的命名与转义习惯（`esc_html` / `esc_url` / `esc_attr`），所有输入经 `sanitize_*` 处理，写库走 `update_option` / `update_user_meta`。

## 许可证

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)，全文见 [LICENSE](LICENSE)。

WordPress 自身为 "GPLv2 or later"，因此在 GPLv3 路径下可与 AGPL-3.0 组合分发。若你把这个插件并入闭源分发的产品，AGPL 的网络服务条款（第 13 条）会要求向通过网络交互的用户提供对应源码——商用前请确认这一点符合你的预期。

## 致谢

接口规范依据《云湖 OAuth2 接入文档（第一版）》。若云湖后续调整端点或字段，插件设置页可直接改端点，无需改代码；字段名变化可用「唯一 ID 字段名」等四个输入框或 `yunhu_oauth_user_profile` 过滤器适配。
