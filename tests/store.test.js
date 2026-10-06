import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
const dir=await mkdtemp(path.join(os.tmpdir(),'webpantau-store-'));
process.env.DATA_DIR=dir;
process.env.TZ='Asia/Makassar';
const {readStore,updateStore,daysRemaining}=await import('../server/store.js');
test('serializes concurrent updates without losing records; writes valid JSON',async()=>{
 await Promise.all(Array.from({length:25},(_,i)=>updateStore(db=>db.services.push({id:i}))));
 assert.equal((await readStore()).services.length,25);
 assert.equal(JSON.parse(await readFile(path.join(dir,'dashboard.json'),'utf8')).services.length,25);
 await rm(dir,{recursive:true});
});
test('reminder dates use Makassar calendar boundary',()=>{
 assert.equal(daysRemaining('2026-10-08',new Date('2026-10-06T17:00:00Z')),1);
 assert.equal(daysRemaining('2026-10-06',new Date('2026-10-06T17:00:00Z')),-1);
});
