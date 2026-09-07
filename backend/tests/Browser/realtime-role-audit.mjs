import { pathToFileURL } from 'node:url';

const baseURL = process.env.APP_URL ?? 'http://127.0.0.1:8000';
const playwrightModule = process.env.PLAYWRIGHT_CORE_PATH ?? 'playwright-core';
const { chromium } = await import(
    playwrightModule.includes(':') || playwrightModule.startsWith('/')
        ? pathToFileURL(playwrightModule).href
        : playwrightModule
);

const allAccounts = [
    { email: 'admin@kpi.com', web: true, menus: 31 },
    { email: 'kpi_admin@kpi.com', web: true, menus: 17, denied: '/app/users' },
    { email: 'auditor@kpi.com', web: true, menus: 5, denied: '/app/users' },
    { email: 'manager@toko.com', web: true, menus: 9, denied: '/app/kpi-definitions' },
    { email: 'supervisor@toko.com', web: true, menus: 11, denied: '/app/attendances' },
    { email: 'teknisi@toko.com', web: false },
    { email: 'cs@toko.com', web: false },
    { email: 'admin_staff@toko.com', web: true, menus: 3, denied: '/app/users' },
    { email: 'kasir@toko.com', web: true, menus: 4, denied: '/app/users' },
    { email: 'gudang@toko.com', web: false },
];
const accountFilter = (process.env.ACCOUNTS ?? '').split(',').filter(Boolean);
const accounts = accountFilter.length
    ? allAccounts.filter(({ email }) => accountFilter.includes(email))
    : allAccounts;

const browser = await chromium.launch({
    headless: true,
    ...(process.env.CHROMIUM_EXECUTABLE_PATH
        ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH }
        : { channel: 'chrome' }),
});

const results = [];

for (const account of accounts) {
    const context = await browser.newContext({ acceptDownloads: false });
    const page = await context.newPage();
    const failures = [];
    const consoleErrors = [];
    const pageErrors = [];
    const serverErrors = [];

    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', (error) => pageErrors.push(`${new URL(page.url()).pathname}: ${error.message}`));
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Kata sandi').fill('password');
    await Promise.all([
        page.waitForResponse((response) => response.url() === `${baseURL}/login` && response.request().method() === 'POST'),
        page.getByRole('button', { name: 'Masuk ke dashboard' }).click(),
    ]);
    await page.waitForFunction(() =>
        location.pathname === '/app' || document.querySelector('button[type="submit"]')?.disabled === false,
    );

    if (!account.web) {
        const message = await page.locator('main').innerText();
        if (!page.url().endsWith('/login')) failures.push(`Akun mobile masuk ke web: ${page.url()}`);
        if (!message.includes('hanya dapat masuk melalui aplikasi mobile')) {
            failures.push(`Pesan penolakan platform tidak tampil — ${message.replace(/\s+/g, ' ').trim()}`);
        }
        if (consoleErrors.some((error) => /Content Security Policy|WebSocket connection/i.test(error))) {
            failures.push('Koneksi realtime gagal');
        }
        results.push({
            account: account.email,
            expected: 'mobile-only',
            status: failures.length ? 'FAIL' : 'PASS',
            pages: 1,
            failures,
            consoleErrors: [...new Set(consoleErrors)],
            pageErrors: [...new Set(pageErrors)],
            serverErrors: [...new Set(serverErrors)],
        });
        await context.close();
        continue;
    }

    if (new URL(page.url()).pathname !== '/app') {
        const loginText = (await page.locator('main').innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
        failures.push(`Login tidak menuju /app: ${page.url()} — ${loginText}`);
    }

    const navigation = await page.locator('nav a[href^="/app"]').evaluateAll((links) =>
        links.map((link) => ({ label: link.textContent.trim(), href: link.getAttribute('href') })),
    );
    if (navigation.length !== account.menus) {
        failures.push(`Jumlah menu ${navigation.length}, seharusnya ${account.menus}`);
    }

    const routeKey = (href) => new URL(href, baseURL).pathname
        .replace(/\/[0-9a-z]{26}(?=\/|$)/g, '/:id')
        .replace(/\/\d+(?=\/|$)/g, '/:id');
    const visited = new Set(['/app']);
    const visitedRoutes = new Set(['/app']);
    const queue = navigation.map(({ href }) => href);
    const pages = [];

    while (queue.length) {
        const href = queue.shift();
        const key = href ? routeKey(href) : '';
        if (!href || visitedRoutes.has(key) || href.includes('/reports/') || href.includes('/evidence/')) continue;
        visited.add(href);
        visitedRoutes.add(key);

        const response = await page.goto(new URL(href, baseURL).href, { waitUntil: 'networkidle' });
        const path = new URL(page.url()).pathname;
        const status = response?.status() ?? 0;
        const heading = (await page.locator('h1, h2').first().textContent().catch(() => ''))?.trim() ?? '';
        pages.push({ href, status, path, heading });

        if (status >= 400) failures.push(`${href} merespons ${status}`);
        if (path === '/login') failures.push(`${href} mengalihkan kembali ke login`);
        if (await page.getByText(/Server Error|Internal Server Error|Whoops|Exception/i).count()) {
            failures.push(`${href} menampilkan error aplikasi`);
        }

        const discovered = await page.locator('a[href^="/app/"]').evaluateAll((links) =>
            links.map((link) => link.getAttribute('href')),
        );
        for (const link of discovered) {
            if (link && !visitedRoutes.has(routeKey(link)) && !queue.some((queued) => routeKey(queued) === routeKey(link))) queue.push(link);
        }
    }

    if (serverErrors.length) failures.push(...serverErrors.map((error) => `Server error: ${error}`));
    if (pageErrors.length) failures.push(...pageErrors.map((error) => `Page error: ${error}`));
    if (consoleErrors.some((error) => /Content Security Policy|WebSocket connection/i.test(error))) {
        failures.push('Koneksi realtime gagal');
    }

    if (account.denied) {
        const deniedResponse = await page.goto(`${baseURL}${account.denied}`, { waitUntil: 'domcontentloaded' });
        if (deniedResponse?.status() !== 403) {
            failures.push(`${account.denied} seharusnya 403, aktual ${deniedResponse?.status() ?? 0}`);
        }
    }

    await page.goto(`${baseURL}/app`, { waitUntil: 'networkidle' });
    const profileButton = page.getByRole('button', { name: 'Buka menu pengguna' });
    if (await profileButton.count()) {
        await profileButton.click();
        await Promise.all([
            page.waitForURL('**/login'),
            page.getByRole('menuitem', { name: /keluar/i }).click(),
        ]);
        if (new URL(page.url()).pathname !== '/login') failures.push('Logout tidak kembali ke login');
    } else {
        failures.push(`Dashboard akhir tidak merender topbar: ${page.url()}`);
    }

    results.push({
        account: account.email,
        expected: 'web',
        status: failures.length ? 'FAIL' : 'PASS',
        menus: navigation.length,
        pages: visited.size,
        failures: [...new Set(failures)],
        consoleErrors: [...new Set(consoleErrors)],
        pageErrors: [...new Set(pageErrors)],
        serverErrors: [...new Set(serverErrors)],
        navigation,
        visited: pages,
    });
    console.error(`${account.email}: ${failures.length ? 'FAIL' : 'PASS'} (${visited.size} halaman)`);
    await context.close();
}

await browser.close();

const summary = {
    baseURL,
    accounts: results.length,
    passed: results.filter(({ status }) => status === 'PASS').length,
    failed: results.filter(({ status }) => status === 'FAIL').length,
    results,
};

const output = process.env.SUMMARY_ONLY
    ? { ...summary, results: results.map(({ account, expected, status, menus, pages, failures, consoleErrors, pageErrors, serverErrors }) => ({ account, expected, status, menus, pages, failures, consoleErrors, pageErrors, serverErrors })) }
    : summary;

console.log(JSON.stringify(output, null, 2));
process.exitCode = summary.failed ? 1 : 0;
