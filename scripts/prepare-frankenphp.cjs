const crypto = require("node:crypto");
const fs = require("node:fs");
const https = require("node:https");
const os = require("node:os");
const path = require("node:path");
const { execFileSync } = require("node:child_process");

// FrankenPHP empaqueta Caddy + PHP (ZTS) en un unico binario. Lo usamos como
// servidor multi-hilo del Motor Local en Windows, reemplazando `php artisan
// serve` (monohilo). Ver docs/MOTOR_LOCAL_OFFLINE_OPCACHE_Y_SERVIDOR_2026-09-23.md.
const ARTIFACT = Object.freeze({
    version: "1.12.7",
    fileName: "frankenphp-windows-x86_64.zip",
    url: "https://github.com/dunglas/frankenphp/releases/download/v1.12.7/frankenphp-windows-x86_64.zip",
    sha256: "07cf35e0a36ff237179c847a6c878605dd9d8230f5ac5bd37b86f69096545421",
});

const repoRoot = path.resolve(__dirname, "..");
const cacheRoot = path.join(repoRoot, "build", ".cache", "frankenphp");
const archivePath = path.join(cacheRoot, ARTIFACT.fileName);
const targetRoot = path.join(repoRoot, "build", "windows-runtime", "frankenphp");

function sha256(filePath) {
    return crypto
        .createHash("sha256")
        .update(fs.readFileSync(filePath))
        .digest("hex");
}

function download(url, destination) {
    return new Promise((resolve, reject) => {
        https
            .get(url, (response) => {
                if ([301, 302, 307, 308].includes(response.statusCode)) {
                    response.resume();
                    download(new URL(response.headers.location, url).toString(), destination).then(resolve, reject);
                    return;
                }

                if (response.statusCode !== 200) {
                    response.resume();
                    reject(new Error(`Descarga FrankenPHP fallo con HTTP ${response.statusCode}`));
                    return;
                }

                const output = fs.createWriteStream(destination);
                response.pipe(output);
                output.on("finish", () => output.close(resolve));
                output.on("error", reject);
            })
            .on("error", reject);
    });
}

async function prepare() {
    if (process.platform !== "win32" && process.env.INVENTARIO_FORCE_FRANKENPHP !== "1") {
        process.stdout.write("FrankenPHP: se omite (no es Windows).\n");
        return;
    }

    fs.mkdirSync(cacheRoot, { recursive: true });

    if (fs.existsSync(archivePath) && sha256(archivePath) !== ARTIFACT.sha256) {
        fs.rmSync(archivePath);
    }

    if (!fs.existsSync(archivePath)) {
        process.stdout.write(`Descargando FrankenPHP ${ARTIFACT.version}...\n`);
        await download(ARTIFACT.url, archivePath);
    }

    const checksum = sha256(archivePath);
    if (checksum !== ARTIFACT.sha256) {
        throw new Error(`SHA-256 de FrankenPHP no coincide: ${checksum}`);
    }

    const extractRoot = fs.mkdtempSync(path.join(os.tmpdir(), "inventario-frankenphp-"));
    try {
        execFileSync("tar", ["-xf", archivePath, "-C", extractRoot], {
            stdio: "inherit",
        });

        if (!fs.existsSync(path.join(extractRoot, "frankenphp.exe"))) {
            throw new Error(`frankenphp.exe no existe dentro de ${ARTIFACT.fileName}`);
        }
        if (!fs.existsSync(path.join(extractRoot, "ext", "php_pdo_sqlite.dll"))) {
            throw new Error("El zip de FrankenPHP no incluye ext/php_pdo_sqlite.dll");
        }

        // El payload no debe llevar el zip descargado.
        const stale = path.join(extractRoot, ARTIFACT.fileName);
        if (fs.existsSync(stale)) fs.rmSync(stale);

        fs.rmSync(targetRoot, { recursive: true, force: true });
        fs.mkdirSync(targetRoot, { recursive: true });
        fs.cpSync(extractRoot, targetRoot, { recursive: true });
    } finally {
        fs.rmSync(extractRoot, { recursive: true, force: true });
    }

    process.stdout.write(`FrankenPHP listo en ${targetRoot}\n`);
}

prepare().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exitCode = 1;
});
