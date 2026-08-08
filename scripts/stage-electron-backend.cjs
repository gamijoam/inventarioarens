const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");

const PORTABLE_PHP_ARTIFACT = Object.freeze({
    version: "8.4.24",
    flavor: "bulk",
    fileName: "php-8.4.24-cli-linux-x86_64.tar.gz",
    sha256:
        "26424cdb8599e94565bd8e70a43be8b9b085d478cf4db41cfa0cd39017318c9f",
});
const WINDOWS_PHP_ARTIFACT = Object.freeze({
    version: "8.4.24",
    flavor: "nts-windows",
    fileName: "php-8.4.24-nts-Win32-vs17-x64.zip",
    sha256:
        "86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb",
});

const BACKEND_ENTRIES = Object.freeze([
    ["artisan", "artisan"],
    ["app", "app"],
    ["bootstrap", "bootstrap"],
    ["config", "config"],
    ["database", "database"],
    ["public", "public"],
    ["resources", "resources"],
    ["routes", "routes"],
    ["scripts", "scripts"],
    ["vendor", "vendor"],
    ["composer.json", "composer.json"],
    ["composer.lock", "composer.lock"],
]);

function copyTree(sourcePath, destinationPath) {
    const sourceStat = fs.lstatSync(sourcePath);

    if (sourceStat.isSymbolicLink()) return;

    if (!sourceStat.isDirectory()) {
        fs.mkdirSync(path.dirname(destinationPath), { recursive: true });
        fs.copyFileSync(sourcePath, destinationPath);
        return;
    }

    fs.mkdirSync(destinationPath, { recursive: true });
    for (const entry of fs.readdirSync(sourcePath)) {
        copyTree(
            path.join(sourcePath, entry),
            path.join(destinationPath, entry),
        );
    }
}

function stageBackend({ repoRoot, stageRoot }) {
    fs.rmSync(stageRoot, { recursive: true, force: true });
    fs.mkdirSync(stageRoot, { recursive: true });
    const backendRoot = path.join(stageRoot, "backend");
    fs.mkdirSync(backendRoot, { recursive: true });

    for (const [source, destination] of BACKEND_ENTRIES) {
        const sourcePath = path.join(repoRoot, source);
        const destinationPath = path.join(backendRoot, destination);

        if (!fs.existsSync(sourcePath)) {
            throw new Error(
                `No se puede empaquetar Laravel: falta ${sourcePath}`,
            );
        }

        copyTree(sourcePath, destinationPath);
    }

    fs.writeFileSync(
        path.join(backendRoot, "RUNTIME_README.txt"),
        "Generated Laravel runtime payload. Do not store .env or user data here.\n",
    );
}

function resolvePhpRuntimeSource({
    repoRoot,
    platform = process.platform,
    phpRuntime,
} = {}) {
    if (phpRuntime) return path.resolve(phpRuntime);

    if (process.env.INVENTARIO_PHP_RUNTIME) {
        return path.resolve(process.env.INVENTARIO_PHP_RUNTIME);
    }

    if (platform === "linux") {
        const portableRuntime = path.join(
            repoRoot,
            "build",
            "linux-runtime",
            "php",
            "php",
        );

        if (fs.existsSync(portableRuntime)) return portableRuntime;

        if (process.env.INVENTARIO_ALLOW_HOST_PHP !== "1") {
            throw new Error(
                `No se encontro PHP portable en ${portableRuntime}. Ejecuta pnpm run electron:prepare:php:linux o define INVENTARIO_PHP_RUNTIME.`,
            );
        }
    }

    if (platform === "win32") {
        return path.join(repoRoot, "build", "windows-runtime", "php");
    }

    return execFileSync("which", ["php"], { encoding: "utf8" }).trim();
}

function getPortablePhpArtifact({ platform, arch } = {}) {
    if (platform === "linux" && arch === "x64") {
        return PORTABLE_PHP_ARTIFACT;
    }

    if (platform === "win32" && arch === "x64") {
        return WINDOWS_PHP_ARTIFACT;
    }

    throw new Error(`No hay un runtime PHP portable definido para ${platform}/${arch}`);
}

function stagePhpRuntime({
    repoRoot,
    stageRoot,
    platform = process.platform,
    phpRuntime,
} = {}) {
    const source = resolvePhpRuntimeSource({ repoRoot, platform, phpRuntime });

    if (!source || !fs.existsSync(source)) {
        throw new Error(
            `No se encontro el runtime PHP en ${source || "(sin ruta)"}`,
        );
    }

    const destination = path.join(stageRoot, "runtime", "php");
    fs.rmSync(destination, { recursive: true, force: true });
    fs.mkdirSync(destination, { recursive: true });

    if (fs.statSync(source).isDirectory()) {
        copyTree(source, destination);
    } else {
        fs.copyFileSync(
            source,
            path.join(destination, platform === "win32" ? "php.exe" : "php"),
        );
    }
}

if (require.main === module) {
    const repoRoot = path.resolve(__dirname, "..");
    const stageDirectory =
        process.env.INVENTARIO_BACKEND_STAGE ?? ".electron-backend-stage";
    const stageRoot = path.join(repoRoot, "frontend", stageDirectory);
    stageBackend({ repoRoot, stageRoot });
    stagePhpRuntime({ repoRoot, stageRoot });
    process.stdout.write(`Laravel backend staged at ${stageRoot}\n`);
}

module.exports = {
    BACKEND_ENTRIES,
    getPortablePhpArtifact,
    resolvePhpRuntimeSource,
    stageBackend,
    stagePhpRuntime,
};
