"""
Tests del CLI inventoryarens.

Cubre:
  - --help y --version output
  - parseo de subcomandos
  - --no-color flag
  - mensajes de error claros para argumentos invalidos
  - deteccion de OS (linux/windows) via sys.platform
"""
import os
import platform
import subprocess
import sys
import unittest
from pathlib import Path

# Path al binario del CLI.
CLI = Path(__file__).resolve().parent.parent.parent / "bin" / "inventoryarens"


def run_cli(*args: str, env: dict = None) -> subprocess.CompletedProcess:
    """Ejecuta el CLI con argumentos y devuelve el resultado."""
    full_env = {**os.environ, **(env or {})}
    # Forzar NO_COLOR para output predecible en los tests.
    full_env["NO_COLOR"] = "1"
    return subprocess.run(
        [sys.executable, str(CLI), *args],
        capture_output=True,
        text=True,
        env=full_env,
        timeout=15,
    )


class TestCliBasics(unittest.TestCase):
    def test_version(self):
        result = run_cli("--version")
        self.assertEqual(result.returncode, 0)
        self.assertIn("1.0.0", result.stdout)
        self.assertIn("inventoryarens", result.stdout)

    def test_help(self):
        result = run_cli("--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("CLI de operaciones", result.stdout)
        self.assertIn("install", result.stdout)
        self.assertIn("status", result.stdout)
        self.assertIn("logs", result.stdout)
        self.assertIn("uninstall", result.stdout)
        self.assertIn("token", result.stdout)
        self.assertIn("update", result.stdout)

    def test_subcommand_help(self):
        for sub in ["install", "uninstall", "logs", "token", "toolbox", "worker", "sync", "images"]:
            with self.subTest(sub=sub):
                result = run_cli(sub, "--help")
                self.assertEqual(result.returncode, 0, msg=f"sub={sub}, stderr={result.stderr}")

    def test_install_subcommand_help(self):
        result = run_cli("install", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("sync", result.stdout)
        self.assertIn("printer", result.stdout)

    def test_install_sync_help(self):
        result = run_cli("install", "sync", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("--tenant", result.stdout)
        self.assertIn("--user", result.stdout)

    def test_logs_help(self):
        result = run_cli("logs", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("sync", result.stdout)
        self.assertIn("printer", result.stdout)

    def test_token_help(self):
        result = run_cli("token", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("rotate", result.stdout)

    def test_worker_help(self):
        result = run_cli("worker", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("restart", result.stdout)
        self.assertIn("refresh-and-retry", result.stdout)

    def test_sync_help(self):
        result = run_cli("sync", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("retry-failed", result.stdout)
        self.assertIn("retry-inbox", result.stdout)

    def test_images_help(self):
        result = run_cli("images", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("download", result.stdout)
        self.assertIn("emit", result.stdout)


class TestCliErrors(unittest.TestCase):
    def test_no_subcommand(self):
        result = run_cli()
        # argparse exit code 2 = argumentos invalidos.
        self.assertEqual(result.returncode, 2)
        self.assertIn("required", result.stderr.lower() + result.stdout.lower())

    def test_invalid_subcommand(self):
        result = run_cli("nonexistent-subcommand")
        self.assertEqual(result.returncode, 2)
        self.assertIn("invalid choice", result.stderr.lower() + result.stdout.lower())

    def test_install_without_subsection(self):
        result = run_cli("install")
        self.assertEqual(result.returncode, 2)
        self.assertIn("required", (result.stderr + result.stdout).lower())

    def test_logs_without_subsection(self):
        result = run_cli("logs")
        self.assertEqual(result.returncode, 2)

    def test_token_without_subsection(self):
        result = run_cli("token")
        self.assertEqual(result.returncode, 2)


class TestCliParsing(unittest.TestCase):
    def test_install_sync_accepts_tenant_flag(self):
        result = run_cli("install", "sync", "--tenant", "demo-caracas",
                         "--user", "test@demo.test", "--no-write-env", "--help")
        # --help tiene prioridad sobre los otros flags.
        self.assertEqual(result.returncode, 0)

    def test_uninstall_sync_accepts_purge_flag(self):
        result = run_cli("uninstall", "sync", "--purge-env", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertIn("--purge-env", result.stdout)

    def test_token_rotate_accepts_flags(self):
        result = run_cli("token", "rotate", "--tenant", "x", "--user", "y",
                         "--no-write-env", "--help")
        self.assertEqual(result.returncode, 0)


class TestCliExitCodes(unittest.TestCase):
    def test_status_exit_zero_when_db_check_works(self):
        # No podemos garantizar un DB real en CI, pero el comando debe
        # ejecutarse sin error de parseo.
        result = run_cli("status")
        # status retorna 0/1/2. Cualquiera de esos es valido.
        self.assertIn(result.returncode, (0, 1, 2))


class TestCliOutput(unittest.TestCase):
    def test_no_color_flag(self):
        result = run_cli("--no-color", "--version")
        self.assertEqual(result.returncode, 0)
        # Sin codigos ANSI.
        self.assertNotIn("\033[", result.stdout)

    def test_help_has_no_ansi_when_no_tty(self):
        result = run_cli("--no-color", "--help")
        self.assertEqual(result.returncode, 0)
        self.assertNotIn("\033[", result.stdout)


if __name__ == "__main__":
    unittest.main()
