const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn } = require("node:child_process");

function getFrontendVersion(repoRoot) {
    const packageJsonPath = path.join(repoRoot, "frontend", "package.json");
    return JSON.parse(fs.readFileSync(packageJsonPath, "utf8")).version;
}

const CLIENT_SMOKE = Object.freeze({
    admin: { artifact: "Sistema-de-Inventario-Administrativo", dir: "admin", mode: "admin", apiPort: 8805 },
    pos: { artifact: "Sistema-de-Inventario-POS", dir: "pos", mode: "pos", apiPort: 8806 },
    technician: { artifact: "Soporte-Tecnico-Inventario", dir: "technician", mode: "technician", apiPort: 8807 },
    "balanzapro-pos": { artifact: "BalanzaPro-POS", dir: "balanzapro-pos", mode: "pos", apiPort: 8808 },
    "balanzapro-admin": { artifact: "BalanzaPro-Administrativo", dir: "balanzapro-admin", mode: "admin", apiPort: 8809 },
    "balanzapro-technician": { artifact: "BalanzaPro-Soporte-Tecnico", dir: "balanzapro-technician", mode: "technician", apiPort: 8810 },
});

function getSmokeConfig(repoRoot, client, version = getFrontendVersion(repoRoot)) {
    const config = CLIENT_SMOKE[client] ?? CLIENT_SMOKE.admin;

    return {
        appImage: path.join(
            repoRoot,
            "frontend",
            "release",
            config.dir,
            `${config.artifact}-${version}.AppImage`,
        ),
        apiPort: config.apiPort,
        mode: config.mode,
    };
}

function runSmoke(config) {
    return new Promise((resolve, reject) => {
        if (!fs.existsSync(config.appImage)) {
            reject(new Error(`No se encontro el AppImage: ${config.appImage}`));
            return;
        }

        const dataRoot = path.join(
            os.tmpdir(),
            `inventarioarens-appimage-${config.mode}-smoke`,
        );
        const logPath = path.join(
            os.tmpdir(),
            `inventarioarens-appimage-${config.mode}-smoke.log`,
        );
        const log = fs.openSync(logPath, "w");
        const child = spawn(
            config.appImage,
            ["--appimage-extract-and-run", "--no-sandbox"],
            {
                env: {
                    ...process.env,
                    INVENTARIO_API_PORT: String(config.apiPort),
                    INVENTARIO_DATA_ROOT: dataRoot,
                    INVENTARIO_ELECTRON_SMOKE: "1",
                },
                stdio: ["ignore", log, log],
            },
        );
        const timeout = setTimeout(() => {
            child.kill("SIGTERM");
            reject(
                new Error(
                    `El smoke Linux excedio el tiempo limite. Log: ${logPath}`,
                ),
            );
        }, 120_000);

        child.once("error", (error) => {
            clearTimeout(timeout);
            fs.closeSync(log);
            reject(error);
        });
        child.once("exit", (code, signal) => {
            clearTimeout(timeout);
            fs.closeSync(log);

            if (code !== 0) {
                reject(
                    new Error(
                        `El smoke Linux termino con codigo ${code} (${signal}). Log: ${logPath}`,
                    ),
                );
                return;
            }

            resolve({ dataRoot, logPath });
        });
    });
}

if (require.main === module) {
    const repoRoot = path.resolve(__dirname, "..");
    const mode = process.argv[2] ?? "admin";
    const config = getSmokeConfig(repoRoot, mode);

    runSmoke(config)
        .then(({ logPath }) =>
            process.stdout.write(
                `Linux AppImage smoke OK (${mode}). Log: ${logPath}\n`,
            ),
        )
        .catch((error) => {
            process.stderr.write(`${error.message}\n`);
            process.exitCode = 1;
        });
}

module.exports = {
    getSmokeConfig,
    runSmoke,
};
