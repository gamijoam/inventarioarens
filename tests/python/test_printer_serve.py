"""
Tests del printer agent (PrinterServer + PrinterServeCommand).

Cubre:
  - Binding a puerto valido / invalido
  - GET /health -> 200 con JSON
  - POST /print digital -> guarda archivo
  - POST /print thermal sin printer_name -> rechaza limpio
  - OPTIONS -> 204 (CORS preflight)
  - Unknown route -> 404
  - CORS headers presentes en todas las responses
"""
import http.client
import json
import os
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
ARTISAN = str(REPO_ROOT / "artisan")


def _free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def _start_server(port: int, max_requests: int = 1) -> subprocess.Popen:
    """Arranca `php artisan printer:serve` en un subprocess."""
    return subprocess.Popen(
        [PHP_BIN, ARTISAN, "printer:serve", f"--port={port}", f"--max-requests={max_requests}"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "NO_COLOR": "1"},
    )


PHP_BIN = os.environ.get("INVENTORYARENS_PHP", "php")


class TestPrinterServer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Verificar PHP disponible.
        try:
            subprocess.run([PHP_BIN, "--version"], capture_output=True, check=True, timeout=5)
        except (FileNotFoundError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
            raise unittest.SkipTest(f"PHP no disponible: {PHP_BIN}")

    def _run_server(self, max_requests: int = 10) -> tuple[subprocess.Popen, int]:
        port = _free_port()
        proc = _start_server(port, max_requests)
        # Esperar a que este listo.
        for _ in range(50):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            proc.kill()
            self.fail("Server no levanto en el puerto")
        return proc, port

    def _wait(self, proc: subprocess.Popen, timeout: int = 10) -> int:
        """Wrapper sobre proc.wait con timeout mas generoso que el default."""
        return proc.wait(timeout=timeout)

    def _http(self, port: int, method: str, path: str, body: dict | None = None) -> tuple[int, dict, dict]:
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
        # Forzar Connection: close para que el subprocess no quede esperando
        # keep-alive (sino el test se cuelga esperando el wait).
        payload = json.dumps(body).encode() if body is not None else b""
        headers = {
            "Content-Type": "application/json",
            "Connection": "close",
        } if body is not None else {"Connection": "close"}
        conn.request(method, path, body=payload, headers=headers)
        resp = conn.getresponse()
        data = json.loads(resp.read().decode() or "{}")
        out_headers = {k.lower(): v for k, v in resp.getheaders()}
        conn.close()
        return resp.status, data, out_headers

    def test_health_returns_ok(self):
        proc, port = self._run_server(max_requests=2)
        try:
            status, data, _ = self._http(port, "GET", "/health")
            self.assertEqual(status, 200)
            self.assertTrue(data.get("ok"))
            self.assertEqual(data.get("service"), "inventarioarens-printer-agent")
            self.assertEqual(data.get("port"), port)
        finally:
            self._wait(proc)

    def test_print_digital_saves_file(self):
        # max_requests=2: hace 1 request de /health (no en este test) o
        # /print. Usamos 2 por si el test agrega un /health warmup despues.
        proc, port = self._run_server(max_requests=2)
        tmpdir = tempfile.mkdtemp(prefix="invtkt_")
        try:
            status, data, _ = self._http(port, "POST", "/print", {
                "job_id": "test-1",
                "output": "digital",
                "station": {"digital_directory": tmpdir},
                "payload": {
                    "tenant": {"slug": "test", "name": "Test"},
                    "pos_order": {"id": 1, "customer_name": "Cliente Test"},
                    "totals": {"total_base_amount": 10.0, "paid_base_amount": 10.0},
                    "items": [{"product_name": "Prod", "quantity": 1, "unit_price": 10.0, "total": 10.0, "serials": []}],
                },
            })
            self.assertEqual(status, 200)
            self.assertEqual(data.get("status"), "generated")
            self.assertIn("pdf_path", data)
            self.assertTrue(Path(data["pdf_path"]).exists())
            self.assertEqual(Path(data["pdf_path"]).suffix, ".txt")
        finally:
            self._wait(proc)

    def test_print_thermal_without_printer_name_rejects(self):
        proc, port = self._run_server(max_requests=2)
        try:
            status, data, _ = self._http(port, "POST", "/print", {
                "job_id": "t-1",
                "output": "thermal",
                "station": {},
                "payload": {"tenant": {"name": "x"}, "pos_order": {"id": 1}},
            })
            self.assertEqual(status, 200)
            self.assertFalse(data.get("ok"))
            self.assertIn("printer_name", data.get("message", ""))
        finally:
            self._wait(proc)

    def test_options_returns_204(self):
        proc, port = self._run_server(max_requests=2)
        try:
            status, data, headers = self._http(port, "OPTIONS", "/health")
            self.assertEqual(status, 204)
            # CORS headers presentes.
            self.assertIn("access-control-allow-origin", headers)
        finally:
            self._wait(proc)

    def test_unknown_route_returns_404(self):
        proc, port = self._run_server(max_requests=2)
        try:
            status, data, _ = self._http(port, "GET", "/no-existe")
            self.assertEqual(status, 404)
            self.assertFalse(data.get("ok"))
        finally:
            self._wait(proc)

    def test_cors_headers_present(self):
        proc, port = self._run_server(max_requests=2)
        try:
            _, _, headers = self._http(port, "GET", "/health")
            self.assertEqual(headers.get("access-control-allow-origin"), "*")
            self.assertIn("GET", headers.get("access-control-allow-methods", ""))
        finally:
            self._wait(proc)


class TestPrinterServeCommand(unittest.TestCase):
    def test_help_text(self):
        proc = subprocess.run(
            [PHP_BIN, ARTISAN, "printer:serve", "--help"],
            capture_output=True, text=True, timeout=5,
        )
        self.assertEqual(proc.returncode, 0)
        self.assertIn("--port", proc.stdout)
        self.assertIn("17777", proc.stdout)

    def test_invalid_port_exits_nonzero(self):
        proc = subprocess.run(
            [PHP_BIN, ARTISAN, "printer:serve", "--port=10"],
            capture_output=True, text=True, timeout=5,
        )
        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("Puerto invalido", proc.stdout)


if __name__ == "__main__":
    unittest.main()
