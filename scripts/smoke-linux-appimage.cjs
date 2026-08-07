const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn } = require("node:child_process");

function getSmokeConfig(repoRoot, mode) {
    if (mode === "pos") {
        return {
            appImage: path.join(
                repoRoot,
                "frontend",
                "release",
                "pos",
                "Sistema-de-Inventario-POS-0.2.0.AppImage",
            ),
            apiPort: 8806,
            mode,
        };
    }

    if (mode === "technician") {
        return {
            appImage: path.join(
                repoRoot,
                "frontend",
                "release",
                "technician",
                "Soporte-Tecnico-Inventario-Arens-0.2.0.AppImage",
            ),
            apiPort: 8807,
            mode,
        };
    }

    return {
        appImage: path.join(
            repoRoot,
            "frontend",
            "release",
            "admin",
            "Sistema-de-Inventario-Administrativo-0.2.0.AppImage",
        ),
        apiPort: 8805,
        mode: "admin",
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
