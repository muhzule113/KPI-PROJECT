import { chromium } from 'playwright-core';

const baseUrl = process.env.APP_URL ?? 'http://127.0.0.1:8088';
const browser = await chromium.launch({
    executablePath: process.env.CHROME_PATH ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.goto(`${baseUrl}/login`);
    await page.getByLabel('Email').fill('manager@toko.com');
    await page.getByLabel('Kata sandi').fill('password');
    await Promise.all([
        page.waitForURL((url) => url.pathname === '/app'),
        page.getByRole('button', { name: 'Masuk ke dashboard' }).click(),
    ]);
    await page.goto(`${baseUrl}/app/team-tasks`);

    await page.getByRole('heading', { name: 'Penilaian Tim' }).waitFor();
    const pendingTab = page.getByRole('tab', { name: 'Belum selesai', exact: true });
    const completedTab = page.getByRole('tab', { name: 'Selesai', exact: true });
    await pendingTab.focus();
    await page.keyboard.press('Tab');
    if (await completedTab.evaluate((element) => document.activeElement !== element)) {
        throw new Error('Urutan fokus keyboard tidak berpindah ke tab Selesai.');
    }
    const action = page.getByRole('link', { name: /Isi kehadiran|Nilai sekarang|Periksa masalah|Tinjau rekap|Sahkan hasil/ }).first();
    if (await action.count()) {
        const href = await action.getAttribute('href');
        if (!href || (!href.includes('kpi_id=') && !href.includes('/employee-kpis/') && !href.includes('/supervisor-reviews/'))) {
            throw new Error(`CTA tidak menuju KPI tertentu: ${href}`);
        }
    }
    const filter = page.getByLabel('Filter tanggal');
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    await filter.click();
    const calendar = page.getByRole('dialog', { name: 'Pilih tanggal' });
    await calendar.waitFor();
    if (await calendar.locator('[aria-pressed="true"]').getAttribute('data-date') !== today) {
        throw new Error('Filter awal bukan tanggal hari ini.');
    }
    const previousDate = new Date(now);
    previousDate.setDate(previousDate.getDate() - 1);
    const previous = `${previousDate.getFullYear()}-${String(previousDate.getMonth() + 1).padStart(2, '0')}-${String(previousDate.getDate()).padStart(2, '0')}`;
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('date') === previous),
        calendar.locator(`[data-date="${previous}"]`).click(),
    ]);
    await completedTab.press('Enter');
    await filter.waitFor();
    await filter.click();
    await calendar.waitFor();

    for (const control of await page.locator('main button, main input, main a, [role="dialog"] button').all()) {
        const box = await control.boundingBox();
        if (box && box.height < 44) throw new Error(`Target sentuh ${box.height}px kurang dari 44px.`);
    }

    await page.setViewportSize({ width: 640, height: 800 });
    const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (overflows) throw new Error('Halaman meluap horizontal pada pembesaran 200%.');
    await page.keyboard.press('Escape');
    if (await filter.getAttribute('aria-expanded') !== 'false') throw new Error('Kalender tidak dapat ditutup dengan Escape.');
    if (consoleErrors.length) throw new Error(`Console error: ${consoleErrors.join(' | ')}`);

    console.log('PASS: kalender kustom, tab, keyboard, target 44px, dan pembesaran 200%.');
} finally {
    await browser.close();
}
