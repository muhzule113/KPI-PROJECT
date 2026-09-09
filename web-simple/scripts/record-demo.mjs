import { spawn } from "node:child_process";
import { createReadStream, createWriteStream, existsSync } from "node:fs";
import { mkdir, mkdtemp, readFile, rm, stat } from "node:fs/promises";
import { createServer } from "node:http";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { dirname, join, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const projectDir = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const require = createRequire(import.meta.url);
const WebSocketClient = require(join(projectDir, "node_modules", "next", "dist", "compiled", "ws"));
const outputPath = join(projectDir, "kpi-harian-demo.webm");
const previewPath = join(projectDir, "kpi-harian-demo-preview.png");
const fontPath = join(projectDir, "src", "app", "fonts", "BricolageGrotesque-Variable.ttf");
const appUrl = "http://localhost:3002";
const fps = 30;
const width = 1280;
const height = 720;

const delay = (ms) => new Promise((done) => setTimeout(done, ms));

function readEnv(text) {
  return Object.fromEntries(text.split(/\r?\n/).flatMap((line) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#") || !trimmed.includes("=")) return [];
    const index = trimmed.indexOf("=");
    return [[trimmed.slice(0, index), trimmed.slice(index + 1).replace(/^(['"])(.*)\1$/, "$2")]];
  }));
}

function chromeExecutable() {
  const candidates = [
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
  ];
  return candidates.find(existsSync);
}

class Cdp {
  constructor(url) {
    this.socket = new WebSocketClient(url, { perMessageDeflate: false });
    this.sequence = 0;
    this.pending = new Map();
  }

  async connect() {
    await new Promise((resolveOpen, reject) => {
      this.socket.once("open", resolveOpen);
      this.socket.once("error", reject);
    });
    this.socket.on("message", (data) => {
      const message = JSON.parse(data.toString());
      if (!message.id) return;
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message));
      else pending.resolve(message.result);
    });
  }

  send(method, params = {}) {
    const id = ++this.sequence;
    return new Promise((resolveResult, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`CDP timeout: ${method}`));
      }, 20_000);
      this.pending.set(id, {
        resolve: (value) => { clearTimeout(timer); resolveResult(value); },
        reject: (error) => { clearTimeout(timer); reject(error); },
      });
      this.socket.send(JSON.stringify({ id, method, params }), (error) => {
        if (!error) return;
        clearTimeout(timer);
        this.pending.delete(id);
        reject(error);
      });
    });
  }

  close() {
    this.socket.terminate();
  }
}

async function waitFor(check, label, timeout = 20_000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    try {
      if (await check()) return;
    } catch {
      // Navigasi Next.js dapat mengganti execution context di tengah polling.
    }
    await delay(120);
  }
  throw new Error(`Timeout menunggu ${label}.`);
}

async function evaluate(cdp, expression) {
  const result = await cdp.send("Runtime.evaluate", {
    expression,
    returnByValue: true,
    awaitPromise: true,
    userGesture: true,
  });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
}

async function navigate(cdp, url, text) {
  await cdp.send("Page.navigate", { url });
  await waitFor(async () => evaluate(cdp, `document.readyState === "complete" && document.body.innerText.includes(${JSON.stringify(text)})`), text);
  await delay(300);
}

async function setViewport(cdp, viewportWidth, viewportHeight, mobile = false) {
  await cdp.send("Emulation.setDeviceMetricsOverride", {
    width: viewportWidth,
    height: viewportHeight,
    deviceScaleFactor: 1,
    mobile,
    screenWidth: viewportWidth,
    screenHeight: viewportHeight,
  });
}

async function screenshot(cdp, path) {
  await evaluate(cdp, `(() => {
    document.querySelectorAll("nextjs-portal").forEach((node) => node.remove());
    document.documentElement.style.scrollBehavior = "auto";
    window.scrollTo(0, 0);
    return true;
  })()`);
  await delay(150);
  const { data } = await cdp.send("Page.captureScreenshot", {
    format: "png",
    captureBeyondViewport: false,
    fromSurface: true,
  });
  await import("node:fs/promises").then(({ writeFile }) => writeFile(path, Buffer.from(data, "base64")));
}

async function captureScenes(cdp, tempRoot, password) {
  const paths = Object.fromEntries(["login", "dashboard", "daily", "recap", "detail", "mobile"].map((name) => [name, join(tempRoot, `${name}.png`)]));

  await setViewport(cdp, 1440, 810);
  await navigate(cdp, `${appUrl}/login`, "Masuk ke KPI Harian");
  await screenshot(cdp, paths.login);

  await evaluate(cdp, `(() => {
    document.querySelector("#username").value = "manager";
    document.querySelector("#password").value = ${JSON.stringify(password)};
    document.querySelector("form").requestSubmit();
    return true;
  })()`);
  await waitFor(async () => evaluate(cdp, `location.pathname === "/app" && document.body.innerText.includes("Selamat datang, Manager Toko")`), "dashboard Manager");
  await screenshot(cdp, paths.dashboard);

  await navigate(cdp, `${appUrl}/app/harian?date=2026-08-15`, "Nilai Supervisor");
  await screenshot(cdp, paths.daily);

  await navigate(cdp, `${appUrl}/app/rekap`, "Hasil KPI per pegawai");
  await evaluate(cdp, `document.querySelectorAll('[role="combobox"]')[0].click()`);
  await waitFor(async () => evaluate(cdp, `[...document.querySelectorAll('[role="option"]')].some((node) => node.textContent.includes("Agustus 2026"))`), "opsi Agustus 2026");
  await evaluate(cdp, `[...document.querySelectorAll('[role="option"]')].find((node) => node.textContent.includes("Agustus 2026")).click()`);
  await evaluate(cdp, `[...document.querySelectorAll('button')].find((node) => node.textContent.trim() === "Tampilkan").click()`);
  await waitFor(async () => evaluate(cdp, `location.search.includes("periodId=") && document.body.innerText.includes("38 pegawai dalam cakupan Anda")`), "rekap Agustus 2026");
  await screenshot(cdp, paths.recap);

  const detailUrl = await evaluate(cdp, `[...document.querySelectorAll('a')].find((node) => node.textContent.trim() === "Rincian")?.href`);
  if (!detailUrl) throw new Error("Rincian KPI demo tidak ditemukan.");
  await navigate(cdp, detailUrl, "Akumulasi indikator");
  await screenshot(cdp, paths.detail);

  await setViewport(cdp, 390, 844, true);
  await navigate(cdp, `${appUrl}/app`, "Selamat datang, Manager Toko");
  await screenshot(cdp, paths.mobile);

  return paths;
}

function recorderHtml(paths) {
  const scenes = [
    { type: "intro", duration: 3.5, image: "/scene/login.png" },
    { type: "desktop", duration: 5, image: "/scene/login.png", step: "AKSES BERBASIS PERAN", title: "Masuk sesuai peran kerja", body: "Satu pintu untuk Supervisor, Manager, Admin, dan Pegawai.", focus: [62, 132, 450, 595] },
    { type: "desktop", duration: 6, image: "/scene/dashboard.png", step: "RINGKASAN", title: "Prioritas langsung terlihat", body: "Antrean, progres, dan pekerjaan berikutnya tersaji sejak layar pertama.", focus: [308, 268, 1060, 350] },
    { type: "desktop", duration: 6, image: "/scene/daily.png", step: "PENILAIAN HARIAN", title: "Nilai KPI dari satu layar", body: "Pilih tanggal, cek status kerja, lalu isi indikator yang relevan.", focus: [610, 360, 745, 410] },
    { type: "desktop", duration: 6, image: "/scene/recap.png", step: "REKAP BULANAN", title: "Pantau 38 pegawai per periode", body: "Kelengkapan hari, nilai, dan status final mudah dipindai.", focus: [306, 382, 1048, 365] },
    { type: "desktop", duration: 6, image: "/scene/detail.png", step: "HASIL FINAL", title: "Nilai tetap transparan", body: "Skor akhir dapat ditelusuri hingga aktual, target, bobot, dan riwayat harian.", focus: [610, 270, 744, 405] },
    { type: "mobile", duration: 5, image: "/scene/mobile.png" },
    { type: "outro", duration: 3.5 },
  ];

  return `<!doctype html>
<html lang="id"><head><meta charset="utf-8"><style>
@font-face{font-family:Bricolage;src:url('/font.ttf')}*{box-sizing:border-box}html,body{margin:0;background:#03110d;overflow:hidden}canvas{display:block}
</style></head><body><canvas id="video" width="${width}" height="${height}"></canvas><script>
const W=${width},H=${height},FPS=${fps},TRANSITION=.5,scenes=${JSON.stringify(scenes)};
const canvas=document.querySelector('#video'),ctx=canvas.getContext('2d',{alpha:false});
const ease=t=>t<.5?2*t*t:1-Math.pow(-2*t+2,2)/2;
const clamp=t=>Math.max(0,Math.min(1,t));
function roundRect(x,y,w,h,r){ctx.beginPath();ctx.roundRect(x,y,w,h,r)}
function background(){
  const alpha=ctx.globalAlpha,g=ctx.createRadialGradient(W*.72,H*.22,20,W*.55,H*.45,W*.8);g.addColorStop(0,'#143c2f');g.addColorStop(.45,'#082019');g.addColorStop(1,'#020b08');ctx.fillStyle=g;ctx.fillRect(0,0,W,H);
  ctx.globalAlpha=alpha*.14;ctx.strokeStyle='#9bff65';ctx.lineWidth=1;for(let x=-H;x<W;x+=54){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x+H,H);ctx.stroke()}ctx.globalAlpha=alpha;
}
function brand(x,y,size=44){ctx.fillStyle='#d7ff32';roundRect(x,y,size,size,11);ctx.fill();ctx.fillStyle='#07120d';ctx.font='800 '+Math.round(size*.48)+'px Bricolage';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText('K',x+size/2,y+size/2+1);ctx.textAlign='left';ctx.textBaseline='alphabetic'}
function pill(text,x,y){ctx.font='700 14px Bricolage';const w=ctx.measureText(text).width+28;ctx.fillStyle='rgba(215,255,50,.11)';ctx.strokeStyle='rgba(215,255,50,.42)';roundRect(x,y,w,34,17);ctx.fill();ctx.stroke();ctx.fillStyle='#d7ff32';ctx.fillText(text,x+14,y+22);return w}
function loadImage(src){return new Promise((resolve,reject)=>{const image=new Image();image.onload=()=>resolve(image);image.onerror=reject;image.src=src})}
function drawCover(image,progress){const zoom=1+.016*ease(progress);const scale=Math.max(W/image.width,H/image.height)*zoom;const w=image.width*scale,h=image.height*scale;const x=(W-w)/2,y=(H-h)/2;ctx.drawImage(image,x,y,w,h);return{scale,x,y}}
function drawHighlight(focus,transform,time){if(!focus)return;const [x,y,w,h]=focus;const pulse=.58+.22*Math.sin(time*4);ctx.save();ctx.strokeStyle='rgba(215,255,50,'+pulse+')';ctx.lineWidth=3;ctx.shadowColor='rgba(215,255,50,.5)';ctx.shadowBlur=18;roundRect(transform.x+x*transform.scale,transform.y+y*transform.scale,w*transform.scale,h*transform.scale,14);ctx.stroke();ctx.restore()}
function caption(scene,progress){const enter=ease(clamp(progress/.18));const y=H-172+(1-enter)*24;ctx.save();ctx.globalAlpha=enter;const g=ctx.createLinearGradient(0,H-260,0,H);g.addColorStop(0,'rgba(2,11,8,0)');g.addColorStop(.35,'rgba(2,11,8,.76)');g.addColorStop(1,'rgba(2,11,8,.98)');ctx.fillStyle=g;ctx.fillRect(0,H-280,W,280);ctx.fillStyle='#d7ff32';ctx.font='750 15px Bricolage';ctx.fillText(scene.step,56,y-54);ctx.fillStyle='#f4f7f2';ctx.font='760 43px Bricolage';ctx.fillText(scene.title,56,y);ctx.fillStyle='#b8c7bf';ctx.font='450 20px Bricolage';ctx.fillText(scene.body,56,y+39);ctx.restore()}
function drawDesktop(scene,progress,time,image,alpha=1){ctx.save();ctx.globalAlpha=alpha;const transform=drawCover(image,progress);const shade=ctx.createLinearGradient(0,0,W,0);shade.addColorStop(0,'rgba(1,8,6,.12)');shade.addColorStop(1,'rgba(1,8,6,0)');ctx.fillStyle=shade;ctx.fillRect(0,0,W,H);drawHighlight(scene.focus,transform,time);caption(scene,progress);ctx.restore()}
function drawIntro(progress,image,alpha=1){ctx.save();ctx.globalAlpha=alpha;background();const enter=ease(clamp(progress/.28));brand(68,66,54);ctx.fillStyle='#f3f7f4';ctx.font='760 72px Bricolage';ctx.fillText('KPI Harian',68,224+(1-enter)*26);ctx.fillStyle='#c1d0c8';ctx.font='450 25px Bricolage';ctx.fillText('Dari catatan harian menuju hasil bulanan.',68,270+(1-enter)*26);let x=68;x+=pill('PENILAIAN',x,315)+12;x+=pill('REVIEW',x,315)+12;pill('REKAP',x,315);ctx.fillStyle='#8fa49a';ctx.font='450 18px Bricolage';ctx.fillText('Demo web-simple · Sistem KPI Toko & Servis HP',68,642);ctx.save();ctx.translate(762,92);ctx.rotate(-.035+.01*progress);ctx.shadowColor='rgba(0,0,0,.55)';ctx.shadowBlur=36;ctx.fillStyle='#0a1813';roundRect(-12,-12,538,350,22);ctx.fill();roundRect(0,0,514,326,14);ctx.clip();ctx.drawImage(image,0,0,514,326);ctx.restore();ctx.restore()}
function drawMobile(progress,image,alpha=1){ctx.save();ctx.globalAlpha=alpha;background();brand(70,66,50);ctx.fillStyle='#d7ff32';ctx.font='750 15px Bricolage';ctx.fillText('WEB RESPONSIF',70,183);ctx.fillStyle='#f4f7f2';ctx.font='760 57px Bricolage';ctx.fillText('Nyaman dipakai',70,252);ctx.fillText('dari ponsel',70,313);ctx.fillStyle='#b8c7bf';ctx.font='450 22px Bricolage';ctx.fillText('Navigasi utama selalu dekat,',70,370);ctx.fillText('bahkan saat tim bekerja di lantai toko.',70,402);let x=70;x+=pill('CEPAT',x,470)+12;pill('FOKUS',x,470);const phoneH=660,phoneW=phoneH*image.width/image.height,px=890-phoneW/2+(1-ease(clamp(progress/.2)))*48,py=30;ctx.shadowColor='rgba(0,0,0,.65)';ctx.shadowBlur=40;ctx.fillStyle='#07110d';roundRect(px-11,py-11,phoneW+22,phoneH+22,34);ctx.fill();roundRect(px,py,phoneW,phoneH,25);ctx.clip();ctx.drawImage(image,px,py,phoneW,phoneH);ctx.restore()}
function drawOutro(progress,alpha=1){ctx.save();ctx.globalAlpha=alpha;background();brand(W/2-29,112,58);ctx.fillStyle='#f4f7f2';ctx.font='760 70px Bricolage';ctx.textAlign='center';ctx.fillText('Catat. Review. Finalkan.',W/2,302);ctx.fillStyle='#b8c7bf';ctx.font='450 24px Bricolage';ctx.fillText('Satu alur KPI yang jelas untuk seluruh tim.',W/2,352);ctx.fillStyle='#d7ff32';roundRect(W/2-110,425,220,48,24);ctx.fill();ctx.fillStyle='#07120d';ctx.font='750 16px Bricolage';ctx.fillText('KPI HARIAN',W/2,456);ctx.fillStyle='#779085';ctx.font='450 16px Bricolage';ctx.fillText('web-simple · 2026',W/2,625);ctx.textAlign='left';ctx.restore()}
function drawScene(scene,progress,time,image,alpha){if(scene.type==='intro')drawIntro(progress,image,alpha);else if(scene.type==='desktop')drawDesktop(scene,progress,time,image,alpha);else if(scene.type==='mobile')drawMobile(progress,image,alpha);else drawOutro(progress,alpha)}
const total=scenes.reduce((sum,scene)=>sum+scene.duration,0);
async function run(){await document.fonts.ready;const images={};for(const scene of scenes){if(scene.image&&!images[scene.image])images[scene.image]=await loadImage(scene.image)}const stream=canvas.captureStream(FPS);const mime=['video/webm;codecs=vp9','video/webm;codecs=vp8','video/webm'].find(MediaRecorder.isTypeSupported);if(!mime)throw new Error('MediaRecorder WebM tidak tersedia');const chunks=[];const recorder=new MediaRecorder(stream,{mimeType:mime,videoBitsPerSecond:8_000_000});recorder.ondataavailable=event=>{if(event.data.size)chunks.push(event.data)};recorder.onstop=async()=>{const blob=new Blob(chunks,{type:mime});const response=await fetch('/upload',{method:'POST',headers:{'content-type':mime},body:blob});if(!response.ok)throw new Error('Upload hasil gagal');document.body.dataset.done='true'};recorder.start(1000);const started=performance.now();function frame(now){const elapsed=Math.min(total,(now-started)/1000);let cursor=0,index=0;for(;index<scenes.length-1&&elapsed>=cursor+scenes[index].duration;index++)cursor+=scenes[index].duration;const scene=scenes[index],local=elapsed-cursor,progress=clamp(local/scene.duration);ctx.clearRect(0,0,W,H);drawScene(scene,progress,elapsed,images[scene.image],1);if(index<scenes.length-1&&local>scene.duration-TRANSITION){const blend=ease(clamp((local-scene.duration+TRANSITION)/TRANSITION));drawScene(scenes[index+1],0,elapsed,images[scenes[index+1].image],blend)}if(elapsed<total)requestAnimationFrame(frame);else setTimeout(()=>recorder.stop(),180)}requestAnimationFrame(frame)}
run().catch(error=>{document.body.dataset.error=error.message});
</script></body></html>`;
}

async function startRecorderServer(paths) {
  let finishUpload;
  const uploaded = new Promise((resolveUpload) => { finishUpload = resolveUpload; });
  const html = recorderHtml(paths);
  const routes = Object.fromEntries(Object.entries(paths).map(([name, path]) => [`/scene/${name}.png`, path]));
  const server = createServer((request, response) => {
    if (request.url === "/") {
      response.writeHead(200, { "content-type": "text/html; charset=utf-8" });
      response.end(html);
      return;
    }
    if (request.url === "/font.ttf") {
      response.writeHead(200, { "content-type": "font/ttf" });
      createReadStream(fontPath).pipe(response);
      return;
    }
    if (routes[request.url]) {
      response.writeHead(200, { "content-type": "image/png" });
      createReadStream(routes[request.url]).pipe(response);
      return;
    }
    if (request.url === "/upload" && request.method === "POST") {
      const output = createWriteStream(outputPath);
      request.pipe(output);
      output.on("finish", () => { response.end("ok"); finishUpload(); });
      output.on("error", (error) => { response.writeHead(500); response.end(error.message); });
      return;
    }
    if (request.url === "/output.webm") {
      response.writeHead(200, { "content-type": "video/webm" });
      createReadStream(outputPath).pipe(response);
      return;
    }
    if (request.url === "/verify") {
      response.writeHead(200, { "content-type": "text/html; charset=utf-8" });
      response.end(`<style>html,body{margin:0;background:#000}video{display:block;width:1280px;height:720px}</style><video id="result" src="/output.webm" muted></video><script>const video=document.querySelector('#result');video.onloadedmetadata=()=>document.body.dataset.metadata=JSON.stringify({duration:video.duration,width:video.videoWidth,height:video.videoHeight});video.onerror=()=>document.body.dataset.error='decode';</script>`);
      return;
    }
    response.writeHead(404); response.end("not found");
  });
  await new Promise((resolveListen) => server.listen(0, "127.0.0.1", resolveListen));
  return { server, uploaded, port: server.address().port };
}

async function main() {
  const env = readEnv(await readFile(join(projectDir, ".env"), "utf8"));
  if (!env.SEED_DEMO_PASSWORD) throw new Error("SEED_DEMO_PASSWORD tidak ditemukan di .env.");
  const health = await fetch(`${appUrl}/api/health`).catch(() => null);
  if (!health?.ok) throw new Error(`Jalankan web-simple di ${appUrl} sebelum merekam.`);

  const chrome = chromeExecutable();
  if (!chrome) throw new Error("Google Chrome atau Microsoft Edge tidak ditemukan.");
  const tempRoot = await mkdtemp(join(tmpdir(), "kpi-demo-"));
  const profile = join(tempRoot, "profile");
  await mkdir(profile);
  const chromeProcess = spawn(chrome, [
    "--headless=new", "--hide-scrollbars", "--disable-gpu", "--disable-gpu-shader-disk-cache", "--no-first-run", "--no-default-browser-check",
    "--disable-background-networking", "--disable-sync", "--remote-debugging-port=0", "--remote-allow-origins=*",
    `--user-data-dir=${profile}`, "about:blank",
  ], { stdio: "ignore", windowsHide: true });

  let cdp;
  let recorderServer;
  try {
    const portFile = join(profile, "DevToolsActivePort");
    await waitFor(async () => readFile(portFile, "utf8").then(() => true).catch(() => false), "Chrome DevTools");
    const port = Number((await readFile(portFile, "utf8")).split(/\r?\n/)[0]);
    const page = await fetch(`http://127.0.0.1:${port}/json/new?about%3Ablank`, { method: "PUT" }).then((response) => response.json());
    cdp = new Cdp(page.webSocketDebuggerUrl);
    await cdp.connect();
    await Promise.all([cdp.send("Page.enable"), cdp.send("Runtime.enable")]);

    const paths = await captureScenes(cdp, tempRoot, env.SEED_DEMO_PASSWORD);
    recorderServer = await startRecorderServer(paths);
    await setViewport(cdp, width, height);
    await cdp.send("Page.navigate", { url: `http://127.0.0.1:${recorderServer.port}/` });
    await waitFor(async () => evaluate(cdp, "Boolean(document.querySelector('canvas'))"), "kanvas video");
    await delay(800);
    await screenshot(cdp, previewPath);
    let renderTimer;
    try {
      await Promise.race([
        recorderServer.uploaded,
        new Promise((_, reject) => {
          renderTimer = setTimeout(async () => {
            const error = await evaluate(cdp, "document.body.dataset.error").catch(() => "");
            reject(new Error(error || "Render video melewati batas waktu."));
          }, 90_000);
        }),
      ]);
    } finally {
      clearTimeout(renderTimer);
    }

    await cdp.send("Page.navigate", { url: `http://127.0.0.1:${recorderServer.port}/verify` });
    await waitFor(async () => evaluate(cdp, "Boolean(document.body.dataset.metadata || document.body.dataset.error)"), "metadata video");
    const metadata = JSON.parse(await evaluate(cdp, "document.body.dataset.metadata"));
    const file = await stat(outputPath);
    const preview = await stat(previewPath);
    if (metadata.width !== width || metadata.height !== height || file.size < 100_000 || preview.size < 50_000) throw new Error("Validasi hasil video gagal.");
    console.log(JSON.stringify({ output: outputPath, preview: previewPath, bytes: file.size, ...metadata }));
  } finally {
    cdp?.close();
    recorderServer?.server.closeAllConnections();
    recorderServer?.server.close();
    recorderServer?.server.unref();
    if (chromeProcess.exitCode === null) {
      chromeProcess.kill();
      await Promise.race([new Promise((done) => chromeProcess.once("exit", done)), delay(2_000)]);
      chromeProcess.unref();
    }
    const tempBase = resolve(tmpdir()) + sep;
    if (resolve(tempRoot).startsWith(tempBase)) await rm(tempRoot, { recursive: true, force: true }).catch(() => {});
  }
}

await main();
