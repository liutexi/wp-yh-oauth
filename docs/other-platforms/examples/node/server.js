// 云湖 OAuth2 登录示例（Node.js 18+ / Express）
// 启动：
//   npm i && YUNHU_CLIENT_ID=xxx YUNHU_CLIENT_SECRET=xxx \
//   YUNHU_REDIRECT_URI=http://127.0.0.1:3000/callback node server.js

import crypto from 'node:crypto';
import express from 'express';
import session from 'express-session';

const AUTHORIZE = 'https://oauth2.jwzhd.com/oauth/authorize';
const TOKEN = 'https://oauth2.jwzhd.com/oauth/token';
const USERINFO = 'https://oauth2.jwzhd.com/api/userinfo';

const CLIENT_ID = process.env.YUNHU_CLIENT_ID ?? '';
const CLIENT_SECRET = process.env.YUNHU_CLIENT_SECRET ?? '';
const REDIRECT_URI = process.env.YUNHU_REDIRECT_URI ?? 'http://127.0.0.1:3000/callback';

const b64url = (buf) => Buffer.from(buf).toString('base64url');
const app = express();

app.use(
  session({
    secret: process.env.SESSION_SECRET ?? crypto.randomBytes(32).toString('hex'),
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production',
    },
  })
);

app.get('/login', (req, res) => {
  const state = crypto.randomBytes(32).toString('hex'); // 64 字符
  const verifier = b64url(crypto.randomBytes(48));

  req.session.yh = { state, verifier, expires: Date.now() + 600_000 };

  const url = new URL(AUTHORIZE);
  url.search = new URLSearchParams({
    response_type: 'code',
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    scope: 'openid profile',
    state,
    code_challenge: b64url(crypto.createHash('sha256').update(verifier).digest()),
    code_challenge_method: 'S256',
  }).toString();

  res.redirect(url.toString());
});

app.get('/callback', async (req, res) => {
  const pending = req.session.yh;
  req.session.yh = undefined;

  if (!pending || pending.expires < Date.now() || pending.state !== req.query.state) {
    return res.status(400).send('state 校验失败或已过期，请重新登录');
  }
  if (req.query.error) {
    return res.status(400).send(req.query.error === 'access_denied' ? '你取消了授权' : '授权失败');
  }

  try {
    const tokenRes = await fetch(TOKEN, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        code: String(req.query.code ?? ''),
        redirect_uri: REDIRECT_URI,
        client_id: CLIENT_ID,
        client_secret: CLIENT_SECRET,
        code_verifier: pending.verifier,
      }),
    });
    const token = await tokenRes.json();

    if (!token.access_token) {
      console.error('token error', tokenRes.status, token.error);
      return res.status(502).send('换取令牌失败');
    }

    const userRes = await fetch(USERINFO, {
      headers: { Authorization: `Bearer ${token.access_token}`, Accept: 'application/json' },
    });
    const user = await userRes.json();

    if (!user.user_id) {
      return res.status(502).send('获取用户失败');
    }

    req.session.regenerate((err) => {
      if (err) return res.status(500).send('会话错误');
      req.session.uid = user.user_id;
      req.session.nickname = user.nickname ?? '';
      req.session.avatar = user.avatar_url ?? '';
      res.redirect('/');
    });
  } catch (err) {
    console.error('oauth failed', err.message);
    res.status(502).send('登录失败');
  }
});

app.get('/', (req, res) => {
  const { uid, nickname, avatar } = req.session;
  if (!uid) {
    return res.send('<p><a href="/login">使用云湖账号登录</a></p>');
  }
  res.send(
    `<p>已登录：${escapeHtml(nickname)} (${escapeHtml(uid)})</p>` +
      (avatar ? `<p><small>头像原始地址：${escapeHtml(avatar)}</small></p>` : '')
  );
});

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));
}

app.listen(3000, () => console.log('http://127.0.0.1:3000'));
