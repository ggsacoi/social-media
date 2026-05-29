const { chromium } = require('@playwright/test');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('console', (msg) => console.log('PAGE_LOG>', msg.text()));

  let dialogInfo = null;
  page.on('dialog', async (dialog) => {
    dialogInfo = `${dialog.type()}:${dialog.message()}`;
    try {
      await dialog.dismiss();
    } catch (e) {
      // ignore
    }
  });

  const baseUrl = 'http://127.0.0.1/login-portfolio';
  await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });

  const testEmail = `playwright-test-${Date.now()}@example.com`;
  const testPassword = 'Password123!';
  const testUsername = `pwuser${Date.now()}`;

  await page.click('#login-form a:has-text("Register")', { timeout: 15000 });
  await page.waitForSelector('#register-form input[name="email"]:visible', { timeout: 15000 });
  await page.fill('#register-form input[name="username"]', testUsername);
  await page.fill('#register-form input[name="numero"]', '0000000000');
  await page.fill('#register-form input[name="email"]', testEmail);
  await page.fill('#register-form input[name="motdepasse"]', testPassword);

  const registration = Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }).catch(() => null),
    page.click('button[name="register"]'),
  ]);
  const [navResult] = await registration;
  if (!page.url().includes('user_page.php')) {
    console.log('Registration did not redirect to user_page.php, trying login. Current URL:', page.url());
    await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });
    await page.fill('#login-form input[name="email"]', testEmail);
    await page.fill('#login-form input[name="motdepasse"]', testPassword);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }),
      page.click('#login-form button[name="login"]'),
    ]);
  }

  if (!page.url().includes('user_page.php')) {
    throw new Error(`Cannot reach user_page.php after login/registration. Current URL: ${page.url()}`);
  }

  const statusText = await page.locator('#nsfwStatus').textContent();
  console.log('Initial nsfwStatus:', statusText && statusText.trim());

  await page.waitForFunction(() => {
    const status = document.getElementById('nsfwStatus');
    return status && (status.textContent.includes('Modèle NSFW chargé') || status.textContent.includes('Erreur'));
  }, { timeout: 90000 });

  const loadedStatus = await page.locator('#nsfwStatus').textContent();
  console.log('Loaded nsfwStatus:', loadedStatus && loadedStatus.trim());

  const mediaInput = await page.$('#post_mediaInput');
  if (!mediaInput) throw new Error('Unable to find post_mediaInput');
  await mediaInput.setInputFiles(path.resolve(__dirname, '..', 'preview.jpg'));
  await page.fill('input[name="legende"]', 'Playwright NSFW test image');

  const submitPromise = page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => null);
  await page.click('button[type="submit"].btn-send');
  const submitNav = await submitPromise;

  const finalUrl = page.url();
  console.log('After submit URL:', finalUrl);
  console.log('Dialog seen:', dialogInfo);
  console.log('Final nsfwStatus:', (await page.locator('#nsfwStatus').textContent())?.trim());
  console.log('Form navigation happened:', !!submitNav);
  const filesCount = await page.$eval('#post_mediaInput', input => input.files.length).catch(() => null);
  console.log('Has post form image file count:', filesCount);

  await browser.close();
})();
