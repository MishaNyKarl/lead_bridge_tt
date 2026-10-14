// Optional real browser pass, driven by opsvault_http_test.py's isolated fake provider.
const { chromium } = require(process.argv[3]);
const assert = require('node:assert/strict');
const base = process.argv[2];
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.argv[4] ? { channel: process.argv[4] } : {}) });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true,
    proxy: { server: process.argv[5], bypass: '127.0.0.1,localhost' } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') console.error('Fixture browser console:', message.text()); });
  await page.goto(base);
  await page.getByRole('link', { name: 'Войти через OpsVault', exact: true }).focus();
  assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Войти через OpsVault');
  await page.keyboard.press('Tab');
  assert.equal(await page.locator('input[name=login]').evaluate(el => el === document.activeElement), true);
  await page.locator('input[name=login]').fill('local-b');
  await page.locator('input[name=password]').fill('separate-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.getByRole('button', { name: 'Связать OpsVault', exact: true }).waitFor();
  assert.ok(await page.evaluate(() => { const ids = [...document.querySelectorAll('[id]')].map(el => el.id); return ids.length === new Set(ids).size; }));
  await page.locator('form:has(input[value=ops_link_start]) input[name=ops_password]').fill('separate-password-123');
  await page.locator('input[name=vault_consent]').check();
  await page.getByRole('button', { name: 'Связать OpsVault', exact: true }).click();
  await page.getByRole('link', { name: 'Fixture sign in', exact: true }).click();
  try { await page.getByRole('button', { name: 'Подтвердить связь', exact: true }).waitFor({ timeout: 10000 }); }
  catch (error) { console.error('Fixture callback page:', page.url(), (await page.locator('body').innerText()).slice(0,1600)); throw error; }
  assert.ok((await page.locator('main').innerText()).includes('PILOT_OPS_B'));
  assert.equal(await page.locator('input[name=password]').count(), 1); // local change form, never pending plaintext
  assert.ok(!(await page.content()).includes('separate-password-123'));
  await page.locator('input[name=confirm_accounts]').focus();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Подтвердить связь');
  await page.keyboard.press('Enter');
  await page.getByText('Аккаунт связан с OpsVault.', { exact: false }).waitFor();
  assert.equal(await page.locator('form:has(input[value=password])').count(), 0);
  await page.setViewportSize({ width: 390, height: 844 });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await page.screenshot({ path: require('node:path').resolve(__dirname, '../.private/opsvault/sso-mobile.png'), fullPage: true });
  await page.getByRole('button', { name: 'Открыть руководство баера' }).click();
  await page.locator('#buyer-help').waitFor({ state: 'visible' });
  assert.ok((await page.locator('#buyer-help').innerText()).includes('Вход через OpsVault'));
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#buyer-help').isVisible(), false);
  // A provider document must also preserve the session on an ordinary SSO login.
  // With JS disabled the callback offers a same-origin continuation link.
  const fallback = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true,
    proxy: { server: process.argv[5], bypass: '127.0.0.1,localhost' } });
  const fallbackPage = await fallback.newPage();
  await fallbackPage.goto(base);
  await fallbackPage.getByRole('link', { name: 'Войти через OpsVault', exact: true }).click();
  await fallbackPage.getByRole('link', { name: 'Fixture sign in', exact: true }).click();
  await fallbackPage.getByRole('link', { name: 'Продолжить в Lead Bridge', exact: true }).click();
  await fallbackPage.getByRole('button', { name: 'Выйти', exact: true }).waitFor();
  assert.equal(await fallbackPage.locator('input[name=login]').count(), 0);
  assert.ok(!fallbackPage.url().includes('opsvault=callback'));
  await fallback.close();
  await page.goto(base + '?fixture_expire=1');
  await page.goto(base + '?page=settings');
  try { await page.getByRole('link', { name: 'Fixture sign in', exact: true }).waitFor({ timeout: 10000 }); }
  catch (error) { console.error('Fixture reauth page:', page.url(), (await page.locator('body').innerText()).slice(0,1600)); throw error; }
  assert.equal(new URL(page.url()).searchParams.get('prompt'), 'login');
  await page.getByRole('link', { name: 'Fixture sign in', exact: true }).focus();
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Выйти', exact: true }).waitFor();
  assert.equal(await page.locator('input[name=login]').count(), 0);
  assert.ok(!((await page.locator('body').innerText()).includes('Сессия OpsVault истекла')));
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await page.getByRole('link', { name: 'Войти через OpsVault', exact: true }).waitFor();
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  assert.deepEqual(errors, []);
  await page.locator('input[name=login]').fill('admin');
  await page.locator('input[name=password]').fill('recovery-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.goto(base + '?page=settings');
  await page.getByText('Подключение OpsVault', { exact: true }).waitFor();
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await page.goto(base + '?page=setup');
  const script = await page.locator('#apps-script').inputValue();
  assert.ok(script.includes('function bridgeTextColumns('));
  assert.ok(!script.includes('function bridgeConfigureForm('));
  assert.ok(script.includes('function bridgePrepareConfiguredFutureRows('));
  assert.ok((await page.locator('main').innerText()).includes('минимум из 500 пустых строк'));
  assert.ok((await page.locator('main').innerText()).includes('уже округлённые значения нужно восстановить из TikTok'));
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await page.getByRole('link', { name: 'Скачать LeadBridge.gs', exact: true }).focus();
  const downloading = page.waitForEvent('download');
  await page.keyboard.press('Enter');
  const download = await downloading, stream = await download.createReadStream(), chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  assert.equal(Buffer.concat(chunks).toString('utf8'), script);
  assert.equal(download.suggestedFilename(), 'LeadBridge.gs');
  assert.deepEqual(errors, []);
  console.log('PASS browser: updated text-ID instructions fit narrow screen; keyboard download matches the copy source exactly');
  console.log('PASS browser: expired session opens provider login and returns, keyboard login/link/confirm, Strict cookies, SSO without JS, narrow screen, help/Escape, logout, no JS errors');
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
