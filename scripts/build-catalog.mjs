/**
 * Kodmob Keşfet kataloğu: tools/<anahtar>/ altındaki araç paketlerinden catalog.json ve dist/<anahtar>-<sürüm>.zip.
 *
 *   node scripts/build-catalog.mjs           tanımları denetler, zip'leri ve kataloğu yazar
 *   node scripts/build-catalog.mjs --check   yalnızca denetler (çekme isteğinde CI); bir şey yazmaz
 *
 * Kurallar Kodmob'un ToolManifest'iyle aynı (kodmob-core/app/Core/Extensions/ToolManifest.php): anahtar, ad, açıklama,
 * yazar, lisans, x.y.z sürüm, uyumlu çatı (">=3.0 <4"), ad alanı, modül sınıfı (php/<Modül>.php), klasör adı = anahtar.
 * Zip belirlenimci (sabit tarih, sıralı dosyalar): içerik değişmedikçe özeti (sha256) değişmez. Bağımlılık yok (Node 22).
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const TOOLS = path.join(ROOT, 'tools');
const DIST = path.join(ROOT, 'dist');
const REPO = process.env.CATALOG_REPO || 'kodmob/kodmob-explore';
const BRANCH = process.env.CATALOG_BRANCH || 'main';
const CHECK = process.argv.includes('--check');

const KEY = /^[a-z][a-z0-9-]{1,30}$/;
const VERSION = /^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/;
const CONSTRAINT = /^(?:(?:>=|>|<=|<|=|\^|~)?\s*\d+(?:\.\d+){0,2}\s*)+$/;
const NAMESPACE = /^[A-Z][A-Za-z0-9]*(\\[A-Z][A-Za-z0-9]*)+$/;
const RESERVED = ['App', 'Tests', 'Illuminate', 'Database', 'Laravel', 'Symfony'];
const SKIP = new Set(['.git', 'node_modules', 'vendor', '.idea', '.vscode', '.DS_Store']);

/** Paketin tanımını denetler; hata listesi (boşsa geçerli). */
export function validate(dir, data) {
    const errors = [];
    const text = (field) => (typeof data[field] === 'string' ? data[field].trim() : '');
    if (!KEY.test(text('key'))) errors.push('key: küçük harf, rakam ve tire (2–31), ör. "stok-takibi"');
    if (text('key') && path.basename(dir) !== text('key')) errors.push(`key: klasörün adı anahtarla aynı olmalı (${text('key')})`);
    for (const field of ['label', 'description', 'author', 'license']) if (!text(field)) errors.push(`${field}: boş olamaz`);
    if (!VERSION.test(text('version'))) errors.push('version: x.y.z biçiminde olmalı');
    if (!CONSTRAINT.test(text('kodmob'))) errors.push('kodmob: uyumlu çatı sürümü, ör. ">=3.0 <4"');
    if (!NAMESPACE.test(text('namespace')) || RESERVED.includes(text('namespace').split('\\')[0])) errors.push('namespace: kendi adınızla başlayan ad alanı, ör. "KodmobTools\\StokTakibi"');
    if (!/^[A-Z][A-Za-z0-9]*$/.test(text('module'))) errors.push('module: modül sınıfının adı, ör. "StokTakibiModule"');
    else if (!fs.existsSync(path.join(dir, 'php', `${text('module')}.php`))) errors.push(`module: php/${text('module')}.php yok`);
    const requires = data.requires ?? [];
    if (!Array.isArray(requires) || requires.some((r) => typeof r !== 'string' || !KEY.test(r) || r === text('key'))) errors.push('requires: takıldığı modüllerin anahtar listesi');
    return errors;
}

function files(dir, base = dir) {
    return fs.readdirSync(dir, { withFileTypes: true })
        .filter((e) => !SKIP.has(e.name))
        .flatMap((e) => (e.isDirectory() ? files(path.join(dir, e.name), base) : [path.relative(base, path.join(dir, e.name)).split(path.sep).join('/')]))
        .sort();
}

/** Belirlenimci zip: dosyalar `<anahtar>/…` altında, sıralı, sabit tarih (1 Ocak 2026), deflate. */
export function zip(dir, prefix) {
    const DOS_TIME = 0;
    const DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;
    const locals = [];
    const centrals = [];
    let offset = 0;
    for (const rel of files(dir)) {
        // Satır sonları LF: Windows'ta çıkarılan depo da aynı özeti verir.
        let data = fs.readFileSync(path.join(dir, rel));
        if (/\.(php|js|mjs|json|md|njk|html|css|txt|neon|xml|yml|yaml)$/i.test(rel) || /^LICENSE$/i.test(path.basename(rel))) data = Buffer.from(data.toString('utf8').replace(/\r\n/g, '\n'));
        const name = Buffer.from(`${prefix}/${rel}`, 'utf8');
        const deflated = zlib.deflateRawSync(data, { level: 9 });
        const crc = zlib.crc32(data) >>> 0;
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4);
        local.writeUInt16LE(0x0800, 6);
        local.writeUInt16LE(8, 8);
        local.writeUInt16LE(DOS_TIME, 10);
        local.writeUInt16LE(DOS_DATE, 12);
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(deflated.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(name.length, 26);
        local.writeUInt16LE(0, 28);
        locals.push(local, name, deflated);
        const central = Buffer.alloc(46);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);
        central.writeUInt16LE(20, 6);
        central.writeUInt16LE(0x0800, 8);
        central.writeUInt16LE(8, 10);
        central.writeUInt16LE(DOS_TIME, 12);
        central.writeUInt16LE(DOS_DATE, 14);
        central.writeUInt32LE(crc, 16);
        central.writeUInt32LE(deflated.length, 20);
        central.writeUInt32LE(data.length, 24);
        central.writeUInt16LE(name.length, 28);
        central.writeUInt32LE(0, 30);
        central.writeUInt32LE(0, 34);
        central.writeUInt32LE(0, 38);
        central.writeUInt32LE(offset, 42);
        centrals.push(central, name);
        offset += local.length + name.length + deflated.length;
    }
    const centralBuf = Buffer.concat(centrals);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(centrals.length / 2, 8);
    end.writeUInt16LE(centrals.length / 2, 10);
    end.writeUInt32LE(centralBuf.length, 12);
    end.writeUInt32LE(offset, 16);
    return Buffer.concat([...locals, centralBuf, end]);
}

function main() {
    const verified = new Set(fs.existsSync(path.join(ROOT, 'verified.json')) ? JSON.parse(fs.readFileSync(path.join(ROOT, 'verified.json'), 'utf8')).verified || [] : []);
    const dirs = fs.existsSync(TOOLS) ? fs.readdirSync(TOOLS, { withFileTypes: true }).filter((e) => e.isDirectory()).map((e) => path.join(TOOLS, e.name)).sort() : [];
    const tools = [];
    let failed = false;
    for (const dir of dirs) {
        const file = path.join(dir, 'kodmob-tool.json');
        let data;
        try {
            data = JSON.parse(fs.readFileSync(file, 'utf8'));
        } catch {
            console.error(`✗ ${path.basename(dir)}: kodmob-tool.json yok ya da bozuk`);
            failed = true;
            continue;
        }
        const errors = validate(dir, data);
        if (errors.length) {
            console.error(`✗ ${path.basename(dir)}:\n  - ${errors.join('\n  - ')}`);
            failed = true;
            continue;
        }
        const archive = zip(dir, data.key);
        const name = `${data.key}-${data.version}.zip`;
        const sha256 = crypto.createHash('sha256').update(archive).digest('hex');
        if (!CHECK) {
            fs.mkdirSync(DIST, { recursive: true });
            fs.writeFileSync(path.join(DIST, name), archive);
        }
        tools.push({
            key: data.key, label: data.label.trim(), description: data.description.trim(), author: data.author.trim(), license: data.license.trim(),
            version: data.version, kodmob: data.kodmob, requires: data.requires || [], homepage: (data.homepage || '').trim(),
            repository: `https://github.com/${REPO}/tree/${BRANCH}/tools/${data.key}`,
            download: `https://raw.githubusercontent.com/${REPO}/${BRANCH}/dist/${name}`,
            sha256, verified: verified.has(`${data.key}@${data.version}`),
        });
        console.log(`✓ ${data.key} ${data.version} (${archive.length} bayt)`);
    }
    if (failed) process.exit(1);
    if (CHECK) return console.log(`Denetim tamam: ${tools.length} araç.`);
    // Eski sürümlerin zip'leri kalkar (katalogda olmayanlar).
    const keep = new Set(tools.map((t) => path.basename(t.download)));
    for (const file of fs.existsSync(DIST) ? fs.readdirSync(DIST) : []) if (file.endsWith('.zip') && !keep.has(file)) fs.unlinkSync(path.join(DIST, file));
    const catalog = {
        description: 'Kodmob Keşfet kataloğu (github.com/kodmob/kodmob-explore): topluluk araç paketleri. Bu dosya CI tarafından tools/ klasöründen üretilir; elle düzenlemeyin. Kurulum: php artisan kodmob:add <key> (sha256 denetlenir).',
        format: 1,
        tools,
    };
    fs.writeFileSync(path.join(ROOT, 'catalog.json'), `${JSON.stringify(catalog, null, 2)}\n`);
    console.log(`catalog.json: ${tools.length} araç.`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
