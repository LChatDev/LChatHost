package main

import (
	"archive/zip"
	"bytes"
	"crypto/rand"
	"embed"
	"encoding/hex"
	"fmt"
	"html/template"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"
)

//go:embed backend.zip Dockerfile compose.yaml host-config.php panel.html .dockerignore
var bundle embed.FS
var language = "en"
var dataDir, panelToken string
var mutex sync.Mutex
var busy bool
var lastLog string
var validToken = regexp.MustCompile("^[A-Za-z0-9_+=/-]*$")
var tunnelURL = regexp.MustCompile("https://[a-z0-9-]+\\.trycloudflare\\.com")

func randomKey() string {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func dockerCommand(args ...string) *exec.Cmd {
	path, err := exec.LookPath("docker")
	if err != nil && runtime.GOOS == "windows" {
		path = filepath.Join(os.Getenv("ProgramFiles"), "Docker", "Docker", "resources", "bin", "docker.exe")
	} else if err != nil {
		path = "docker"
	}
	c := exec.Command(path, args...)
	c.Dir = dataDir
	return c
}

func run(c *exec.Cmd) error {
	output, err := c.CombinedOutput()
	mutex.Lock()
	defer mutex.Unlock()
	lastLog = string(output)
	if err != nil {
		lastLog += "\n" + err.Error()
	}
	return err
}

func settings() map[string]string {
	result := map[string]string{}
	content, _ := os.ReadFile(filepath.Join(dataDir, "settings.env"))
	for _, line := range strings.Split(string(content), "\n") {
		key, value, ok := strings.Cut(line, "=")
		if ok {
			result[key] = value
		}
	}
	return result
}

func setupSite() error {
	for _, name := range []string{"Dockerfile", "compose.yaml", "host-config.php", ".dockerignore"} {
		contents, err := bundle.ReadFile(name)
		if err != nil {
			return err
		}
		if err = os.WriteFile(filepath.Join(dataDir, name), contents, 0600); err != nil {
			return err
		}
	}
	blob, err := bundle.ReadFile("backend.zip")
	if err != nil {
		return err
	}
	archive, err := zip.NewReader(bytes.NewReader(blob), int64(len(blob)))
	if err != nil {
		return err
	}
	for _, entry := range archive.File {
		if entry.FileInfo().IsDir() {
			continue
		}
		if !filepath.IsLocal(filepath.FromSlash(entry.Name)) {
			return fmt.Errorf("invalid archive path")
		}
		dest := filepath.Join(dataDir, "site", filepath.FromSlash(entry.Name))
		if err = os.MkdirAll(filepath.Dir(dest), 0700); err != nil {
			return err
		}
		input, err := entry.Open()
		if err != nil {
			return err
		}
		contents, err := io.ReadAll(input)
		input.Close()
		if err != nil {
			return err
		}
		if err = os.WriteFile(dest, contents, 0600); err != nil {
			return err
		}
	}
	return nil
}

func installDocker() error {
	if _, err := dockerCommand("compose", "version").Output(); err == nil {
		return nil
	}
	if runtime.GOOS == "windows" {
		return run(exec.Command("winget", "install", "--id", "Docker.DockerDesktop", "--exact", "--accept-package-agreements", "--accept-source-agreements"))
	}
	// Ubuntu package. Existing Docker installations are detected above.
	if err := run(exec.Command("sudo", "apt-get", "update")); err != nil {
		return err
	}
	return run(exec.Command("sudo", "apt-get", "install", "-y", "docker.io", "docker-compose-v2"))
}

func launchBrowser(address string) {
	if runtime.GOOS == "windows" {
		_ = exec.Command("rundll32.exe", "url.dll,FileProtocolHandler", address).Start()
	} else {
		_ = exec.Command("xdg-open", address).Start()
	}
}

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--version" {
		fmt.Println("LChatHost Panel 1.0.0", language, runtime.GOOS, runtime.GOARCH)
		return
	}
	root, err := os.UserConfigDir()
	if err != nil {
		log.Fatal(err)
	}
	dataDir = filepath.Join(root, "LChatHostPanel")
	if err = os.MkdirAll(dataDir, 0700); err != nil {
		log.Fatal(err)
	}
	if len(os.Args) == 2 && os.Args[1] == "--install-docker" {
		if err = installDocker(); err != nil {
			log.Fatal(err, lastLog)
		}
		fmt.Println(lastLog)
		return
	}
	if err = setupSite(); err != nil {
		log.Fatal(err)
	}
	panelToken = randomKey()
	panel, err := template.ParseFS(bundle, "panel.html")
	if err != nil {
		log.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		log.Fatal(err)
	}
	host := listener.Addr().String()
	path := "/" + panelToken + "/"
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != host || r.URL.Path != path {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Referrer-Policy", "no-referrer")
		w.Header().Set("X-Frame-Options", "DENY")
		if r.Method == "GET" {
			mutex.Lock()
			logText, working, cfg := lastLog, busy, settings()
			mutex.Unlock()
			_ = panel.Execute(w, map[string]any{"RU": language == "ru", "Busy": working, "Log": logText, "QuickURL": tunnelURL.FindString(logText), "Config": cfg, "Windows": runtime.GOOS == "windows"})
			return
		}
		if r.Method != "POST" || r.Header.Get("Origin") != "http://"+host {
			http.Error(w, "Forbidden", 403)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 8192)
		if err := r.ParseForm(); err != nil {
			http.Error(w, "Invalid form", 400)
			return
		}
		mutex.Lock()
		if busy {
			mutex.Unlock()
			http.Redirect(w, r, path, 303)
			return
		}
		busy = true
		mutex.Unlock()
		go func() {
			defer func() { mutex.Lock(); busy = false; mutex.Unlock() }()
			if err := action(r.PostForm); err != nil {
				mutex.Lock()
				lastLog += "\n" + err.Error()
				mutex.Unlock()
			}
		}()
		http.Redirect(w, r, path, 303)
	})
	address := "http://" + host + path
	fmt.Println(address)
	if os.Getenv("LCHAT_NO_BROWSER") != "1" {
		launchBrowser(address)
	}
	log.Fatal((&http.Server{Handler: handler, ReadHeaderTimeout: 5 * time.Second}).Serve(listener))
}

func action(form url.Values) error {
	args := []string{"compose", "--env-file", "settings.env", "-p", "lchathost-panel"}
	compose := func(extra ...string) error {
		return run(dockerCommand(append(append([]string{}, args...), extra...)...))
	}
	switch form.Get("action") {
	case "install":
		if runtime.GOOS != "windows" {
			return fmt.Errorf("Run --install-docker from a terminal / Запустите --install-docker в терминале")
		}
		return installDocker()
	case "start":
		web, err := url.Parse(form.Get("web"))
		if err != nil || web.Scheme != "https" || web.Hostname() == "" || web.User != nil || strings.ContainsAny(form.Get("web"), " \t\r\n$#\\\"'") {
			return fmt.Errorf("Enter the HTTPS LChatWeb URL / Укажите HTTPS-адрес LChatWeb")
		}
		token, turn, secret := form.Get("token"), form.Get("turn"), form.Get("turnSecret")
		if !validToken.MatchString(token) || !validToken.MatchString(secret) || strings.ContainsAny(turn, " \t\r\n$#\\\"'") {
			return fmt.Errorf("Invalid tunnel or TURN settings / Неверные настройки туннеля или TURN")
		}
		cfg := settings()
		for _, key := range []string{"DB_PASSWORD", "INSTALL_KEY", "PROXY_SECRET"} {
			if cfg[key] == "" {
				cfg[key] = randomKey()
			}
		}
		content := fmt.Sprintf("DB_PASSWORD=%s\nINSTALL_KEY=%s\nPROXY_SECRET=%s\nWEB_URL=%s\nTUNNEL_TOKEN=%s\nTURN_URL=%s\nTURN_SECRET=%s\n", cfg["DB_PASSWORD"], cfg["INSTALL_KEY"], cfg["PROXY_SECRET"], web.String(), token, turn, secret)
		if err = os.WriteFile(filepath.Join(dataDir, "settings.env"), []byte(content), 0600); err != nil {
			return err
		}
		return compose("up", "-d", "--build", "backend")
	case "quick", "named":
		which := form.Get("action")
		if which == "named" && settings()["TUNNEL_TOKEN"] == "" {
			return fmt.Errorf("Save the tunnel token first / Сначала сохраните токен туннеля")
		}
		if err := compose("--profile", "quick", "--profile", "named", "stop", "quick", "named"); err != nil {
			return err
		}
		if err := compose("--profile", which, "up", "-d", which); err != nil {
			return err
		}
		time.Sleep(4 * time.Second)
		return compose("--profile", which, "logs", "--tail", "40", which)
	case "status":
		return compose("--profile", "quick", "--profile", "named", "ps", "-a")
	case "quicklogs", "namedlogs", "backendlogs":
		service := strings.TrimSuffix(form.Get("action"), "logs")
		return compose("--profile", "quick", "--profile", "named", "logs", "--tail", "60", service)
	case "stop":
		return compose("--profile", "quick", "--profile", "named", "stop")
	default:
		return fmt.Errorf("Unknown action")
	}
}
