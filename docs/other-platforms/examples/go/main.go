// 云湖 OAuth2 登录示例（Go 1.21+，仅标准库）
//
// 运行：
//   YUNHU_CLIENT_ID=xxx YUNHU_CLIENT_SECRET=xxx \
//   YUNHU_REDIRECT_URI=http://127.0.0.1:8080/callback go run main.go
//
// 注意：这里用进程内 map 保存 pending state，只适合单实例演示；
// 多实例部署请换成 Redis / 数据库或加密 Cookie。
package main

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"html"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"
)

const (
	authorizeURL = "https://oauth2.jwzhd.com/oauth/authorize"
	tokenURL     = "https://oauth2.jwzhd.com/oauth/token"
	userinfoURL  = "https://oauth2.jwzhd.com/api/userinfo"
)

type pending struct {
	verifier string
	expires  time.Time
}

var (
	clientID     = os.Getenv("YUNHU_CLIENT_ID")
	clientSecret = os.Getenv("YUNHU_CLIENT_SECRET")
	redirectURI  = envOr("YUNHU_REDIRECT_URI", "http://127.0.0.1:8080/callback")

	mu       sync.Mutex
	sessions = map[string]pending{}
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func randomHex(n int) string {
	return hex.EncodeToString(randomBytes(n))
}

func b64url(b []byte) string {
	return strings.TrimRight(base64.URLEncoding.EncodeToString(b), "=")
}

func main() {
	http.HandleFunc("/login", handleLogin)
	http.HandleFunc("/callback", handleCallback)
	http.HandleFunc("/", handleIndex)

	log.Println("listen :8080")
	log.Fatal(http.ListenAndServe(":8080", nil))
}

func handleLogin(w http.ResponseWriter, r *http.Request) {
	state := randomHex(32) // 64 字符

	verifier := b64url(randomBytes(48))
	challenge := sha256.Sum256([]byte(verifier))

	mu.Lock()
	sessions[state] = pending{verifier: verifier, expires: time.Now().Add(10 * time.Minute)}
	mu.Unlock()

	q := url.Values{
		"response_type":         {"code"},
		"client_id":             {clientID},
		"redirect_uri":          {redirectURI},
		"scope":                 {"openid profile"},
		"state":                 {state},
		"code_challenge":        {b64url(challenge[:])},
		"code_challenge_method": {"S256"},
	}

	http.Redirect(w, r, authorizeURL+"?"+q.Encode(), http.StatusFound)
}

func handleCallback(w http.ResponseWriter, r *http.Request) {
	query := r.URL.Query()
	state := query.Get("state")

	mu.Lock()
	p, ok := sessions[state]
	delete(sessions, state)
	mu.Unlock()

	if !ok || time.Now().After(p.expires) {
		http.Error(w, "state 校验失败或已过期，请重新登录", http.StatusBadRequest)
		return
	}

	if e := query.Get("error"); e != "" {
		msg := "授权失败"
		if e == "access_denied" {
			msg = "你取消了授权"
		}
		http.Error(w, msg, http.StatusBadRequest)
		return
	}

	form := url.Values{
		"grant_type":    {"authorization_code"},
		"code":          {query.Get("code")},
		"redirect_uri":  {redirectURI},
		"client_id":     {clientID},
		"client_secret": {clientSecret},
		"code_verifier": {p.verifier},
	}

	resp, err := http.PostForm(tokenURL, form)
	if err != nil {
		log.Printf("token request failed: %v", err)
		http.Error(w, "换取令牌失败", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	var token struct {
		AccessToken string `json:"access_token"`
	}
	_ = json.NewDecoder(resp.Body).Decode(&token)
	if token.AccessToken == "" {
		log.Printf("token endpoint returned %d", resp.StatusCode)
		http.Error(w, "换取令牌失败", http.StatusBadGateway)
		return
	}

	req, _ := http.NewRequest(http.MethodGet, userinfoURL, nil)
	req.Header.Set("Authorization", "Bearer "+token.AccessToken)
	req.Header.Set("Accept", "application/json")

	uresp, err := http.DefaultClient.Do(req)
	if err != nil {
		http.Error(w, "获取用户失败", http.StatusBadGateway)
		return
	}
	defer uresp.Body.Close()

	var user struct {
		UserID   string `json:"user_id"`
		Nickname string `json:"nickname"`
		Avatar   string `json:"avatar_url"`
	}
	_ = json.NewDecoder(uresp.Body).Decode(&user)
	if user.UserID == "" {
		http.Error(w, "获取用户失败", http.StatusBadGateway)
		return
	}

	// 生产环境：在这里签发你自己的会话 Cookie，而不是直接输出。
	fmt.Fprintf(
		w,
		"<p>已登录：%s (%s)</p>",
		html.EscapeString(user.Nickname),
		html.EscapeString(user.UserID),
	)
}

func handleIndex(w http.ResponseWriter, r *http.Request) {
	io.WriteString(w, `<p><a href="/login">使用云湖账号登录</a></p>`)
}

func randomBytes(n int) []byte {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return b
}
