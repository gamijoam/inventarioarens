const crypto = require("node:crypto");
const fs = require("node:fs");
const https = require("node:https");
const os = require("node:os");
const path = require("node:path");
const { execFileSync } = require("node:child_process");

const { getPortablePhpArtifact } = require("./stage-electron-backend.cjs");

const repoRoot = path.resolve(__dirname, "..");
const windowsRuntimeRoot = path.join(repoRoot, "build", "windows-runtime", "php");
const artifact = getPortablePhpArtifact({
    platform: process.platform,
    arch: process.arch,
});
const downloadUrl = process.platform === "win32"
    ? `https://windows.php.net/downloads/releases/${artifact.fileName}`
    : `https://dl.static-php.dev/v3/php-bin/${artifact.flavor}/${artifact.fileName}`;
const cacheRoot = path.join(repoRoot, "build", ".cache", "php");
const archivePath = path.join(cacheRoot, artifact.fileName);
const runtimeRoot = path.join(repoRoot, "build", "linux-runtime", "php");
const runtimePath = path.join(runtimeRoot, "php");
const windowsArchivePath = path.join(repoRoot, "build", "windows-runtime", "php");

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
                    reject(new Error(`Descarga PHP fallo con HTTP ${response.statusCode}`));
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
    fs.mkdirSync(cacheRoot, { recursive: true });

    if (fs.existsSync(archivePath) && sha256(archivePath) !== artifact.sha256) {
        fs.rmSync(archivePath);
    }

    if (!fs.existsSync(archivePath)) {
        process.stdout.write(`Descargando PHP portable ${artifact.version}...\n`);
        await download(downloadUrl, archivePath);
    }

    const checksum = sha256(archivePath);
    if (checksum !== artifact.sha256) {
        throw new Error(`SHA-256 de PHP no coincide: ${checksum}`);
    }

    const extractRoot = fs.mkdtempSync(path.join(os.tmpdir(), "inventario-php-"));
    try {
        execFileSync("tar", [
            process.platform === "win32" ? "-xf" : "-xzf",
            archivePath,
            "-C",
            extractRoot,
        ], {
            stdio: "inherit",
        });

        const extractedPhp = path.join(
            extractRoot,
            process.platform === "win32" ? "php.exe" : "php",
        );
        if (!fs.existsSync(extractedPhp)) {
            throw new Error(`El archivo PHP no existe dentro de ${artifact.fileName}`);
        }

        const targetRoot = process.platform === "win32" ? windowsArchivePath : runtimeRoot;
        const targetPath = process.platform === "win32"
            ? path.join(targetRoot, "php.exe")
            : runtimePath;
        fs.rmSync(targetRoot, { recursive: true, force: true });
        fs.mkdirSync(targetRoot, { recursive: true });
        if (process.platform === "win32") {
            fs.cpSync(extractRoot, targetRoot, { recursive: true });

            // Create php.ini from php.ini-production
            const phpIniPath = path.join(targetRoot, "php.ini");
            const phpIniProduction = path.join(targetRoot, "php.ini-production");
            if (fs.existsSync(phpIniProduction)) {
                let iniContent = fs.readFileSync(phpIniProduction, "utf8");

                // Configure extension_dir to ext
                iniContent = iniContent.replace(/;?\s*extension_dir\s*=\s*"ext"/g, 'extension_dir = "ext"');

                // Enable extensions
                const extensions = ["curl", "fileinfo", "gd", "intl", "mbstring", "openssl", "pdo_sqlite", "sqlite3", "zip"];
                for (const ext of extensions) {
                    iniContent = iniContent.replace(new RegExp(`;\\s*extension\\s*=\\s*${ext}`, "g"), `extension = ${ext}`);
                }

                fs.writeFileSync(phpIniPath, iniContent, "utf8");
            }

            // Copy cacert.pem
            const cacertSrc = path.join(repoRoot, "installer", "windows", "cacert.pem");
            const cacertDst = path.join(targetRoot, "cacert.pem");
            if (fs.existsSync(cacertSrc)) {
                fs.copyFileSync(cacertSrc, cacertDst);
            }
        } else {
            fs.copyFileSync(extractedPhp, targetPath);
            fs.chmodSync(targetPath, 0o755);
        }

        const drivers = execFileSync(targetPath, [
            "-r",
            "echo implode(',', PDO::getAvailableDrivers());",
        ], { encoding: "utf8" });
        if (!drivers.split(",").includes("sqlite")) {
            throw new Error(`PHP portable no incluye PDO SQLite: ${drivers}`);
        }
    } finally {
        fs.rmSync(extractRoot, { recursive: true, force: true });
    }

    process.stdout.write(`PHP portable listo en ${runtimePath}\n`);
}

prepare().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exitCode = 1;
});
