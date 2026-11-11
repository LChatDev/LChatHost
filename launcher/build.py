import os
from pathlib import Path
import subprocess

source = Path(__file__).resolve().parent
out = Path(os.environ.get("LCHAT_BUILD_OUT", source.parent.parent / "launcher-build" / "release"))
out.mkdir(parents=True, exist_ok=True)
subprocess.run(["git", "archive", "--format=zip", "--output="+str(source/"backend.zip"), "HEAD",
    ":(glob)*.php", ".htaccess", ".user.ini", "apis", "data", "files", ":(exclude)config.php", ":(exclude)apis/**/config.php"], cwd=source.parent, check=True)
for target in ("windows", "linux"):
    for language in ("en", "ru"):
        name = f"LChatHost-{target}-amd64-{language}" + (".exe" if target=="windows" else "")
        env = dict(os.environ, GOOS=target, GOARCH="amd64", CGO_ENABLED="0")
        subprocess.run(["go","build","-trimpath","-ldflags",f"-s -w -X main.language={language}",
                        "-o",str(out/name),"main.go"],cwd=source,env=env,check=True)
