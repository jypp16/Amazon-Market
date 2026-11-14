const assert=require('node:assert/strict');
const fs=require('node:fs');const vm=require('node:vm');
(async()=>{
 let downloaded=false;
 const context={API_URL:'/api',Blob,Uint8Array,URL:{createObjectURL:()=> 'blob:test',revokeObjectURL(){}},
 fetch:async()=>({ok:true,status:200,headers:{get:n=>n==='content-type'?'application/pdf':''},blob:async()=>new Blob(['%PDF-1.4\n%%EOF'])}),
 document:{createElement:()=>({style:{},click(){downloaded=true},remove(){}}),body:{appendChild(){}}},window:{setTimeout(){}}};
 vm.createContext(context);vm.runInContext(fs.readFileSync('Assets/js/api.js','utf8')+';globalThis.api=Api',context);
 const r=await context.api.download('reportes/exportar-pdf?tipo=cierres-caja');assert.equal(r.ok,true);assert.equal(downloaded,true);
 context.fetch=async()=>({ok:true,status:200,headers:{get:()=> 'application/pdf'},blob:async()=>new Blob(['HTML corrupto'])});
 assert.equal((await context.api.download('invalid')).data.code,'INVALID_PDF');console.log('PASS descarga PDF válida y rechazo de archivo inválido');
})().catch(e=>{console.error(e);process.exit(1)});
