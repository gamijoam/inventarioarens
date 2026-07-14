"""Quick VPS status check."""
import sys
import paramiko

HOST = "217.216.80.158"
USER = "root"
PASSWORD = "GaboMac12"

def run(cmd, timeout=60):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASSWORD, timeout=10, banner_timeout=10, auth_timeout=10)
    try:
        stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        if out: sys.stdout.write(out)
        if err: sys.stderr.write(err)
        sys.stdout.flush(); sys.stderr.flush()
        return code
    finally:
        client.close()

if __name__ == "__main__":
    cmds = [
        "systemctl is-active inventarioarens-sync.timer 2>&1",
        "systemctl list-timers --all 2>&1 | grep -E 'sync|TIMER' || true",
        "ls -la /etc/systemd/system/inventarioarens-sync* 2>&1",
        "cd /opt/inventarioarens-cloud && php artisan sync:apply-inbox --limit=10 2>&1 | tail -5",
        "cd /opt/inventarioarens-cloud && php artisan tinker --execute='echo \\App\\Modules\\Sync\\Models\\SyncNode::count() . \" nodes, \" . \\App\\Modules\\Sync\\Models\\SyncInbox::count() . \" inbox\"' 2>&1 | tail -3",
    ]
    for cmd in cmds:
        sys.stdout.write(f"\n=== $ {cmd}\n")
        sys.stdout.flush()
        run(cmd, timeout=60)