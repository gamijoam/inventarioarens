import argparse
import posixpath
import sys

import paramiko


NGINX_TEMPLATE = """server {{
    listen 80;
    listen [::]:80;
    server_name {domain};
    root {project_path}/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {{
        try_files $uri $uri/ /index.php?$query_string;
    }}

    location = /favicon.ico {{ access_log off; log_not_found off; }}
    location = /robots.txt  {{ access_log off; log_not_found off; }}

    error_page 404 /index.php;

    location ~ ^/index\\.php(/|$) {{
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }}

    location ~ /\\.ht {{
        deny all;
    }}
}}
"""


def run(client: paramiko.SSHClient, command: str, timeout: int = 120) -> tuple[int, str, str]:
    stdin, stdout, stderr = client.exec_command(command, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    return code, out, err


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def main() -> int:
    parser = argparse.ArgumentParser(description="Configura un dominio Nginx para Laravel en el VPS.")
    parser.add_argument("--host", required=True)
    parser.add_argument("--user", default="root")
    parser.add_argument("--password", required=True)
    parser.add_argument("--domain", required=True)
    parser.add_argument("--project-path", default="/opt/inventarioarens-cloud")
    parser.add_argument("--email", default="")
    parser.add_argument("--skip-certbot", action="store_true")
    args = parser.parse_args()

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(args.host, username=args.user, password=args.password, timeout=25)

    try:
        checks = [
            ("nginx", "command -v nginx"),
            ("php-fpm", "test -S /run/php/php8.4-fpm.sock"),
            ("certbot", "command -v certbot"),
            ("proyecto", f"test -f {args.project_path}/public/index.php"),
        ]

        for label, command in checks:
            code, out, err = run(client, command)
            if code != 0:
                fail(f"No se pudo validar {label}. {err or out}")
            print(f"OK {label}")

        config = NGINX_TEMPLATE.format(domain=args.domain, project_path=args.project_path)
        remote_conf = f"/etc/nginx/sites-available/{args.domain}"
        remote_enabled = f"/etc/nginx/sites-enabled/{args.domain}"

        sftp = client.open_sftp()
        with sftp.file(remote_conf, "w") as fh:
            fh.write(config)
        sftp.close()
        print(f"OK configuracion escrita: {remote_conf}")

        link_command = (
            f"ln -sfn {remote_conf} {remote_enabled} "
            f"&& chown -R www-data:www-data {args.project_path}/storage {args.project_path}/bootstrap/cache "
            f"&& chmod -R ug+rw {args.project_path}/storage {args.project_path}/bootstrap/cache "
            f"&& nginx -t "
            f"&& systemctl reload nginx"
        )
        code, out, err = run(client, link_command)
        print(out)
        if code != 0:
            fail(err or out)
        print("OK Nginx recargado")

        if not args.skip_certbot:
            email_part = f"--email {args.email}" if args.email else "--register-unsafely-without-email"
            certbot_command = (
                f"certbot --nginx -d {args.domain} {email_part} "
                "--agree-tos --non-interactive --redirect"
            )
            code, out, err = run(client, certbot_command, timeout=180)
            print(out)
            if code != 0:
                fail(err or out)
            print("OK HTTPS configurado")

        code, out, err = run(client, f"curl -I -L --max-time 20 https://{args.domain}/api/sync/status")
        print(out)
        if err:
            print(err)
        print("Configuracion finalizada.")
    finally:
        client.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
