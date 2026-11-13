"""云湖 OAuth2 登录示例（Python 3.9+ / Flask）

启动：
    pip i -r requirements.txt
    YUNHU_CLIENT_ID=xxx YUNHU_CLIENT_SECRET=xxx \
    YUNHU_REDIRECT_URI=http://127.0.0.1:5000/callback python app.py
"""

import base64
import hashlib
import os
import secrets
import urllib.parse

import requests
from flask import Flask, redirect, request, session, url_for

AUTHORIZE = "https://oauth2.jwzhd.com/oauth/authorize"
TOKEN = "https://oauth2.jwzhd.com/oauth/token"
USERINFO = "https://oauth2.jwzhd.com/api/userinfo"

CLIENT_ID = os.environ.get("YUNHU_CLIENT_ID", "")
CLIENT_SECRET = os.environ.get("YUNHU_CLIENT_SECRET", "")
REDIRECT_URI = os.environ.get("YUNHU_REDIRECT_URI", "http://127.0.0.1:5000/callback")

app = Flask(__name__)
app.secret_key = os.environ.get("SESSION_SECRET") or secrets.token_hex(32)
app.config.update(
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
    # 生产环境（HTTPS）请改为 True
    SESSION_COOKIE_SECURE=os.environ.get("SESSION_COOKIE_SECURE") == "1",
)


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


@app.get("/login")
def login():
    state = secrets.token_urlsafe(32)          # 43 字符，满足 32–128
    verifier = secrets.token_urlsafe(48)

    session["yh"] = {"state": state, "verifier": verifier}

    challenge = b64url(hashlib.sha256(verifier.encode()).digest())
    params = {
        "response_type": "code",
        "client_id": CLIENT_ID,
        "redirect_uri": REDIRECT_URI,
        "scope": "openid profile",
        "state": state,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
    }
    query = "&".join(f"{k}={urllib.parse.quote(str(v), safe='')}" for k, v in params.items())
    return redirect(f"{AUTHORIZE}?{query}")


@app.get("/callback")
def callback():
    pending = session.pop("yh", None)
    state = request.args.get("state", "")

    if not pending or not state or not secrets.compare_digest(pending["state"], state):
        return "state 校验失败，请重新登录", 400

    if request.args.get("error"):
        denied = request.args.get("error") == "access_denied"
        return ("你取消了授权" if denied else "授权失败"), 400

    token_resp = requests.post(
        TOKEN,
        data={
            "grant_type": "authorization_code",
            "code": request.args.get("code", ""),
            "redirect_uri": REDIRECT_URI,
            "client_id": CLIENT_ID,
            "client_secret": CLIENT_SECRET,
            "code_verifier": pending["verifier"],
        },
        headers={"Accept": "application/json"},
        timeout=15,
    )
    token = token_resp.json()

    if not token.get("access_token"):
        app.logger.warning("token error %s %s", token_resp.status_code, token.get("error"))
        return "换取令牌失败", 502

    user_resp = requests.get(
        USERINFO,
        headers={
            "Authorization": f"Bearer {token['access_token']}",
            "Accept": "application/json",
        },
        timeout=15,
    )
    user = user_resp.json()

    if not user.get("user_id"):
        return "获取用户失败", 502

    session.clear()
    session["uid"] = user["user_id"]
    session["nickname"] = user.get("nickname", "")
    session["avatar"] = user.get("avatar_url", "")

    return redirect(url_for("index"))


@app.get("/")
def index():
    if not session.get("uid"):
        return '<p><a href="/login">使用云湖账号登录</a></p>'

    return (
        f"<p>已登录：{escape(session['nickname'])} ({escape(session['uid'])})</p>"
        + (f"<p><small>头像原始地址：{escape(session['avatar'])}</small></p>" if session.get("avatar") else "")
    )


def escape(value: str) -> str:
    return (
        str(value)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&#39;")
    )


if __name__ == "__main__":
    app.run(port=5000, debug=True)
