#!/usr/bin/env node
/*
 * MotionForge render worker.
 *
 * Usage: node render.mjs <job.json>
 * job = {
 *   engineDir, storyFile, capsFile?, fontFile,
 *   mode: 'video' | 'still' | 'probe',
 *   out, from?, to?, at?, fps?, quality?, ffmpeg?, frameTimeoutMs?, totalTimeoutSec?, chrome?,
 *   preset?, crf?, workers?, width?, height?   // width/height: canvas size (default 1080x1920)   // workers: parallel Chrome pages for video mode (chunks joined losslessly)
 * }
 * Emits JSON lines on stdout: {type:'progress',frame,total} | {type:'done',...} | {type:'error',message,scene?}
 *
 * Isolation: the story code (AI-generated, untrusted) runs only inside headless Chrome with
 *  - a CSP that forbids network access, eval and the Function constructor,
 *  - every request aborted by request interception and DNS resolution disabled,
 *  - per-frame and total timeouts.
 * The caller starts this process with a scrubbed environment (no application secrets).
 */
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const { chromium } = await import(pathToFileURL(path.join(here, '../../node_modules/playwright-core/index.mjs')).href);

const emit = o => process.stdout.write(JSON.stringify(o) + '\n');
const encoders = [];
const fail = (message, extra = {}) => { emit({ type: 'error', message, ...extra }); encoders.forEach(p => p.kill('SIGKILL')); process.exit(2); };

const jobPath = process.argv[2];
if (!jobPath) fail('job file argument missing');
const job = JSON.parse(fs.readFileSync(jobPath, 'utf8'));
const fps = job.fps || 30;
const frameTimeout = job.frameTimeoutMs || 8000;
const totalTimeout = (job.totalTimeoutSec || 3600) * 1000;
const killer = setTimeout(() => fail(`render exceeded total timeout of ${totalTimeout / 1000}s`), totalTimeout);

const read = f => fs.readFileSync(f, 'utf8');
const width = job.width || 1080, height = job.height || 1920;
const esc = s => s.replace(/<\/script/gi, '<\\/script');
const font = fs.readFileSync(job.fontFile).toString('base64');
const parts = [
  `const MF_SIZE = { w: ${width}, h: ${height} };`,
  read(path.join(job.engineDir, 'core.js')),
  read(path.join(job.engineDir, 'bg.js')),
  job.capsFile ? read(job.capsFile) : '',
  read(job.storyFile),
  read(path.join(job.engineDir, 'engine.js')),
];
const html = `<!doctype html><html><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; font-src data:; img-src data:">
<style>@font-face{font-family:Poppins;font-weight:700;src:url(data:font/ttf;base64,${font}) format('truetype');}
html,body{margin:0;background:#000}canvas{display:block}</style></head>
<body><canvas id="stage" width="${width}" height="${height}"></canvas>
<div style="font:700 10px Poppins;position:absolute;opacity:0">.</div>
${parts.map(p => `<script>${esc(p)}</script>`).join('\n')}
</body></html>`;

const browser = await chromium.launch({
  executablePath: job.chrome || '/usr/bin/google-chrome',
  headless: true,
  args: ['--disable-extensions', '--disable-background-networking', '--disable-sync', '--no-first-run',
    '--disable-default-apps', '--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE localhost-never', '--mute-audio'],
});
try {
  /** Each page lives in its own browser context, so Chrome gives it its own renderer process (one CPU core). */
  const openPage = async () => {
    const context = await browser.newContext({ viewport: { width, height }, javaScriptEnabled: true, offline: true });
    await context.route('**/*', r => r.abort());
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(String(e.message || e)));
    await page.setContent(html, { waitUntil: 'load', timeout: 30000 });
    await page.evaluate(() => document.fonts.ready);
    const info = await page.evaluate(() => window.MF ? { duration: MF.duration, scenes: MF.scenes, starts: MF.starts } : null);
    if (!info) fail('story failed to load: ' + (pageErrors[0] || 'STORY or engine not defined'));
    if (pageErrors.length) fail('story script error: ' + pageErrors[0]);
    return { page, info };
  };
  const { page, info } = await openPage();

  const withTimeout = (p, ms, what) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error(`${what} timed out after ${ms}ms (possible infinite loop)`)), ms))]);
  const sceneOf = T => { let i = 0; for (let k = 0; k < info.scenes; k++) if (T >= info.starts[k]) i = k; return i; };
  const checkErrors = async (pg = page) => { const errs = await pg.evaluate(() => MF.errors.slice()); if (errs.length) fail(`scene ${errs[0].scene + 1} threw: ${errs[0].message}`, { scene: errs[0].scene, errors: errs }); };

  if (job.mode === 'probe') {
    // render one frame from the middle of each scene to surface runtime errors
    for (let i = 0; i < info.scenes; i++) {
      const T = (info.starts[i] + info.starts[i + 1]) / 2;
      try { await withTimeout(page.evaluate(T => { MF.frame(T); return 1; }, T), frameTimeout, `scene ${i + 1} frame`); }
      catch (e) { fail(e.message, { scene: i }); }
    }
    await checkErrors();
    emit({ type: 'done', mode: 'probe', ...info });
  } else if (job.mode === 'still') {
    const T = job.at ?? 0;
    let data;
    try { data = await withTimeout(page.evaluate(T => MF.png(T), T), frameTimeout, 'still frame'); }
    catch (e) { fail(e.message, { scene: sceneOf(T) }); }
    await checkErrors();
    fs.writeFileSync(job.out, Buffer.from(data.split(',')[1], 'base64'));
    emit({ type: 'done', mode: 'still', out: job.out, at: T });
  } else {
    const from = job.from ?? 0, to = job.to ?? info.duration;
    const f0 = Math.round(from * fps), f1 = Math.round(to * fps), total = f1 - f0;
    if (total <= 0) fail('empty frame range');
    const q = job.quality ?? 0.92;
    const minChunkFrames = 2 * fps;
    const workers = Math.max(1, Math.min(job.workers || 1, Math.floor(total / minChunkFrames)));
    const encode = out => {
      const ff = spawn(job.ffmpeg || 'ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'image2pipe', '-c:v', 'mjpeg', '-framerate', String(fps), '-i', '-',
        '-c:v', 'libx264', '-preset', job.preset || 'medium', '-crf', String(job.crf ?? 19), '-pix_fmt', 'yuv420p', '-r', String(fps), '-movflags', '+faststart', out], { stdio: ['pipe', 'ignore', 'pipe'] });
      let err = ''; ff.stderr.on('data', d => { err += d; });
      encoders.push(ff);
      return { ff, done: new Promise(res => ff.on('close', code => res(code === 0 ? null : 'ffmpeg failed: ' + err.slice(-800)))) };
    };

    let rendered = 0;
    const renderRange = async (pg, a, b, out) => {
      const { ff, done } = encode(out);
      for (let f = a; f < b; f++) {
        const T = f / fps;
        let data;
        try { data = await withTimeout(pg.evaluate(([T, q]) => MF.jpeg(T, q), [T, q]), frameTimeout, `frame ${f}`); }
        catch (e) { fail(e.message, { scene: sceneOf(T) }); }
        if ((f - a) % 15 === 0) await checkErrors(pg);
        const buf = Buffer.from(data.split(',')[1], 'base64');
        if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
        rendered++;
        if (rendered % 10 === 0 || rendered === total) emit({ type: 'progress', frame: rendered, total });
      }
      await checkErrors(pg);
      ff.stdin.end();
      const error = await done;
      if (error) fail(error);
    };

    if (workers === 1) {
      await renderRange(page, f0, f1, job.out);
    } else {
      // Split the frames into contiguous chunks rendered in parallel, then join them without re-encoding.
      const pages = [page, ...await Promise.all(Array.from({ length: workers - 1 }, () => openPage().then(p => p.page)))];
      const size = Math.ceil(total / workers);
      const chunks = pages.map((pg, k) => ({ pg, a: f0 + k * size, b: Math.min(f1, f0 + (k + 1) * size), out: `${job.out}.part${k}.mp4` })).filter(c => c.b > c.a);
      await Promise.all(chunks.map(c => renderRange(c.pg, c.a, c.b, c.out)));
      const list = `${job.out}.parts.txt`;
      fs.writeFileSync(list, chunks.map(c => `file '${c.out.replace(/'/g, `'\\''`)}'`).join('\n') + '\n');
      const { done } = (() => {
        const ff = spawn(job.ffmpeg || 'ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', '-movflags', '+faststart', job.out], { stdio: ['ignore', 'ignore', 'pipe'] });
        let err = ''; ff.stderr.on('data', d => { err += d; });
        encoders.push(ff);
        return { done: new Promise(res => ff.on('close', code => res(code === 0 ? null : 'ffmpeg concat failed: ' + err.slice(-800)))) };
      })();
      const error = await done;
      chunks.forEach(c => fs.rmSync(c.out, { force: true }));
      fs.rmSync(list, { force: true });
      if (error) fail(error);
    }
    emit({ type: 'done', mode: 'video', out: job.out, frames: total, from, to, workers });
  }
} catch (e) {
  fail(String(e && e.message || e));
} finally {
  clearTimeout(killer);
  await browser.close().catch(() => {});
}
process.exit(0);
