import express from 'express';
import { randomBytes, scryptSync, timingSafeEqual, randomUUID } from 'node:crypto';
import { readStore, updateStore, daysRemaining } from './store.js';
const app=express(), port=Number(process.env.PORT || 3000);
app.disable('x-powered-by');
app.use(express.json({limit:'32kb'}));
app.use((req,res,next)=>{
 res.set('X-Content-Type-Options','nosniff');res.set('Referrer-Policy','same-origin');
 res.set('X-Frame-Options','DENY');
 if(req.path.startsWith('/api')) {
 res.set('Cache-Control','no-store');
 if(!['GET','HEAD'].includes(req.method)){
 const origin=req.get('origin');
 if(origin && origin!==`${req.protocol}://${req.get('host')}`)return res.status(403).json({error:'Asal permintaan tidak diizinkan.'});
 if(req.get('content-type')?.split(';')[0].trim()!=='application/json')return res.status(415).json({error:'Gunakan JSON.'});
 }
 }
 next();
});
const sessions=new Map(), attempts=new Map();
function hash(password,salt){return scryptSync(password,salt,64).toString('hex');}
function createSession(res){const id=randomBytes(32).toString('hex');sessions.set(id,Date.now()+7*86400000);res.cookie('session',id,{httpOnly:true,sameSite:'strict',secure:process.env.COOKIE_SECURE==='true',maxAge:7*86400000,path:'/'});}
function sessionId(req){return req.headers.cookie?.split(';').map(x=>x.trim()).find(x=>x.startsWith('session='))?.slice(8);}
app.get('/api/auth',async(req,res)=>{const db=await readStore();res.json({initialized:!!db.user,authenticated:(sessions.get(sessionId(req))||0)>Date.now(),username:(sessions.get(sessionId(req))||0)>Date.now()?db.user?.username:null});});
app.post('/api/auth/:action',async(req,res)=>{
 const {username,password}=req.body;
 if(req.params.action==='logout'){sessions.delete(sessionId(req));res.clearCookie('session');return res.json({ok:true});}
 if(!['setup','login'].includes(req.params.action))return res.sendStatus(404);
 const key=req.ip, recent=(attempts.get(key)||[]).filter(t=>t>Date.now()-15*60000);
 if(recent.length>=10)return res.status(429).json({error:'Terlalu banyak percobaan. Coba lagi dalam 15 menit.'});
 attempts.set(key,[...recent,Date.now()]);
 if(typeof username!=='string'||username.length<3||username.length>60||typeof password!=='string'||password.length<12||password.length>256)return res.status(400).json({error:'Nama pengguna minimal 3 karakter dan kata sandi minimal 12 karakter.'});
 if(req.params.action==='setup'){
 const salt=randomBytes(16).toString('hex');
 const created=await updateStore(db=>{if(db.user)return false;db.user={username,salt,hash:hash(password,salt)};return true;});
 if(!created)return res.status(409).json({error:'Akun sudah dibuat.'});
 }else{
 const {user}=await readStore();const salt=user?.salt || 'dummy-salt';const candidate=hash(password,salt);
 if(!user||username!==user.username||!timingSafeEqual(Buffer.from(candidate,'hex'),Buffer.from(user.hash,'hex')))return res.status(401).json({error:'Nama pengguna atau kata sandi salah.'});
 }
 attempts.delete(key);createSession(res);res.json({ok:true});
});
app.use('/api',(req,res,next)=>{if((sessions.get(sessionId(req))||0)<=Date.now())return res.status(401).json({error:'Silakan masuk kembali.'});next();});
app.get('/api/services',async(req,res)=>res.json((await readStore()).services));
function serviceInput(body){
 const keys=['name','client','website','provider','type','cycle','expires','cost','notes'];const s=Object.fromEntries(keys.map(k=>[k,typeof body[k]==='string'?body[k].trim():'']));
 if(!s.name||!s.client||s.name.length>120||s.client.length>120||Object.values(s).some(v=>v.length>2000)||!['domain','server'].includes(s.type)||!['monthly','yearly'].includes(s.cycle)||!/^\d{4}-\d{2}-\d{2}$/.test(s.expires)||!Number.isFinite(Date.parse(s.expires))||new Date(s.expires).toISOString().slice(0,10)!==s.expires||!Number.isFinite(Number(s.cost))||Number(s.cost)<0)throw new Error('Lengkapi nama, klien, tanggal, dan biaya yang valid.');
 if(s.website && !/^https?:\/\//i.test(s.website))s.website='https://'+s.website;
 if(s.website){const u=new URL(s.website);if(!['http:','https:'].includes(u.protocol))throw new Error('URL tidak valid.');}
 return s;
}
app.post('/api/services',async(req,res)=>{let s;try{s=serviceInput(req.body);}catch(e){return res.status(400).json({error:e.message});}s.id=randomUUID();await updateStore(db=>db.services.push(s));res.status(201).json(s);});
app.put('/api/services/:id',async(req,res)=>{let s;try{s=serviceInput(req.body);}catch(e){return res.status(400).json({error:e.message});}const ok=await updateStore(db=>{const i=db.services.findIndex(s=>s.id===req.params.id);if(i<0)return false;db.services[i]={...s,id:req.params.id};return true;});res.status(ok?200:404).json({ok});});
app.delete('/api/services/:id',async(req,res)=>{await updateStore(db=>{db.services=db.services.filter(s=>s.id!==req.params.id);});res.json({ok:true});});
app.get('/api/telegram',async(req,res)=>{const {telegram}=await readStore();res.json({...telegram,token:undefined,hasToken:!!telegram.token});});
app.put('/api/telegram',async(req,res)=>{
 const {token,chatId,enabled,days}=req.body;
 if(typeof chatId!=='string'||(chatId && !/^(-?\d+|@[A-Za-z0-9_]{5,})$/.test(chatId))||typeof enabled!=='boolean'||!Array.isArray(days)||days.length>12||days.some(x=>!Number.isInteger(x)||x<0||x>365)|| (token && (typeof token!=='string'||!/^\d+:[A-Za-z0-9_-]{20,}$/.test(token))))return res.status(400).json({error:'Token, chat ID, atau jadwal pengingat tidak valid.'});
 const ok=await updateStore(db=>{const next={token:token||db.telegram.token,chatId,enabled,days:[...new Set(days)].sort((a,b)=>b-a)};if(enabled&&(!next.token||!chatId||!days.length))return false;db.telegram=next;return true;});
 res.status(ok?200:400).json(ok?{ok:true}:{error:'Isi token, chat ID, dan jadwal sebelum mengaktifkan pengingat.'});
});
async function sendTelegram(config,text){
 if(!config.token||!config.chatId)throw new Error('Simpan token bot dan chat ID terlebih dahulu.');
 const response=await fetch(`https://api.telegram.org/bot${config.token}/sendMessage`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({chat_id:config.chatId,text}),signal:AbortSignal.timeout(15000)});
 const result=await response.json();if(!result.ok)throw new Error('Telegram menolak pengiriman. Periksa token, chat ID, dan pastikan bot sudah menerima /start.');
}
app.post('/api/telegram/test',async(req,res)=>{try{await sendTelegram((await readStore()).telegram,'✓ Webpantau terhubung. Pengingat domain dan server akan dikirim ke chat ini.');res.json({ok:true});}catch(e){res.status(400).json({error:e.message.includes('Telegram')||e.message.includes('Simpan')?e.message:'Tidak dapat terhubung ke Telegram. Periksa akses jaringan.'});}});
let checking=false;
async function reminders(){if(checking)return;checking=true;try{const db=await readStore();if(!db.telegram.enabled)return;
 for(const s of db.services){const days=daysRemaining(s.expires),key=`${s.id}:${s.expires}:${days}`;if(!db.telegram.days.includes(days)||db.sent.includes(key))continue;
 await sendTelegram(db.telegram,`🔔 ${s.type==='domain'?'Domain':'Server'} ${s.name}\nKlien: ${s.client}\nJatuh tempo: ${s.expires}\n${days===0?'Jatuh tempo hari ini':`Tersisa ${days} hari`}\nBiaya: Rp ${Number(s.cost).toLocaleString('id-ID')}\nPerbarui tanggal di Webpantau setelah diperpanjang.`);
 await updateStore(current=>{current.sent.push(key);current.sent=current.sent.slice(-5000);});
 }
 }catch{console.error('Pengingat Telegram gagal; periksa konfigurasi dan jaringan.');}finally{checking=false;}}
setInterval(reminders,60*60000).unref();
setInterval(()=>{for(const [id,expires]of sessions)if(expires<Date.now())sessions.delete(id);for(const [ip,times]of attempts)if(times.every(t=>t<Date.now()-15*60000))attempts.delete(ip);},60000).unref();
if(process.env.NODE_ENV==='production'){app.use(express.static('dist'));app.get('/{*path}',(req,res)=>res.sendFile('index.html',{root:'dist'}));}else{const {createServer}=await import('vite');const vite=await createServer({server:{middlewareMode:true},appType:'spa'});app.use(vite.middlewares);}
app.use((err,req,res,next)=>{console.error('Permintaan gagal:',err.code || err.name);res.status(500).json({error:'Terjadi kesalahan server.'});});
app.listen(port,'0.0.0.0',()=>{console.log(`Webpantau berjalan pada port ${port}`);reminders();});
