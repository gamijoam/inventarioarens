const fs = require("node:fs");
const { createRequire } = require("node:module");
const path = require("node:path");

const asar = createRequire(
    path.join(__dirname, "..", "frontend", "package.json"),
)("@electron/asar");

const CLIENTS = ["admin", "pos", "technician"];
const MOTOR_PAYLOAD_PATTERNS = [
    /^backend(?:\/|$)/,
    /^runtime(?:\/|$)/,
    /(^|\/)artisan$/,
    /(^|\/)storage(?:\/|$)/,
    /(^|\/)install-backend-service\.ps1$/,
    /(^|\/)php(?:\.exe)?$/i,
    /\.(?:sqlite|db)$/i,
];

function normalizeEntries(entries) {
    return entries.map((entry) =>
        entry.replaceAll("\\", "/").replace(/^\/+/, ""),
    );
}

function validateEntries(client, rawEntries) {
    if (!CLIENTS.includes(client)) {
        throw new Error(`Cliente Electron invalido: ${client}`);
    }

    const entries = normalizeEntries(rawEntries);
    const renderer = `dist/${client}`;
    const foreignRenderers = CLIENTS.filter((candidate) => candidate !== client)
        .map((candidate) => `dist/${candidate}`)
        .filter((candidate) =>
            entries.some(
                (entry) =>
                    entry === candidate || entry.startsWith(`${candidate}/`),
            ),
        );

    if (foreignRenderers.length > 0) {
        throw new Error(
            `El artifact de ${client} contiene bundles de otros clientes: ${foreignRenderers.join(", ")}`,
        );
    }

    if (!entries.includes("electron/main.cjs")) {
        throw new Error(
            `El artifact de ${client} no contiene electron/main.cjs.`,
        );
    }

    if (!entries.includes(`${renderer}/index.html`)) {
        throw new Error(
            `El artifact de ${client} no contiene ${renderer}/index.html.`,
        );
    }

    if (!entries.some((entry) => entry.startsWith(`${renderer}/assets/`))) {
        throw new Error(
            `El artifact de ${client} no contiene assets de ${renderer}.`,
        );
    }

    const motorPayload = entries.filter((entry) =>
        MOTOR_PAYLOAD_PATTERNS.some((pattern) => pattern.test(entry)),
    );
    if (motorPayload.length > 0) {
        throw new Error(
            `El artifact de ${client} contiene payload del Motor Local: ${motorPayload.join(", ")}`,
        );
    }

    return { client, renderer };
}

function resolveAsarPath(artifactPath) {
    const resolved = path.resolve(artifactPath);
    const candidates = [resolved, path.join(resolved, "resources", "app.asar")];

    const asarPath = candidates.find(
        (candidate) =>
            fs.existsSync(candidate) && fs.statSync(candidate).isFile(),
    );
    if (!asarPath) {
        throw new Error(`No se encontro resources/app.asar en ${resolved}.`);
    }

    return asarPath;
}

function verifyArtifact(client, artifactPath) {
    const asarPath = resolveAsarPath(artifactPath);
    const result = validateEntries(client, asar.listPackage(asarPath));

    return { ...result, asarPath };
}

if (require.main === module) {
    const [, , client, artifactPath] = process.argv;

    if (!client || !artifactPath) {
        process.stderr.write(
            "Uso: node scripts/verify-electron-artifact.cjs <admin|pos|technician> <artifact-dir|app.asar>\n",
        );
        process.exitCode = 2;
    } else {
        try {
            const result = verifyArtifact(client, artifactPath);
            process.stdout.write(
                `Electron artifact OK (${result.client}): ${result.asarPath}\n`,
            );
        } catch (error) {
            process.stderr.write(`${error.message}\n`);
            process.exitCode = 1;
        }
    }
}

module.exports = {
    CLIENTS,
    normalizeEntries,
    resolveAsarPath,
    validateEntries,
    verifyArtifact,
};
