import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as access from '../src/lib/communityAccess.js'

let code = compileScript(parse(readFileSync(new URL('../src/customer/pages/Messages.vue', import.meta.url),'utf8')).descriptor,{id:'chat-controls'}).content
for (const node of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()) {
  if (node.type==='ImportDeclaration') code=code.slice(0,node.start)+node.specifiers.map(s=>`const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type==='ImportDefaultSpecifier'?'default':s.imported.name)}];`).join('\n')+code.slice(node.end)
  else if (node.type==='ExportDefaultDeclaration') code=code.slice(0,node.start)+'return '+code.slice(node.declaration.start)
}
function chat(t,role='moderator') {
  const noop=()=>{}, scope=vue.effectScope(), calls=[]
  t.after(()=>scope.stop())
  const route=vue.reactive({params:{id:'community__one'}})
  const customer=vue.ref({id:7})
  let result=async(id,permission)=>({ok:true,community:{id,owner_customer_id:role==='owner'?7:8,membership:{role,status:'accepted'},settings:{chat_permission:permission,post_permission:'admins'}}})
  const imports=new Proxy({
    vue:{...vue,onMounted:noop,onUnmounted:noop},
    'vue-router':{useRoute:()=>route,useRouter:()=>({})},
    '../../lib/customerSession.js':{customer,isCustomer:vue.ref(true)},
    '../../lib/communityAccess.js':access,
    '../../customer/community.js':{
      canManage:membership=>['owner','admin'].includes(membership?.role),isMember:membership=>membership?.status==='accepted',
      updateCommunityChatPermission:async(id,permission)=>{calls.push([id,permission]);return result(id,permission)},
      loadCommunity:async()=>null,loadGroupThread:async()=>[],
    },
  },{get:(object,key)=>object[key]||new Proxy({},{get:()=>noop})})
  const state=scope.run(()=>new Function('imports',code)(imports).setup({}, {expose:noop}))
  state.groupInfo.value={id:'one',owner_customer_id:role==='owner'?7:8,membership:{role,status:'accepted'},settings:{chat_permission:'members',post_permission:'admins'}}
  return {state,calls,route,result:fn=>result=fn}
}
test('owners, admins and moderators can edit chat permissions; ordinary or pending members cannot',async t=>{
  for (const role of ['owner','admin','moderator','member']) {
    const {state,calls}=chat(t,role)
    await state.openChatPermissions()
    assert.equal(state.chatPermissionsOpen.value,role!=='member')
    state.chatPermission.value='admins'
    await state.saveChatPermissions()
    assert.equal(calls.length,role==='member'?0:1)
    if (role==='member') continue
    assert.equal(state.groupInfo.value.settings.chat_permission,'admins')
    assert.equal(state.canPostGroup.value,role!=='moderator')
  }
  const {state,calls}=chat(t)
  state.groupInfo.value.membership.status='pending'
  await state.openChatPermissions(); await state.saveChatPermissions()
  assert.equal(state.chatPermissionsOpen.value,false); assert.equal(calls.length,0)
})
test('chat permissions keep the panel open on failure and do not overwrite another conversation',async t=>{
  const {state,calls,result,route}=chat(t)
  await state.openChatPermissions(); state.chatPermission.value='admins'
  result(async()=>({error:'Permission denied'}))
  await state.saveChatPermissions()
  assert.equal(state.chatPermissionError.value,'Permission denied')
  assert.equal(state.chatPermissionsOpen.value,true)
  assert.equal(state.groupInfo.value.settings.chat_permission,'members')
  let finish
  result(()=>new Promise(resolve=>finish=resolve))
  const saving=state.saveChatPermissions()
  await state.saveChatPermissions()
  assert.equal(calls.length,2)
  route.params.id='community__two'
  await vue.nextTick()
  finish({ok:true,community:{id:'one',settings:{chat_permission:'admins'}}})
  await saving
  assert.notEqual(state.groupInfo.value?.id,'one')
  assert.equal(state.chatPermissionMessage.value,'')
  assert.equal(state.chatPermissionBusy.value,false)
})
