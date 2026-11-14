# Yunhu OAuth2 Login for WordPress

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](https://www.gnu.org/licenses/agpl-3.0.html)
[![Version](https://img.shields.io/badge/version-0.2.1-green)](readme.txt)

**English** · [简体中文](README.md)

Sign in to WordPress with a Yunhu (云湖) account, and give users a dedicated **My Account** page. Implements the OAuth 2.0 authorization-code flow with mandatory PKCE S256, provisions a local account from `user_id`, and syncs nickname plus avatar (including hotlink-protection handling).

> Unofficial community plugin. Not affiliated with Yunhu.

## Features

- Standard **authorization code** flow with **PKCE S256** (plain is not supported by the provider)
- **CSRF protection**: 64-character random `state`, bound to the browser session, single-use, 10-minute TTL
- **Auto registration** on first login with a configurable default role
- **Account linking**: users can link or unlink from their profile screen; one Yunhu account maps to exactly one WordPress user
- **Local avatar cache**: Yunhu images are hotlink-protected, so avatars are downloaded into `uploads/` during login
- **Two callback flavours**: REST route (default, no query string) or `admin-post.php` when REST is blocked
- **Bundled "my account" page** at `/my/`: avatar, account details, Yunhu binding, inline rename, unlink and logout
- **Lands on that page**: subscribers finish login (OAuth or password) on `/my/` instead of `wp-admin`
- **Shortcodes** `[yunhu_login]` for the button and `[yunhu_me]` to embed the panel anywhere
- **No token storage**: the access token is used once to fetch the profile and discarded; refresh tokens are not persisted
- **Debuggable**: optional request/response logging with automatic secret redaction

## Requirements

| Item | Requirement |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| Transport | **HTTPS is mandatory in production** (HTTP allowed only for `127.0.0.1`, `localhost`, `::1`) |

## Installation

1. Download `0.2.zip` from the releases page — the asset name is the version number; the archive unpacks to a `wp-yunhu-oauth/` folder.
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Install.
3. Activate **云湖 OAuth2 登录**.

Manual alternative:

```bash
cd wp-content/plugins
git clone https://github.com/liutexi/wp-yh-oauth.git wp-yunhu-oauth
```

## Configuration

### 1. Create the application

Open the Yunhu OAuth2 authorization bot (bot ID `45781752`) and register an app. You will receive a `client_id` and a `client_secret`.

### 2. Register the callback URL

The provider matches the **full callback URL byte for byte** — scheme, host, port, path, query string and trailing slash all matter, and the length limit is 255 bytes. Register exactly this value (the settings screen displays a copyable version):

```
https://your-domain.com/wp-json/yunhu-oauth/v1/callback
```

> If a security plugin blocks anonymous `/wp-json/` requests, switch **Callback handler** to `admin-post.php` on the settings screen and register the newly displayed URL instead.

### 3. Enter credentials

Settings → 云湖登录 (`Settings → Yunhu Login`). Storing the secret in `wp-config.php` is recommended:

```php
define( 'YUNHU_OAUTH_CLIENT_ID', 'your client id' );
define( 'YUNHU_OAUTH_CLIENT_SECRET', 'your client secret' );
```

### 4. Verify

Log out, click **使用云湖账号登录** on the login screen, approve the consent screen, and you should land on the **my account** page (`/my/` by default).

Also check `/my/` directly: signed out it should redirect to the login screen, and after signing in it should return you to `/my/`. A 404 means the rewrite rules are stale — see Troubleshooting.

## Flow

```mermaid
sequenceDiagram
    autonumber
    participant U as Browser
    participant W as WordPress
    participant Y as Yunhu oauth2.jwzhd.com

    U->>W: Click "Sign in with Yunhu"
    W->>W: Generate state + PKCE verifier, store in a 10-minute transient
    W-->>U: 302 to /oauth/authorize (code_challenge included)
    U->>Y: Email + password, approve consent
    Y-->>U: 302 to /wp-json/yunhu-oauth/v1/callback?code&state
    U->>W: GET callback
    W->>W: Validate state with hash_equals, load verifier
    W->>Y: POST /oauth/token (code + verifier + client_secret)
    Y-->>W: access_token
    W->>Y: GET /api/userinfo (Bearer)
    Y-->>W: user_id / nickname / avatar_url
    W->>W: Create or match the user by user_id, cache the avatar
    W-->>U: Auth cookie + 302 to the account page
```

## Key settings

| Setting | Default | Notes |
|---|---|---|
| Enabled | on | Turning it off hides the button and rejects callbacks |
| Show on login page | on | Injects the button into `wp-login.php` |
| Client ID / Secret | — | Overridable via `wp-config.php` constants |
| Authorize endpoint | `https://oauth2.jwzhd.com/oauth/authorize` | |
| Token endpoint | `https://oauth2.jwzhd.com/oauth/token` | `application/x-www-form-urlencoded` |
| Userinfo endpoint | `https://oauth2.jwzhd.com/api/userinfo` | |
| Scope | `openid profile` | `openid` → `user_id`, `profile` → `nickname` and `avatar_url` |
| Token auth method | `client_secret_post` | Try `client_secret_basic` on `invalid_client` |
| Callback handler | REST route | Fallback: `admin-post.php` |
| Account page | on | Registers the `/my/` route; `[yunhu_me]` keeps working when off |
| Page slug | `my` | Change to `account` for `/account/`; conflicts are reported on save |
| Redirect after login | on | Users without writing capability land on the account page; explicit `redirect_to` wins |
| Hide admin bar | off | Hides the toolbar for users without writing capability |
| Auto registration | on | When off, only pre-linked accounts may sign in |
| Sync profile | on | Refresh nickname and avatar on every login |
| Cache avatar locally | on | See below |
| Avatar Referer | `https://chat-go.jwzhd.com/` | Domain whitelisted by the CDN |
| Debug log | off | Enable while troubleshooting, disable afterwards |

## The account page

Everything a subscriber needs, on one front-end page. Default URL: `https://your-domain.com/my/`, slug configurable.

| Block | Contents |
|---|---|
| Identity | Avatar, display name, `@login`, role badge, Yunhu binding badge |
| Account | Username, display name, email, role, registration date, last Yunhu login |
| Yunhu | Yunhu `user_id`, nickname, link timestamp; unlink when bound, link when not |
| Display name | Inline rename (nonce-protected); warns when the value is overwritten by profile sync |
| Footer | Edit full profile, back home, log out |

How it works:

- The route is registered with `add_rewrite_rule` + `template_include`; **no page is created** and nothing is written to the posts table
- The active theme's `header.php` / `footer.php` are reused; block themes fall back to WordPress' bundled theme-compat header
- Signed-out visitors are redirected to the login screen and returned afterwards
- Sent with `noindex, noarchive` and `nocache_headers()` so it is neither indexed nor cached wholesale
- With plain permalinks the URL degrades to `?yunhu_me=1` instead of 404-ing
- **Not dependent on rewrite rules**: if the rule was never written (upgrades, migrations, hosts that rewrite `rewrite_rules`), the request path is matched instead — no 404 either way
- The settings screen shows whether the rule is present and offers a one-click **Flush rewrite rules** button
- Prefer your own page? Disable the route and place `[yunhu_me]` anywhere

Colours can be themed through CSS variables:

```css
.yunhu-me {
	--yh-primary: #0b5fff;
	--yh-radius: 8px;
	--yh-card: #fffdf7;
}
```

## Avatars & hotlink protection

Yunhu images reject foreign referrers, so avatars are cached under:

```
wp-content/uploads/yunhu-avatars/{md5(user_id|url)}.{png|jpg|webp|gif|avif}
```

- Referer is retried in order: configured value → `https://chat-go.jwzhd.com/` → `https://www.yhchat.com/` → the image's own origin → no Referer header, **only** on HTTP 401/403
- Download is validated with `getimagesizefromstring` (content bytes, not `Content-Type`), capped at 2 MB, and performed through `wp_safe_remote_get()` which also blocks private addresses
- An unchanged avatar URL reuses the cached file; a changed URL re-downloads and removes the old file
- Failure never blocks login — the remote URL is used instead and the `<img>` gets `referrerpolicy="no-referrer"`
- Unlinking the account deletes the cached file

## Shortcode

```php
[yunhu_login]
[yunhu_login label="Sign in with Yunhu" redirect="/account/" class="my-btn"]
```

Hidden for signed-in users.

```php
// The account panel, embeddable in any page or page builder
[yunhu_me]
```

`[yunhu_me]` renders exactly what `/my/` renders; signed-out visitors get a login prompt instead.

## Hooks

### Filters

| Hook | Arguments | Purpose |
|---|---|---|
| `yunhu_oauth_settings` | `$settings` | Override any setting at runtime |
| `yunhu_oauth_authorize_args` | `$args` | Modify authorization parameters |
| `yunhu_oauth_authorize_url` | `$url, $args` | Replace the authorize URL entirely |
| `yunhu_oauth_token_request_body` | `$body` | Modify the token request body |
| `yunhu_oauth_http_args` | `$args, $url, $method` | Adjust requests (proxy, timeout, TLS) |
| `yunhu_oauth_raw_user` | `$data` | Inspect the raw userinfo payload |
| `yunhu_oauth_user_profile` | `$profile, $raw` | Reshape the normalized profile |
| `yunhu_oauth_avatar_request_args` | `$args, $url, $referer` | Tune the avatar download |
| `yunhu_oauth_avatar_referers` | `$candidates, $url` | Replace the Referer chain |
| `yunhu_oauth_account_data` | `$data, $user` | Values rendered on the account page |

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `yunhu_oauth_user_created` | `$user_id, $profile` | After a new account is provisioned |
| `yunhu_oauth_login` | `$user_id, $profile` | After every successful login, before redirect |
| `yunhu_oauth_account_before` | `$user` | Before the account page content |
| `yunhu_oauth_account_sections` | `$user` | After the main blocks — append custom sections |

```php
add_filter( 'yunhu_oauth_user_profile', function ( $profile ) {
	$profile['nickname'] = 'Yunhu · ' . $profile['nickname'];

	return $profile;
} );
```

## Stored data

| User meta | Content |
|---|---|
| `yunhu_oauth_uid` | Yunhu `user_id` — the only account link |
| `yunhu_oauth_avatar` | Original avatar URL |
| `yunhu_oauth_avatar_file` | Cached file, relative to the uploads directory |
| `yunhu_oauth_avatar_source` | URL the cache was built from |
| `yunhu_oauth_managed` | Account was created by this plugin |
| `yunhu_oauth_bound_at` | Link timestamp |
| `yunhu_oauth_nickname` | Last synced Yunhu nickname |
| `yunhu_oauth_last_login` | Timestamp of the last Yunhu login |

Options: `yunhu_oauth_settings`, plus `yunhu_oauth_rules_marker` (rewrite signature used for self-healing; safe to delete). Uninstall removes options and stale transients but **keeps account links** so a reinstall does not orphan users.

## Security notes

- `state` compared with `hash_equals()`, transient read once and deleted
- Callback parameters fall back to scanning the raw `REQUEST_URI` for providers that mis-join query strings
- The token exchange reuses the original `code_verifier`; it is never regenerated
- `redirect_to` passes through `wp_validate_redirect()`; external redirects are limited to the authorize endpoint
- The client secret is never echoed into HTML; an empty field keeps the stored value
- No access or refresh tokens are written to the database
- Yunhu does not return an email address, so there is deliberately **no** email-based account merging

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "登录状态校验失败" (state mismatch) | Cookies blocked, domain changed, or consent screen left open over 10 minutes | Retry; check cookie settings |
| "换取访问令牌失败" (token exchange failed) | Wrong secret, mismatched callback, wrong auth method | Enable debug log; try `client_secret_basic` on `invalid_client` |
| "未拿到云湖用户 ID" (no user id) | Scope lacks `openid` | Keep scope at `openid profile` |
| Callback 404 | REST API blocked by a security plugin | Switch callback handler to `admin-post.php` and re-register |
| Authorize page returns raw 400 JSON | Invalid authorization request | Compare the registered callback with the one shown in settings |
| Avatar missing | Hotlink protection | Check the log for `头像请求返回 HTTP xxx`; adjust Avatar Referer |
| `/my/` returns 404 | Rewrite rule missing (upgrade, migration, or a host/security plugin rewriting `rewrite_rules`) | Check **Route status** on the settings screen and click **Flush rewrite rules**; since 0.2.1 the path is also matched directly, so the page works regardless |
| `/my/` shows another page | Slug collides with an existing page | Pick another slug — a warning is shown on save |
| Account page fights the theme CSS | Theme styles `dl`/`dt`/`dd` aggressively | Override within `.yunhu-me`, or use `[yunhu_me]` on your own page |

Invalid authorization parameters are rejected by the provider with a plain HTTP 400 JSON response — the browser never returns to the site, so those cases must be diagnosed from logs.

## Other platforms

Not running WordPress? [`docs/other-platforms/`](docs/other-platforms/README.md) covers the protocol at a glance, the seven-step integration, deployment notes, an error table — plus four runnable examples in `php/`, `node/`, `python/` and `go/`.

## Development

```bash
find . -name "*.php" -exec php -l {} \;
```

CI runs `php -l` on PHP 7.4 through 8.3 and verifies that the plugin header version, the `YUNHU_OAUTH_VERSION` constant and `readme.txt`'s `Stable tag` all match. Pushing a `v*` tag builds `wp-yunhu-oauth.zip` and attaches it to the GitHub release.

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html) — see [LICENSE](LICENSE).
