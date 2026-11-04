import test from 'node:test'
import assert from 'node:assert/strict'
import {dialogFocus} from '../src/lib/dialogFocus.js'

// Minimal browser surface for the directive, including stacked dialogs and hidden controls.
function environment(t) {
  const old=globalThis.document
  const listeners=new Map()
  const document={activeElement:null,body:{style:{overflow:'scroll'}},addEventListener:(type,fn)=>listeners.set(type,fn),removeEventListener:type=>listeners.delete(type)}
  globalThis.document=document
  t.after(()=>{globalThis.document=old})
  const node=()=>({isConnected:true,focus(){document.activeElement=this},getClientRects:()=>[{}],closest:()=>null})
  const make=()=>{
    const attrs=new Map(); const a=node(),b=node(),hidden={...node(),getClientRects:()=>[]}
    const panel={...node(),querySelectorAll:()=>[a,hidden,b],querySelector:()=>({id:'test-heading'}),hasAttribute:key=>attrs.has(key),setAttribute:(key,value)=>attrs.set(key,value),contains:n=>[panel,a,b,hidden].includes(n)}
    return {panel,a,b,attrs}
  }
  function key(key,shiftKey=false){let prevented=false;listeners.get('keydown')?.({key,shiftKey,preventDefault:()=>prevented=true,stopImmediatePropagation(){}});return prevented}
  return {document,listeners,node,make,key}
}
test('dialog traps both tab directions and ignores hidden controls',async t=>{
 const e=environment(t),d=e.make(),trigger=e.node();trigger.focus()
 dialogFocus.mounted(d.panel,{value:()=>{}});await Promise.resolve()
 assert.equal(e.document.activeElement,d.panel);assert.equal(d.attrs.get('aria-modal'),'true')
 e.key('Tab');assert.equal(e.document.activeElement,d.a)
 e.key('Tab',true);assert.equal(e.document.activeElement,d.b)
 e.key('Tab');assert.equal(e.document.activeElement,d.a)
 dialogFocus.beforeUnmount(d.panel);assert.equal(e.document.activeElement,trigger);assert.equal(e.document.body.style.overflow,'scroll')
 assert.equal(e.listeners.size,0)
})
test('nested confirmation owns Escape and restores focus to parent',async t=>{
 const e=environment(t),parent=e.make(),child=e.make();let p=0,c=0
 dialogFocus.mounted(parent.panel,{value:()=>p++});await Promise.resolve();parent.b.focus()
 dialogFocus.mounted(child.panel,{value:()=>c++});await Promise.resolve();e.key('Escape')
 assert.equal(c,1);assert.equal(p,0);assert.equal(e.document.body.style.overflow,'hidden')
 dialogFocus.beforeUnmount(child.panel);assert.equal(e.document.activeElement,parent.b)
 e.key('Escape');assert.equal(p,1);dialogFocus.beforeUnmount(parent.panel)
 assert.equal(e.document.body.style.overflow,'scroll')
})
test('empty dialog keeps focus and updated close callbacks are respected',async t=>{
 const e=environment(t),d=e.make();d.panel.querySelectorAll=()=>[];let closes=0
 dialogFocus.mounted(d.panel,{});await Promise.resolve()
 assert.equal(e.key('Tab'),true);assert.equal(e.document.activeElement,d.panel)
 dialogFocus.updated(d.panel,{value:()=>closes++});e.key('Escape');assert.equal(closes,1)
 dialogFocus.beforeUnmount(d.panel)
})
