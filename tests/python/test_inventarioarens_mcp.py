import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path


SERVER = Path(__file__).resolve().parents[2] / "tools" / "mcp" / "inventarioarens_mcp_server.py"


def load_server_module():
    spec = importlib.util.spec_from_file_location("inventarioarens_mcp_server_under_test", SERVER)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class InventarioArensMcpSecurityTest(unittest.TestCase):
    def setUp(self):
        self.module = load_server_module()

    def test_validates_tenant_slug(self):
        self.assertEqual(self.module.validate_tenant_slug("demo-caracas"), "demo-caracas")
        self.assertEqual(self.module.validate_tenant_slug("mi_empresa_01"), "mi_empresa_01")
        with self.assertRaises(ValueError):
            self.module.validate_tenant_slug("../inventory_arens")
        with self.assertRaises(ValueError):
            self.module.validate_tenant_slug("Demo Caracas")

    def test_blocks_non_select_sql(self):
        self.assertTrue(self.module.is_select_sql("select * from tenants"))
        self.assertTrue(self.module.is_select_sql("with x as (select 1) select * from x"))
        self.assertFalse(self.module.is_select_sql("update products set name = 'x'"))
        self.assertFalse(self.module.is_select_sql("delete from products"))
        self.assertFalse(self.module.is_select_sql("select * from tenants; drop table products"))

    def test_resolve_project_path_stays_inside_root(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            settings = self.module.Settings(
                project_root=Path(temp_dir).resolve(),
                access_key="secret",
                db_host="127.0.0.1",
                db_port=5432,
                db_name="inventory_arens",
                db_user="postgres",
                db_password="secret",
                host="127.0.0.1",
                port=17888,
            )

            path = self.module.resolve_project_path(settings, "app/Modules/Products/routes.php")
            self.assertTrue(str(path).startswith(str(settings.project_root)))

            with self.assertRaises(ValueError):
                self.module.resolve_project_path(settings, "../.env")
            with self.assertRaises(ValueError):
                self.module.resolve_project_path(settings, ".git/config")
            with self.assertRaises(ValueError):
                self.module.resolve_project_path(settings, ".env", writable=True)
            with self.assertRaises(ValueError):
                self.module.resolve_project_path(settings, "storage/logs/laravel.log", writable=True)

    def test_clamps_limits(self):
        self.assertEqual(self.module.clamp_limit(0), 1)
        self.assertEqual(self.module.clamp_limit(50), 50)
        self.assertEqual(self.module.clamp_limit(999), self.module.MAX_SQL_LIMIT)


if __name__ == "__main__":
    unittest.main()
