"""sync_token.py - wrapper local que automatiza la obtencion de sync tokens.

Uso:
  python scripts/sync_token.py <tenant-slug> [opciones]

Opciones:
  --user <email>          Email del user (default: gabo@gabo.com)
  --print                  Solo imprime el token (no setea env var)
  --run                     Ademas corre php artisan sync:run <slug> despues de emitir
  --save-env <path>        Path al .env donde guardar el token (default: .env)
  --set-token-only         Solo actualizar SYNC_CLOUD_TOKEN, dejar todo lo demas igual

Ejemplos:
  python scripts/sync_token.py mi-empresa
  python scripts/sync_token.py grupo-prueba --user admin@local
  python scripts/sync_token.py mi-empresa --run

El script:
1. SSH al VPS (paramiko).
2. Ejecuta 'php artisan sync:ensure-and-token <slug> [opciones]'.
3. Parsea el token del output.
4. Lo imprime y opcionalmente lo escribe en .env del local.
"""
import argparse
import re
import sys
import paramiko

VPS_HOST = "217.216.80.158"
VPS_USER = "root"
VPS_PASSWORD = "GaboMac12"
LOCAL_CWD = r"C:\Users\gafit\Documents\INVENTARIOARENS"


def ssh_run(host, user, password, cmd, timeout=60):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, username=user, password=password, timeout=15, banner_timeout=15, auth_timeout=15)
    try:
        stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        return code, out, err
    finally:
        client.close()


def get_sync_token(tenant, user_email, host, user, password, node_name=None):
    """SSH al VPS y emite el sync token. Retorna (token, full_output)."""
    parts = ["php", "artisan", "sync:ensure-and-token", tenant, "--user=" + user_email]
    if node_name:
        parts.append("--node-name=" + node_name)
    # NOTA: NO usamos --silent porque queremos el output con el token.
    cmd = "cd /opt/inventarioarens-cloud && " + " ".join(parts) + " 2>&1"
    code, out, err = ssh_run(host, user, password, cmd, timeout=120)
    # Parsear token del output. El token viene en una linea "TOKEN=<valor>"
    # PERO con el comando artisan EnsureAndToken el token esta en una linea
    # tipo "  TOKEN=valor_largo". Buscamos la primera linea que tenga
    # "TOKEN=" seguida de contenido (no espacios).
    m = re.search(r"TOKEN=(\S+)", out)
    if not m:
        print(f"[ERROR] No se encontro TOKEN= en output (code={code})")
        print("STDOUT:", out)
        print("STDERR:", err)
        sys.exit(1)
    return m.group(1), out


def update_env_file(env_path, token, tenant):
    """Actualiza o inserta SYNC_CLOUD_TOKEN y SYNC_CLOUD_URL en .env."""
    import os
    env_path = os.path.abspath(env_path)
    if not os.path.exists(env_path):
        print(f"[WARN] .env no encontrado en {env_path}, saltando update")
        return
    with open(env_path, "r", encoding="utf-8") as f:
        lines = f.readlines()
    new_lines = []
    found_token = False
    found_url = False
    for line in lines:
        if line.startswith("SYNC_CLOUD_TOKEN="):
            new_lines.append(f"SYNC_CLOUD_TOKEN={token}\n")
            found_token = True
        elif line.startswith("SYNC_CLOUD_URL="):
            new_lines.append("SYNC_CLOUD_URL=https://app.miinventariofacil.com/api\n")
            found_url = True
        else:
            new_lines.append(line)
    if not found_token:
        new_lines.append(f"SYNC_CLOUD_TOKEN={token}\n")
    if not found_url:
        new_lines.append("SYNC_CLOUD_URL=https://app.miinventariofacil.com/api\n")
    with open(env_path, "w", encoding="utf-8") as f:
        f.writelines(new_lines)
    print(f"[OK] {env_path} actualizado (SYNC_CLOUD_TOKEN + SYNC_CLOUD_URL)")


def run_local_sync(tenant):
    """Corre php artisan sync:run <tenant> en el local."""
    import subprocess
    print(f"\n=== Ejecutando 'php artisan sync:run {tenant}' (local) ===")
    r = subprocess.run(
        ["php", "artisan", "sync:run", tenant],
        cwd=LOCAL_CWD,
        capture_output=True,
        text=True,
    )
    print(r.stdout[-2000:] if len(r.stdout) > 2000 else r.stdout)
    if r.returncode != 0:
        print("STDERR:", r.stderr[-1000:])
        return False
    return True


def main():
    p = argparse.ArgumentParser(
        description="Emite sync token desde el VPS y opcionalmente ejecuta sync local.",
    )
    p.add_argument("tenant", help="Slug del tenant (ej: mi-empresa, grupo-prueba)")
    p.add_argument("--user", default="gabo@gabo.com", help="Email del user (default: gabo@gabo.com)")
    p.add_argument("--node-name", default=None, help="Nombre visible del nodo")
    p.add_argument("--print", action="store_true", help="Solo imprime el token, no modifica .env")
    p.add_argument("--run", action="store_true", help="Ademas corre 'php artisan sync:run <slug>' en el local")
    p.add_argument("--save-env", default=None, help="Path al .env (default: <LOCAL_CWD>/.env)")
    args = p.parse_args()

    print(f"=== Conectando al VPS {VPS_HOST} ===")
    print(f"=== Solicitando sync token para tenant: {args.tenant} ===")
    token, raw = get_sync_token(args.tenant, args.user, VPS_HOST, VPS_USER, VPS_PASSWORD, args.node_name)
    print(f"\n[OK] Token emitido para '{args.tenant}':")
    print(f"  {token}")
    if args.print:
        return 0
    env_path = args.save_env or (LOCAL_CWD + "\\.env")
    update_env_file(env_path, token, args.tenant)
    if args.run:
        run_local_sync(args.tenant)
    print("\n[READY] Puedes correr 'php artisan sync:run " + args.tenant + "' localmente.")
    return 0


if __name__ == "__main__":
    sys.exit(main())