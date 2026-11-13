// Optional browser integration test: disposable seeded site only. See docs/VALIDATION.md.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.AL_PLAYWRIGHT || 'playwright');
(async () => {
  const fixture = JSON.parse(fs.readFileSync(process.env.AL_FIXTURE, 'utf8'));
  const base = process.env.AL_BASE || 'http://127.0.0.1:9413';
  const out = process.env.AL_ARTIFACT_DIR || '/tmp/access-ledger-browser';
  fs.mkdirSync(out, {recursive:true});
  const browser = await chromium.launch({headless:true, executablePath:process.env.AL_CHROME || undefined});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1100}});
    page.setDefaultTimeout(30000);
    await page.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const before = await page.request.get(fixture.url);
    assert.equal(before.status(),403,'Mismatched email rejects actual download');
    await page.goto(base+'/wp-login.php', {waitUntil:'domcontentloaded'});
    await page.locator('#user_login').fill(process.env.AL_USER || 'admin');
    await page.locator('#user_pass').fill(process.env.AL_PASSWORD);
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', {waitUntil:'domcontentloaded'}).catch(async e=>{console.log('LOGIN',page.url(),(await page.locator('body').innerText()).slice(0,700));throw e;});
    await page.goto(base+'/wp-admin/admin.php?page=access-ledger',{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>document.getElementById('al-total-orders')?.textContent!=='—');
    await page.locator('#al-order-id').fill(String(fixture.order_id));
    await page.getByRole('button',{name:'Explain access',exact:true}).click();
    await page.getByRole('button',{name:'Preview email correction',exact:true}).click();
    await page.locator('#al-confirm-email').waitFor({state:'visible'});
    await page.screenshot({path:path.join(out,'preview.png')});
    await page.locator('#al-confirm-email').fill('incorrect@example.test');
    await page.locator('#al-repair-confirm').click();
    assert.equal(await page.locator('#al-confirm-error').isVisible(),true,'Exact email confirmation required');
    await page.locator('#al-confirm-email').fill(fixture.email);
    await page.locator('#al-repair-confirm').click();
    await page.waitForFunction(()=>document.getElementById('al-notice').textContent.includes('corrected'));
    const after = await page.request.get(fixture.url);
    assert.equal(after.status(),200,'Corrected identity allows native WooCommerce download');
    assert.equal(await after.text(),'Access Ledger delivery fixture');
    await page.locator('#al-history-content').getByRole('button',{name:'Review undo'}).first().click();
    await page.locator('#al-undo-confirm').click();
    await page.waitForFunction(()=>document.getElementById('al-notice').textContent.includes('restored'));
    assert.equal((await page.request.get(fixture.url)).status(),403,'Undo restores previous link behavior');
    const denied = await page.evaluate(async()=>{const r=await fetch(accessLedger.root+'scan',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"days":30}'});return r.status;});
    assert.ok([401,403].includes(denied),'Cookie mutation without REST nonce denied by authorization');
    await page.locator('#al-scan-start').click();
    await page.waitForFunction(()=>document.getElementById('al-scan-status').textContent==='Scanning');
    await page.waitForFunction(()=>document.getElementById('al-scan-status').textContent==='Complete',null,{timeout:120000});
    const download = page.waitForEvent('download');
    await page.locator('#al-export').click();
    const exported = await download;await exported.saveAs(path.join(out,'export.json'));
    const reports=JSON.parse(fs.readFileSync(path.join(out,'export.json'))).reports;
    assert.ok(Array.isArray(reports) && reports.length>50 && reports.length<=1000, 'Export includes older persisted findings and remains capped');
    const csvDownload = page.waitForEvent('download');
    await page.locator('#al-export-csv').click();
    const csvExported = await csvDownload; await csvExported.saveAs(path.join(out,'export.csv'));
    assert.ok(fs.readFileSync(path.join(out,'export.csv'),'utf8').startsWith('order_id,order_number,status,paid,customer_id,customer_email,order_url,checked_at,issues,permissions\r\n'), 'CSV download is raw CSV with a header');
    assert.match(await page.locator('#al-report-scope').innerText(), /loaded orders only/);
    assert.equal(await page.locator('#al-reports-content tbody tr').count(),50,'Initial UI is bounded to 50 findings');
    await page.locator('#al-search').fill(fixture.email);
    assert.equal(await page.locator('#al-reports-content tbody tr').count(),0,'Search does not claim to include unloaded findings');
    await page.locator('#al-load-older').click();
    await page.waitForFunction(()=>!document.getElementById('al-load-older').disabled);
    assert.ok((await page.locator('#al-reports-content').innerText()).includes(fixture.email),'Search includes findings loaded beyond the first 50');
    await page.locator('#al-filter').selectOption('repairable');
    assert.ok((await page.locator('#al-reports-content').innerText()).includes(fixture.email),'Repairable filter applies to older loaded findings');
    await page.locator('#al-issue-filter').selectOption('exhausted');
    assert.equal(await page.locator('#al-reports-content tbody tr').count(),0,'Issue filter applies to older loaded findings');
    await page.locator('#al-filter').selectOption('all');
    await page.locator('#al-issue-filter').selectOption('all');
    await page.locator('#al-search').fill('');
    const loaded = await page.locator('#al-reports-content tbody tr').count();
    assert.equal(loaded,Math.min(100,reports.length),'Loading older appends one bounded page');
    const labels = await page.locator('#al-reports-content tbody button').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('aria-label')));
    assert.equal(new Set(labels).size,labels.length,'No duplicate findings across UI page boundaries');
    await Promise.all([
      page.waitForResponse(r=>decodeURIComponent(r.url()).includes('access-ledger/v1/dashboard') && r.status()===200),
      page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')))
    ]);
    assert.equal(await page.locator('#al-reports-content tbody tr').count(),loaded,'Background refresh preserves loaded findings for the same scan');
    await page.locator('#al-refresh').click();
    await page.waitForFunction(()=>!document.getElementById('al-refresh').disabled);
    assert.equal(await page.locator('#al-reports-content tbody tr').count(),50,'Explicit refresh resets to the latest page');
    const readDenials = await page.evaluate(async()=>Promise.all([
      ['reports',{scan_id:'00000000-0000-0000-0000-000000000000',before_id:'1'}], ['export',{format:'csv'}]
    ].map(async ([endpoint,params])=>{
      const url=new URL(accessLedger.root+endpoint);
      Object.entries(params).forEach(([key,value])=>url.searchParams.set(key,value));
      return (await fetch(url)).status;
    })));
    assert.ok(readDenials.every(status=>[401,403].includes(status)),'Pagination and CSV cookie requests without REST nonce denied by authorization');
    await page.evaluate(()=>wp.apiFetch({path:'/access-ledger/v1/scan',method:'POST',data:{days:30},headers:{'X-WP-Nonce':accessLedger.nonce}}));
    await page.locator('#al-load-older').click();
    await page.waitForFunction(()=>document.getElementById('al-notice').textContent.includes('previous scan were discarded'));
    assert.ok(await page.locator('#al-reports-content tbody tr').count()<=50,'Stale cursor clears the old list and reloads only the new scan');
    await page.waitForFunction(()=>document.getElementById('al-scan-status').textContent==='Complete',null,{timeout:120000});
    if(await page.getByRole('button',{name:'Close details',exact:true}).isVisible()) await page.getByRole('button',{name:'Close details',exact:true}).click();
    await page.evaluate(()=>scrollTo(0,0));
    await page.screenshot({path:path.join(out,'dashboard.png'),fullPage:false});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:path.join(out,'mobile.png'),fullPage:true});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No mobile horizontal page overflow');
    assert.deepEqual(errors,[],'No JavaScript runtime errors');
    console.log('BROWSER: native download 403 → 200 → 403; preview, exact email, undo, nonce denial, scan, bounded pagination, loaded search/filters, CSV/JSON exports and 390px layout passed.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
