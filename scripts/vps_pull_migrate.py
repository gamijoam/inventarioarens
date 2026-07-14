"""VPS helper v3: pull, composer install, migrate nuevas."""
import sys
import paramiko

HOST = "217.216.80.158"
USER = "root"
PASSWORD = "GaboMac12"

def run(cmd, timeout=300):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASSWORD, timeout=10, banner_timeout=10, auth_timeout=10)
    try:
        print(f"\n=== $ {cmd}\n")
        stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        if out: print(out, end="")
        if err: print("STDERR:", err, file=sys.stderr, end="")
        print(f"--- exit code: {code}")
        return code, out, err
    finally:
        client.close()

if __name__ == "__main__":
    # Pull con rebase (stash .env primero para evitar conflicto).
    cmds = [
        "cd /opt/inventarioarens-cloud && git status --short",
        "cd /opt/inventarioarens-cloud && cp .env .env.bak.stash 2>/dev/null; git stash --include-untracked || true",
        "cd /opt/inventarioarens-cloud && cp .env.bak.stash .env 2>/dev/null; true",
        "cd /opt/inventarioarens-cloud && git fetch origin && git rebase origin/main 2>&1 | tail -10",
        "cd /opt/inventarioarens-cloud && git log --oneline -3",
        # Composer install si hace falta (cambio de dependencias).
        "cd /opt/inventarioarens-cloud && composer install --no-dev --no-interaction 2>&1 | tail -10",
        # Correr migraciones nuevas (las del 14 de julio).
        "cd /opt/inventarioarens-cloud && php artisan migrate --force 2>&1 | tail -30",
        # Seed roles.
        "cd /opt/inventarioarens-cloud && php artisan db:seed --class=RolesAndPermissionsSeeder --force 2>&1 | tail -5",
    ]
    for cmd in cmds:
        code, _, _ = run(cmd, timeout=300)
        if code != 0:
            print(f"[WARN] step non-zero: {cmd[:80]}")