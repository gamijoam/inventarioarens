"use strict";

const fs = require("node:fs/promises");
const path = require("node:path");
const { execFile } = require("node:child_process");
const { promisify } = require("node:util");

const execFileAsync = promisify(execFile);
const ROOT = __dirname;
const DEFAULT_OUTPUT_DIR = path.resolve(ROOT, "../../build/print-connector");
const SENTINEL_FUSE = "NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2";

function parseArgs(argv) {
    const args = {
        outputDir: DEFAULT_OUTPUT_DIR,
        version: "0.1.0",
        checkOnly: false,
    };
    for (let index = 0; index < argv.length; index += 1) {
        const value = argv[index];
        if (value === "--output-dir")
            args.outputDir = path.resolve(argv[++index]);
        else if (value === "--version") args.version = argv[++index];
        else if (value === "--check-only") args.checkOnly = true;
        else if (value === "--help") args.help = true;
        else throw new Error(`Argumento desconocido: ${value}`);
    }
    return args;
}

async function ensureReleaseInputs() {
    const required = [
        path.join(ROOT, "connector.cjs"),
        path.join(ROOT, "PrintConnector.iss"),
        path.join(ROOT, "install-task.ps1"),
        path.join(ROOT, "uninstall-task.ps1"),
    ];
    for (const filePath of required) {
        await fs.access(filePath);
    }
}

async function createSeaExecutable({ outputDir, version }) {
    const stageDir = path.join(outputDir, "stage");
    const tempDir = path.join(outputDir, ".sea");
    const blobPath = path.join(tempDir, "connector.blob");
    const seaConfigPath = path.join(tempDir, "sea-config.json");
    const executableName =
        process.platform === "win32"
            ? "InventarioArens-Print-Connector.exe"
            : "InventarioArens-Print-Connector";
    const executablePath = path.join(stageDir, executableName);

    await fs.rm(tempDir, { recursive: true, force: true });
    await fs.rm(stageDir, { recursive: true, force: true });
    await fs.mkdir(tempDir, { recursive: true });
    await fs.mkdir(stageDir, { recursive: true });
    await fs.writeFile(
        seaConfigPath,
        JSON.stringify(
            {
                main: path.join(ROOT, "connector.cjs"),
                output: blobPath,
                disableExperimentalSEAWarning: true,
                useCodeCache: true,
                useSnapshot: false,
            },
            null,
            2,
        ),
    );

    await execFileAsync(
        process.execPath,
        ["--experimental-sea-config", seaConfigPath],
        { cwd: ROOT },
    );
    await fs.copyFile(process.execPath, executablePath);

    const npxCommand = process.platform === "win32" ? "npx.cmd" : "npx";
    await execFileAsync(
        npxCommand,
        [
            "--yes",
            "postject@1.0.0-alpha.6",
            executablePath,
            "NODE_SEA_BLOB",
            blobPath,
            "--sentinel-fuse",
            SENTINEL_FUSE,
            "--overwrite",
        ],
        {
            cwd: ROOT,
            windowsHide: true,
            shell: process.platform === "win32",
        },
    );

    await fs.writeFile(
        path.join(stageDir, "VERSION.txt"),
        `${version}\n`,
        "ascii",
    );
    await fs.copyFile(
        path.join(ROOT, "install-task.ps1"),
        path.join(stageDir, "install-task.ps1"),
    );
    await fs.copyFile(
        path.join(ROOT, "uninstall-task.ps1"),
        path.join(stageDir, "uninstall-task.ps1"),
    );
    await fs.rm(tempDir, { recursive: true, force: true });

    return { executablePath, stageDir };
}

async function main(argv = process.argv.slice(2)) {
    const args = parseArgs(argv);
    if (args.help) {
        process.stdout.write(
            "Uso: node package-connector.cjs [--version 0.1.0] [--output-dir build/print-connector] [--check-only]\n",
        );
        return;
    }

    await ensureReleaseInputs();
    if (args.checkOnly) {
        process.stdout.write("Contrato de empaquetado del conector valido.\n");
        return;
    }

    const result = await createSeaExecutable(args);
    process.stdout.write(`Conector empaquetado en ${result.executablePath}\n`);
}

if (require.main === module) {
    main().catch((error) => {
        process.stderr.write(`${error.message}\n`);
        process.exitCode = 1;
    });
}

module.exports = {
    DEFAULT_OUTPUT_DIR,
    SENTINEL_FUSE,
    createSeaExecutable,
    ensureReleaseInputs,
    parseArgs,
};
