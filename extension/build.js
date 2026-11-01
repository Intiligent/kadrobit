/**
 * Builds the Chrome extension into extension/dist and packs it for Settings → Extension:
 * public/extension/kadrobit-extension-<version>.zip and public/extension/release.json.
 *
 *     npm run build:extension                                         # for APP_URL from .env
 *     EXTENSION_APP_URL=https://kadrobit.loc npm run build:extension  # for another Kadrobit, e.g. a local one
 *
 * The version is the one in extension/manifest.json. In the manifest, "@kadrobit" becomes the Kadrobit origin and
 * "@marketplaces" the patterns of src/marketplaces.js.
 */
import fs from 'fs';
import path from 'path';
import { zipSync } from 'fflate';
import { build, loadEnv } from 'vite';
import { MARKETPLACES } from './src/marketplaces.js';
import { extensionConfig } from './vite.config.js';

const root = import.meta.dirname;
const dist = path.resolve(root, 'dist');
const releaseDir = path.resolve(root, '../public/extension');

const env = { ...loadEnv('production', path.resolve(root, '..'), ''), ...process.env };
const appUrl = (env.EXTENSION_APP_URL || env.APP_URL || '').replace(/\/$/, '');
// The same default as config/extension.php: the client OauthSeeder creates.
const clientId = env.EXTENSION_CLIENT_ID || '01a1972a-6ffd-7284-9536-5f5987dec9bd';

// Without a Kadrobit to talk to (a checkout without .env) only the app is built.
if (!/^https?:\/\//.test(appUrl)) {
    console.warn('Extension not built: set APP_URL or EXTENSION_APP_URL to the Kadrobit it works with.');
    process.exit(0);
}

await build(extensionConfig({ appUrl, clientId }));
await build(extensionConfig({ appUrl, clientId, content: true }));

const manifest = JSON.parse(fs.readFileSync(path.resolve(root, 'manifest.json'), 'utf8'));
const marketplaces = MARKETPLACES.flatMap((marketplace) => marketplace.matches);
const expand = (patterns) => patterns.flatMap((pattern) => ({ '@kadrobit': [`${new URL(appUrl).origin}/*`], '@marketplaces': marketplaces })[pattern] ?? [pattern]);

manifest.host_permissions = expand(manifest.host_permissions);
manifest.content_scripts = manifest.content_scripts.map((script) => ({ ...script, matches: expand(script.matches) }));
fs.writeFileSync(path.resolve(dist, 'manifest.json'), `${JSON.stringify(manifest, null, 4)}\n`);

const file = `kadrobit-extension-${manifest.version}.zip`;
fs.rmSync(releaseDir, { recursive: true, force: true });
fs.mkdirSync(releaseDir, { recursive: true });
fs.writeFileSync(path.resolve(releaseDir, file), zipSync(readTree(dist), { level: 9 }));
fs.writeFileSync(path.resolve(releaseDir, 'release.json'), `${JSON.stringify({ version: manifest.version, built_at: new Date().toISOString(), file }, null, 4)}\n`);

console.log(`Extension ${manifest.version} for ${appUrl}: extension/dist, public/extension/${file}`);

/**
 * The folder as fflate's nested { name: bytes | folder } tree.
 */
function readTree(dir) {
    return Object.fromEntries(fs.readdirSync(dir, { withFileTypes: true }).map((entry) => {
        const full = path.resolve(dir, entry.name);

        return [entry.name, entry.isDirectory() ? readTree(full) : fs.readFileSync(full)];
    }));
}
