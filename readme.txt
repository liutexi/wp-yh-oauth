=== 云湖 OAuth2 登录 ===
Contributors: yunhu-oauth
Tags: oauth2, oauth, login, yunhu, 云湖, sso, 单点登录
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.3
License: AGPLv3
License URI: https://www.gnu.org/licenses/agpl-3.0.html

用云湖账号登录 WordPress，自带「我的」账号页面。基于云湖 OAuth2 授权码模式 + 强制 PKCE S256，支持自动注册、账号绑定、昵称头像同步。

== Description ==

对接云湖 OAuth2 服务（`https://oauth2.jwzhd.com`），实现「使用云湖账号登录」：

* 授权码模式（Authorization Code），强制 PKCE S256（云湖不支持 plain）
* state 为 64 位随机十六进制串，绑定浏览器会话，10 分钟过期、一次性消费
* 首次登录自动注册本地账号，可指定默认角色
* 老用户可在「个人资料」页手动绑定 / 解绑云湖账号
* 同步昵称与头像（头像下载到本地规避防盗链；仅覆盖插件创建的账号，不会改掉老用户自设的昵称）
* 自带「我的」账号页面（默认 `/my/`）：头像、账号信息、云湖绑定状态、改名、解绑、退出登录
* 订阅者登录后默认进入「我的」，不再被丢进 wp-admin
* 提供短代码 `[yunhu_login]` 与 `[yunhu_me]`，可在任意页面放置登录按钮或账号面板
* 内置调试日志开关，失败时可在 error_log 查看请求与响应（自动脱敏）

== 接口与参数（对应云湖 OAuth2 文档第一版）==

服务地址：`https://oauth2.jwzhd.com`

| 方法 | 地址 | 用途 |
|---|---|---|
| GET | `/oauth/authorize` | 浏览器授权 |
| POST | `/oauth/token` | 兑换 / 刷新令牌（form-urlencoded） |
| GET | `/api/userinfo` | 获取用户信息（Bearer） |

Scope：`openid` 返回 `user_id`，`profile` 返回 `nickname` 与 `avatar_url`，建议 `openid profile`（也是服务端默认值）。

用户信息结构：

```json
{ "user_id": "...", "nickname": "...", "avatar_url": "..." }
```

**云湖不返回邮箱**，因此本插件不提供「按邮箱合并账号」，账号关联一律使用 `user_id`，自动注册的账号邮箱为空。

== 头像与防盗链 ==

云湖的图片启用了防盗链，第三方站点直接 `<img src="云湖地址">` 会因 Referer 不符被 403 拒绝。插件默认开启「本地化头像」：

* 登录时由服务端把头像下载到 `wp-content/uploads/yunhu-avatars/`，站内输出的是本站地址，不受防盗链影响
* 下载时按顺序尝试 Referer：设置值 → `https://chat-go.jwzhd.com/`（云湖客户端域名）→ `https://www.yhchat.com/` → 图片自身域名 → 不带 Referer；遇到 401/403 自动换下一个，其它错误直接放弃
* 下载失败（403 / 超时 / 非图片）不影响登录，只是退回远程地址
* 退回远程地址时，头像 `<img>` 会自动补上 `referrerpolicy="no-referrer"`，部分 CDN 可借此放行
* 头像地址未变化则复用本地文件，不重复下载
* 解绑云湖账号时删除该用户的本地头像文件

关闭「本地化头像」后不再落盘，但站内头像很可能显示为裂图或空白。

== 「我的」页面 ==

登录（含云湖授权）完成后落地的账号页面，避免订阅者进入后台。

默认地址：`https://example.com/my/`（别名可在设置里改；站点若使用「朴素」固定链接，会自动降级为 `?yunhu_me=1`）

页面内容：

* 头像、显示名称、角色、已绑定/未绑定徽章
* 账号信息：用户名、显示名称、邮箱、角色、注册时间、上次云湖登录时间
* 云湖账号：用户 ID、云湖昵称、绑定时间，可直接解绑
* 修改显示名称（带 nonce；若该账号的显示名跟随云湖同步，会提示下次登录将被覆盖）
* 快捷入口：编辑完整资料、返回首页、退出登录

实现说明：

* 路由由插件注册，不生成页面、不写入文章表；主题的 `header.php` / `footer.php` 会被复用
* 未登录访问会跳转到登录页，登录后自动回到该页面
* 页面不对搜索引擎索引（`noindex, noarchive`），并发送 `nocache_headers()`
* 别名与现有页面冲突时，设置页会给出警告
* 不想用插件路由时，可在任意页面放短代码 `[yunhu_me]`，或关闭「启用页面」只保留短代码

== 回调地址规则 ==

云湖对**完整回调 URL** 做精确匹配，不是只校验域名：

* 协议、主机、端口、路径、查询参数、末尾斜杠任一不同都会失败
* 生产环境必须 HTTPS；HTTP 仅允许 `127.0.0.1`、`localhost`、`::1`
* 不允许 fragment（`#`）或 URL 中的用户名密码
* 完整地址最长 255 字节
* 授权请求与兑换令牌必须使用同一个原始回调地址

插件默认使用 REST 路由，地址不含查询参数，兼容性最好：

```
https://example.com/wp-json/yunhu-oauth/v1/callback
```

若站点禁用了 REST API（部分安全插件会拦截匿名 `/wp-json/` 访问），在设置页把「回调实现方式」切换为 `admin-post.php`，并把新显示的地址重新登记到云湖应用。

== Installation ==

1. 上传 `wp-yunhu-oauth` 目录到 `/wp-content/plugins/`，或后台上传 zip。
2. 启用插件。
3. 打开云湖 OAuth2 授权机器人（机器人 ID `45781752`）创建应用，获取 `client_id` / `client_secret`，并把插件设置页显示的回调地址原样登记。
4. 「设置 → 云湖登录」填入凭据，保存。
5. 退出登录，在登录页点「使用云湖账号登录」验证。

推荐把密钥放进 `wp-config.php`，避免入库：

    define( 'YUNHU_OAUTH_CLIENT_ID', '你的 client id' );
    define( 'YUNHU_OAUTH_CLIENT_SECRET', '你的 client secret' );

== FAQ ==

= 提示「登录状态校验失败」或「授权会话已过期」？ =

授权页停留超过 10 分钟，或换了域名导致 Cookie/瞬态对不上。重新点一次登录即可；若必现，检查站点是否禁止了 Cookie。

= 提示「换取访问令牌失败」？ =

依次核对：Client Secret 是否正确；登记的完整回调地址与当前显示的是否逐字符一致（含 https 与末尾斜杠）；Token 端点是否为 `/oauth/token`。打开调试日志可看到云湖返回的 `error` / `error_description`。

常见平台错误：`invalid_client`（应用标识或密钥错误）、`invalid_grant`（code 无效/过期/已使用，或 redirect_uri 不匹配）、`invalid_scope`。

= 提示「未拿到云湖用户 ID」？ =

通常是 scope 里没有 `openid`。保持 scope 为 `openid profile`，或在调试日志里看 userinfo 的返回字段，手工填入「唯一 ID 字段名」。

= 报错说回调地址不匹配 / 授权请求非法？ =

云湖在参数不合法时直接返回 HTTP 400 JSON，不会跳回站点。重点检查：登记的完整 URL 是否与设置页显示的一致、是否 HTTPS、PKCE 参数是否完整。

= 能否只允许已绑定用户登录？ =

关闭「自动注册」：未绑定的云湖账号会提示先登录站点、在个人资料页完成绑定。

= 令牌会被存下来吗？ =

不会。插件只用 access token 拉一次用户信息完成登录，不保存 access token 与 refresh token，因此也不涉及刷新流程。

== Changelog ==

= 0.2.3 =
* 修复：部分站点 `/my/` 仍然 404
  路由判断下沉到请求路径，WordPress 已判定 404 时也能接管并返回 200
  （此前依赖 parse_request 阶段的 query var，一旦该阶段被跳过就救不回来）
* template_redirect 优先级提到 1，避免 redirect_canonical 把 /my/ 重定向到首页或搜索结果页
* 设置页新增「运行路由自检」：服务端实请求 /my/ 与 ?yunhu_me=1，区分「请求没到 WordPress」和「插件没认出路」
* 路由状态区块显示插件版本与两个备用访问地址

= 0.2.2 =
* 修复：个人资料页点「绑定我的云湖账号」总是提示「绑定会话与当前登录的账号不一致」
  原因是 REST 回调在缺少 X-WP-Nonce 时会被 WordPress 当作未登录（当前用户被置 0），
  现在改为直接校验浏览器携带的登录 Cookie
* 云湖账号已被其他站点账号占用时，管理员能看到占用的账号名，便于定位
* 失败提示补充了「发起绑定的用户 / 当前会话用户」，便于排查

= 0.2.1 =
* 修复：部分环境下 `/my/` 返回 404
* 新增不依赖重写规则的兜底路由（按请求路径识别），重写规则未写入时页面依然可用
* 设置页新增「路由状态」诊断：显示固定链接结构、规则是否已写入，并提供「立即刷新伪静态规则」按钮
* 「我的」页面支持子目录安装与 `index.php/` 形式的固定链接

= 0.2 =
* 新增「我的」账号页面（默认 `/my/`），展示头像、账号信息与云湖绑定状态
* 页面内可直接解绑云湖账号、修改显示名称、退出登录
* 云湖授权与普通登录完成后，订阅者默认落到「我的」而不是 wp-admin
* 新增设置：启用页面、页面别名、登录后跳转、隐藏工具栏
* 伪静态规则自动检测刷新，从 0.1 升级后无需手动保存设置
* 新增短代码 `[yunhu_me]`，可把账号面板嵌入任意页面
* 新增 meta：`yunhu_oauth_nickname`、`yunhu_oauth_last_login`

= 0.1 =
* 首个公开版本
* 云湖 OAuth2 授权码登录，强制 PKCE S256，state 一次性校验（10 分钟过期）
* 首次登录自动注册本地账号，可指定默认角色
* 个人资料页绑定 / 解绑云湖账号，按 user_id 关联
* 昵称与头像同步；头像下载到 uploads 规避防盗链，带 Referer 重试链
* 支持 REST 与 admin-post 两种回调实现
* 短代码 `[yunhu_login]`、调试日志、完整的过滤器与动作钩子
