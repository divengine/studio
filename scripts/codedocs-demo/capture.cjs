// Take authenticated documentation screenshots on the Studio host.
// STUDIO_ROOT=/path/to/studio node capture.cjs /path/to/puppeteer
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

(async () => {
  const { default: puppeteer } = await import(pathToFileURL(require.resolve(process.argv[2] || 'puppeteer')).href);
  const studio = process.env.STUDIO_ROOT || path.resolve(__dirname, '../..');
  const root = process.env.CODEDOCS_DEMO_ROOT || path.join(process.env.HOME, 'workspace/codedocs-demo');
  const base = process.env.STUDIO_URL || 'http://127.0.0.1:8080';
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'output/manifest.json')));
  const out = path.join(root, 'output/screens');
  fs.mkdirSync(out, { recursive: true });
  // One-time login remains in this process; never print it or persist cookies.
  const login = execFileSync(path.join(studio, 'vendor/bin/drush'), ['--uri=' + base, 'user:login', '--uid=1', '--no-browser'], { cwd: studio, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
    await page.goto(login, { waitUntil: 'networkidle0' });
    const php = manifest.projects['demo-shop-php'];
    const ts = manifest.projects['demo-shop-ts'];
    for (const [name, url] of [
      ['codedocs', '/development/' + manifest.development + '/docs'],
      ['codedocs-history', '/docs/' + php.set],
      ['codedocs-snapshot', '/docs/snapshot/' + ts.snapshot],
      ['codedocs-symbol', '/docs/snapshot/' + php.snapshot + '/symbol/' + php.symbol],
    ]) {
      const response = await page.goto(base + url, { waitUntil: 'networkidle0' });
      if (!response.ok()) throw new Error(url + ': HTTP ' + response.status());
      await page.evaluate(() => document.fonts.ready);
      await page.screenshot({ path: path.join(out, name + '.png'), fullPage: false });
      await page.screenshot({ path: path.join(out, name + '.jpg'), type: 'jpeg', quality: 88, fullPage: false });
      console.log('Captured ' + name);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
