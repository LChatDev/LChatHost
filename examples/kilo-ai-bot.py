#!/usr/bin/env python3
"""Self-hosted LChat AI bot powered by Kilo Auto Free."""
from __future__ import annotations

import json
import logging
import os
import time
from collections import defaultdict, deque
from pathlib import Path
from typing import Any

import requests

ROOT = Path(__file__).resolve().parent
STATE = ROOT / "state"
STATE.mkdir(exist_ok=True)
LOGS = ROOT / "logs"
LOGS.mkdir(exist_ok=True)


def load_env(path: Path) -> None:
    if not path.exists():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip())


load_env(ROOT / ".env")
TOKEN = os.environ.get("LCHAT_BOT_TOKEN", "").strip()
LCHAT_API = os.environ.get("LCHAT_API_URL", "https://chat.lbs.su/bot-api.php").strip()
KILO_URL = os.environ.get("KILO_BASE_URL", "https://ai-gateway.kilo.ai/api/v1").rstrip("/") + "/chat/completions"
KILO_MODEL = os.environ.get("KILO_MODEL", "kilo-auto/free").strip()
KILO_KEY = os.environ.get("KILO_API_KEY", "").strip()
OFFSET_FILE = STATE / "offset.json"
HISTORY_FILE = STATE / "history.json"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOGS / "aibot.log", encoding="utf-8"), logging.StreamHandler()],
)
http = requests.Session()
history: dict[str, list[dict[str, str]]] = {}
user_limits: dict[int, deque[float]] = defaultdict(deque)
global_limit: deque[float] = deque()


def read_json(path: Path, default: Any) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


def write_json(path: Path, value: Any) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False), encoding="utf-8")
    temporary.replace(path)


def lchat(method: str, data: dict[str, Any] | None = None, params: dict[str, Any] | None = None) -> Any:
    headers = {"Authorization": f"Bearer {TOKEN}", "X-LChat-Bot-Token": TOKEN}
    query = {"method": method, **(params or {})}
    response = http.get(LCHAT_API, params=query, headers=headers, timeout=30) if data is None else http.post(LCHAT_API, params=query, json=data, headers=headers, timeout=30)
    payload = response.json()
    if response.status_code >= 400 or not payload.get("ok"):
        raise RuntimeError(f"LChat {method}: {payload.get('description', response.status_code)}")
    return payload.get("result")


def allowed(user_id: int) -> bool:
    now = time.time()
    own = user_limits[user_id]
    while own and own[0] < now - 600:
        own.popleft()
    while global_limit and global_limit[0] < now - 3600:
        global_limit.popleft()
    if len(own) >= 15 or len(global_limit) >= 180:
        return False
    own.append(now)
    global_limit.append(now)
    return True


def ask_kilo(chat_id: int, text: str) -> str:
    key = str(chat_id)
    messages = history.setdefault(key, [])
    messages.append({"role": "user", "content": text[:12000]})
    messages[:] = messages[-20:]
    total = 0
    trimmed: list[dict[str, str]] = []
    for item in reversed(messages):
        total += len(item["content"].encode("utf-8"))
        if total > 30000:
            break
        trimmed.append(item)
    trimmed.reverse()
    headers = {"Content-Type": "application/json", "Accept": "application/json", "User-Agent": "LChat-AiBot/2.0"}
    if KILO_KEY:
        headers["Authorization"] = f"Bearer {KILO_KEY}"
    response = http.post(KILO_URL, headers=headers, json={
        "model": KILO_MODEL,
        "messages": [{
            "role": "system",
            "content": "Ты @AiBot в LChat. Отвечай на языке пользователя, полезно и по существу. Можно использовать Markdown. Не выдумывай выполненные действия. Не проси пароли или токены; если пользователь прислал секрет, посоветуй заменить его. Ответы бесплатной модели могут содержать ошибки.",
        }, *trimmed],
        "temperature": 0.35,
        "max_tokens": 4000,
        "stream": False,
    }, timeout=180)
    try:
        payload = response.json()
    except ValueError as exc:
        raise RuntimeError(f"Kilo returned non-JSON, HTTP {response.status_code}") from exc
    if response.status_code >= 400:
        raise RuntimeError("Kilo: " + str(payload.get("error", {}).get("message", response.status_code))[:300])
    answer = str(payload.get("choices", [{}])[0].get("message", {}).get("content", "")).strip()
    if not answer:
        raise RuntimeError("Kilo returned an empty answer")
    answer = answer[:30000]
    messages.append({"role": "assistant", "content": answer})
    messages[:] = messages[-20:]
    write_json(HISTORY_FILE, history)
    return answer


def handle(message: dict[str, Any], own_id: int) -> None:
    sender = message.get("from") or {}
    if sender.get("is_bot") or int(sender.get("id", 0)) == own_id:
        return
    chat_id = int((message.get("chat") or {}).get("id", 0))
    user_id = int(sender.get("id", 0))
    text = str(message.get("text") or "").strip()
    if not chat_id or not user_id or not text:
        return
    command = text.split("@", 1)[0].lower()
    if command in {"/start", "/help"}:
        answer = "Привет! Я @AiBot и работаю через Kilo Auto Free на отдельном компьютере. Просто отправьте вопрос.\n\n/reset — очистить контекст\n/help — помощь\n\nНе отправляйте пароли, токены и закрытые данные."
    elif command in {"/reset", "/clear"}:
        history.pop(str(chat_id), None)
        write_json(HISTORY_FILE, history)
        answer = "Контекст очищен. Можете начать новую тему."
    elif not allowed(user_id):
        answer = "Слишком много запросов или бесплатный лимит Kilo временно исчерпан. Попробуйте позже."
    else:
        try:
            answer = ask_kilo(chat_id, text)
        except Exception as exc:
            logging.warning("Kilo request failed: %s", exc)
            answer = "Не удалось получить ответ от Kilo. Попробуйте ещё раз немного позже."
    lchat("sendMessage", {"chat_id": chat_id, "text": answer})


def main() -> None:
    if not TOKEN:
        raise SystemExit("Set LCHAT_BOT_TOKEN in .env")
    global history
    loaded = read_json(HISTORY_FILE, {})
    history = loaded if isinstance(loaded, dict) else {}
    me = lchat("getMe")
    own_id = int(me["id"])
    offset = int(read_json(OFFSET_FILE, {}).get("offset", 0))
    logging.info("Connected as @%s; model=%s", me.get("username"), KILO_MODEL)
    while True:
        try:
            updates = lchat("getUpdates", params={"offset": offset, "limit": 50}) or []
            for update in updates:
                offset = max(offset, int(update.get("update_id", 0)) + 1)
                write_json(OFFSET_FILE, {"offset": offset})
                if isinstance(update.get("message"), dict):
                    handle(update["message"], own_id)
            time.sleep(1.5 if updates else 3)
        except KeyboardInterrupt:
            return
        except Exception as exc:
            logging.warning("Polling failed: %s", exc)
            time.sleep(5)


if __name__ == "__main__":
    main()
