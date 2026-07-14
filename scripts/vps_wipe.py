"""VPS helper v2: cierra sesiones + wipe + migrate."""
import sys
import paramiko

HOST = "217.216.80.158"
USER = "root"
PASSWORD = "GaboMac12"

def run(cmd, timeout=120):
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

def close_sessions_and_wipe():
    """Cierra sesiones activas a inventory_arens, luego DROP + CREATE + migrate."""
    cmds = [
        # 1. Terminar conexiones activas.
        "sudo -u postgres psql -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='inventory_arens' AND pid <> pg_backend_pid();\"",
        # 2. DROP.
        "sudo -u postgres psql -c 'DROP DATABASE IF EXISTS inventory_arens;'",
        # 3. CREATE.
        "sudo -u postgres psql -c 'CREATE DATABASE inventory_arens OWNER postgres;'",
        # 4. Install PostGIS (si no lo esta).
        "sudo -u postgres psql -d inventory_arens -c 'CREATE EXTENSION IF NOT EXISTS postgis;' 2>&1 | tail -3",
        # 5. Migrate.
        "cd /opt/inventarioarens-cloud && php artisan migrate --force 2>&1 | tail -30",
        # 6. Seed roles.
        "cd /opt/inventarioarens-cloud && php artisan db:seed --class=RolesAndPermissionsSeeder --force 2>&1 | tail -5",
    ]
    for cmd in cmds:
        code, _, _ = run(cmd, timeout=300)
        if code != 0 and "CREATE EXTENSION" not in cmd:
            print(f"[FAIL] step failed: {cmd[:80]}")
            return False
    return True

if __name__ == "__main__":
    if not close_sessions_and_wipe():
        sys.exit(1)
    print("\n[OK] wipe + migrate completados")