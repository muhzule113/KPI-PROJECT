import { chromium } from 'playwright-core';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const baseURL = process.env.APP_URL ?? 'http://127.0.0.1:8787';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const results = [];

async function login(page, email) {
    const diagnostics = [];
    page.on('console', (message) => message.type() === 'error' && diagnostics.push(message.text()));
    page.on('pageerror', (error) => diagnostics.push(error.message));
    const response = await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    if (await page.getByLabel('Email').count() !== 1) {
        throw new Error(`Form login tidak tampil: ${response?.status()} ${page.url()} ${diagnostics.join(' | ')} ${(await page.locator('body').innerText()).slice(0, 300)}`);
    }
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Kata sandi').fill('password');
    await Promise.all([
        page.waitForURL('**/app'),
        page.getByRole('button', { name: 'Masuk ke dashboard' }).click(),
    ]);
}

for (const viewport of [{ width: 375, height: 812 }, { width: 768, height: 900 }, { width: 1440, height: 900 }]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const consoleErrors = [];
    const serverErrors = [];
    page.on('console', (message) => message.type() === 'error' && consoleErrors.push(message.text()));
    page.on('response', (response) => response.status() >= 500 && serverErrors.push(`${response.status()} ${response.url()}`));
    await login(page, 'admin@kpi.com');
    await page.goto(`${baseURL}/app/kpi-administration`, { waitUntil: 'networkidle' });

    const taskNames = ['Ubah predikat', 'Atur KPI jabatan', 'Atur penilai', 'Atur format import'];
    for (const task of taskNames) {
        if (await page.getByRole('link', { name: new RegExp(task) }).count() !== 1) throw new Error(`${task} tidak tampil pada ${viewport.width}px`);
    }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (overflow) throw new Error(`Overflow horizontal pada ${viewport.width}px`);

    await page.getByRole('button', { name: 'Cara pakai' }).click();
    if (!await page.getByRole('dialog', { name: 'Cara memakai pusat administrasi' }).isVisible()) throw new Error('Dialog bantuan tidak terbuka');
    await page.keyboard.press('Escape');
    if (await page.getByRole('dialog').count()) throw new Error('Dialog bantuan tidak tertutup dengan Escape');

    await page.getByText('Mode Lanjutan', { exact: true }).click();
    if (!await page.getByRole('link', { name: 'Katalog indikator' }).isVisible()) throw new Error('Mode Lanjutan tidak menampilkan tautan teknis');
    const navigationHrefs = await page.locator('nav a').evaluateAll((links) => links.map((link) => link.getAttribute('href')));
    if (!navigationHrefs.includes('/app/kpi-administration') || navigationHrefs.includes('/app/kpi-definitions')) throw new Error('Sidebar masih menampilkan menu teknis lama');
    await page.getByText('Mode Lanjutan', { exact: true }).click();

    const screenshot = join(tmpdir(), `kpi-administration-${viewport.width}.png`);
    await page.screenshot({ path: screenshot, fullPage: true });
    const wizardScreenshots = [];
    if ([375, 1440].includes(viewport.width)) {
        for (const [task, detail] of Object.entries({ predicates: 'Ubah predikat', templates: 'Atur KPI jabatan', assignments: 'Atur penilai', imports: 'Atur format import' })) {
            await page.goto(`${baseURL}/app/kpi-administration`, { waitUntil: 'networkidle' });
            const link = page.getByRole('link', { name: new RegExp(detail) });
            await link.focus();
            await page.keyboard.press('Enter');
            await page.waitForURL(`**task=${task}`);
            if (!await page.getByText('Yang perlu Anda lakukan:', { exact: true }).count()) throw new Error(`Hint tahap ${detail} tidak tampil`);
            if (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)) throw new Error(`Wizard ${detail} overflow pada ${viewport.width}px`);
            const unlabeledFields = await page.locator('input, select, textarea').evaluateAll((fields) => fields
                .filter((field) => !field.disabled && field.type !== 'hidden')
                .filter((field) => !field.getAttribute('aria-label') && !field.closest('label') && (!field.id || !document.querySelector(`label[for="${CSS.escape(field.id)}"]`)))
                .map((field) => `${field.tagName.toLowerCase()}#${field.id}`));
            if (unlabeledFields.length) throw new Error(`Field tanpa label pada ${detail}: ${unlabeledFields.join(', ')}`);
            const wizardScreenshot = join(tmpdir(), `kpi-${task}-${viewport.width}.png`);
            await page.screenshot({ path: wizardScreenshot, fullPage: true });
            wizardScreenshots.push(wizardScreenshot);
        }
    }
    results.push({ viewport: viewport.width, screenshot, wizardScreenshots, consoleErrors, serverErrors });
    await context.close();
}

const kpiContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const kpiPage = await kpiContext.newPage();
await login(kpiPage, 'kpi_admin@kpi.com');
await kpiPage.goto(`${baseURL}/app/kpi-administration`, { waitUntil: 'networkidle' });
if (await kpiPage.getByRole('link', { name: /Ubah predikat/ }).count()) throw new Error('Admin KPI melihat wizard predikat');
if (await kpiPage.getByRole('link', { name: /Atur KPI jabatan/ }).count()) throw new Error('Admin KPI melihat wizard template');
if (!await kpiPage.getByRole('link', { name: /Atur penilai/ }).count()) throw new Error('Admin KPI tidak melihat wizard penilai');
if (!await kpiPage.getByRole('link', { name: /Atur format import/ }).count()) throw new Error('Admin KPI tidak melihat wizard import');
await kpiContext.close();

const deniedContext = await browser.newContext();
const deniedPage = await deniedContext.newPage();
await login(deniedPage, 'manager@toko.com');
const deniedResponse = await deniedPage.goto(`${baseURL}/app/kpi-administration`, { waitUntil: 'domcontentloaded' });
if (deniedResponse?.status() !== 403) throw new Error(`Manager menerima status ${deniedResponse?.status()}, seharusnya 403`);
await deniedContext.close();

await browser.close();
const errors = results.flatMap((result) => [...result.consoleErrors, ...result.serverErrors]);
console.log(JSON.stringify({ status: errors.length ? 'FAIL' : 'PASS', results }, null, 2));
process.exitCode = errors.length ? 1 : 0;
