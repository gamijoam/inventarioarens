"""SSH helper: ejecuta comandos via paramiko con autenticacion por password."""
import sys
import paramiko
import re

HOST = "217.216.80.158"
USER = "root"
PASSWORD = "GaboMac12"

def run(cmd, timeout=30):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASSWORD, timeout=10, banner_timeout=10, auth_timeout=10)
    try:
        stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        return code, out, err
    finally:
        client.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("uso: ssh_run.py <comando>")
        sys.exit(1)
    cmd = " ".join(sys.argv[1:])
    code, out, err = run(cmd)
    if out:
        print(out, end="")
    if err:
        print("STDERR:", err, file=sys.stderr, end="")
    sys.exit(code)