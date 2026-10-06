import { mkdir, readFile, writeFile, rename } from 'node:fs/promises';
import path from 'node:path';
export const directory = path.resolve(process.env.DATA_DIR || 'data');
const file = path.join(directory, 'dashboard.json');
let queue = Promise.resolve();
export async function readStore() {
  await queue;
  try { return JSON.parse(await readFile(file, 'utf8')); }
  catch(e) { if(e.code !== 'ENOENT') throw e; return {user:null,services:[],telegram:{token:'',chatId:'',enabled:false,days:[30,14,7,3,0]},sent:[]}; }
}
export function updateStore(fn) {
 const task = queue.then(async()=>{
  await mkdir(directory,{recursive:true,mode:0o700});
  let data;
  try {data=JSON.parse(await readFile(file,'utf8'));} catch(e){if(e.code!=='ENOENT')throw e;data={user:null,services:[],telegram:{token:'',chatId:'',enabled:false,days:[30,14,7,3,0]},sent:[]};}
  const result=await fn(data);
  await writeFile(file+'.tmp',JSON.stringify(data,null,2),{mode:0o600});
  await rename(file+'.tmp',file);
  return result;
 });
 queue=task.catch(()=>{});return task;
}
export function daysRemaining(date, now = new Date()) {
 const today = new Intl.DateTimeFormat('en-CA',{timeZone:process.env.TZ || 'Asia/Makassar',year:'numeric',month:'2-digit',day:'2-digit'}).format(now);
 return Math.round((Date.parse(date+'T00:00:00Z')-Date.parse(today+'T00:00:00Z'))/86400000);
}
