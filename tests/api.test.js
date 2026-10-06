import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtemp,rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
test('login protection, CRUD, Telegram configuration and logout',async()=>{
 const dir=await mkdtemp(path.join(os.tmpdir(),'webpantau-api-'));
 const server=spawn(process.execPath,['server/index.js'],{env:{...process.env,NODE_ENV:'production',PORT:'3199',DATA_DIR:dir},stdio:['ignore','pipe','pipe']});
 try{
 await new Promise((resolve,reject)=>{server.stdout.on('data',d=>{if(d.toString().includes('berjalan'))resolve();});server.on('exit',()=>reject(Error('Server stopped')));setTimeout(()=>reject(Error('Startup timeout')),10000).unref();});
 let cookie='';
 async function call(url,method='GET',body,origin){const r=await fetch('http://127.0.0.1:3199/api'+url,{method,headers:{'Content-Type':'application/json',Cookie:cookie,...(origin?{Origin:origin}:{})},body:body?JSON.stringify(body):undefined});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];return{status:r.status,data:await r.json()};}
 assert.equal((await call('/services')).status,401);
 assert.equal((await call('/auth')).data.initialized,false);
 assert.equal((await call('/auth/setup','POST',{username:'owner',password:'a-long-test-password'})).status,200);
 assert.equal((await call('/auth/setup','POST',{username:'owner',password:'a-long-test-password'})).status,409);
 assert.equal((await call('/services','POST',{},'https://evil.example')).status,403);
 const input={name:'example.com',client:'Example',website:'example.com',provider:'Registrar',type:'domain',cycle:'yearly',expires:'2027-04-20',cost:'250000',notes:''};
 assert.equal((await call('/services','POST',{...input,expires:'2027-02-30'})).status,400);
 const created=await call('/services','POST',input);assert.equal(created.status,201);
 assert.equal((await call('/services')).data[0].website,'https://example.com');
 assert.equal((await call('/services/'+created.data.id,'PUT',{...input,expires:'2028-04-20'})).status,200);
 assert.equal((await call('/services')).data[0].expires,'2028-04-20');
 assert.equal((await call('/telegram','PUT',{chatId:'12345',enabled:true,days:[7]})).status,400);
 const token='123456:abcdefghijklmnopqrstuvwxyz';
 assert.equal((await call('/telegram','PUT',{token,chatId:'12345',enabled:false,days:[30,7,0]})).status,200);
 const cfg=(await call('/telegram')).data;assert.equal(cfg.hasToken,true);assert.equal(cfg.token,undefined);
 await call('/telegram','PUT',{token:'',chatId:'12345',enabled:false,days:[7]});assert.equal((await call('/telegram')).data.hasToken,true);
 await call('/services/'+created.data.id,'DELETE');assert.equal((await call('/services')).data.length,0);
 await call('/auth/logout','POST',{});assert.equal((await call('/services')).status,401);
 assert.equal((await call('/auth/login','POST',{username:'owner',password:'wrong-long-password'})).status,401);
 assert.equal((await call('/auth/login','POST',{username:'owner',password:'a-long-test-password'})).status,200);
 }finally{server.kill();await new Promise(r=>server.once('exit',r));await rm(dir,{recursive:true,force:true});}
});
