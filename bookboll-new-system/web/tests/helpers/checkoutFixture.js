import { readFileSync } from 'node:fs'
import { compile, createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import * as icons from 'lucide-vue-next'

export const checkoutSource = readFileSync(new URL('../../src/customer/pages/ListingDetail.vue',import.meta.url),'utf8')
const fragment = checkoutSource.split('<!-- ===== STEP 2 — checkout ===== -->')[1].split('<MoreVenueListings')[0].replace('v-else class="ld-grid','class="ld-grid')
const render = compile(fragment)
export async function renderCheckout(overrides = {}) {
  const state = {
    l:{name:'6v6 Half Court - Abu Hamor'},loc:{name:'Abu Hamor (Bright Future International School)'},heroPhoto:'',
    fullQuote:{perDay:[{date:'2026-10-14',blocks:[{start:'16:00',end:'17:00',slots:[1],total:85}]}],dates:['2026-10-14'],pricedAddons:[],totalSlots:1,slotsTotal:85,savings:0,addonsTotal:0},
    cur:'QAR',fmtDate:()=> 'Wed, Oct 14',fmtTime:t=>t==='16:00'?'4:00 PM':'5:00 PM',
    isCustomer:true,customer:{full_name:'Alex Player',email:'alex@example.test'},phone:'',selectedCountry:{iso_code:'QA'},showPhoneErr:false,phoneErr:'',phoneTouched:false,
    plan:{methods:['online','deposit']},paymentError:'',payMethod:'',saving:false,paymentSelected:false,
    PAY_LABEL:{online:'Pay online',deposit:'Deposit + on arrival'},PAY_NOTE:{online:'Pay securely online.',deposit:'Deposit secures your booking; pay the balance at the venue.'},PAY_DESC:{deposit:'The deposit is non-refundable. Pay the rest at the venue.'},
    selectMode:false,bookingOpen:true,hoursLabel:'1 hr',promoDiscount:0,payableTotal:85,promo:null,promoInput:'',promoBusy:false,promoError:'',
    paySplit:null,depositLabel:'20%',chargeNow:17,bookingUnavailable:false,err:'',confirmLabel:'Continue',isWhiteLabel:true,rewardAmount:0,
    backToSelection:()=>{},removeBlock:()=>{},removeAddon:()=>{},applyPromo:()=>{},removePromo:()=>{},confirm:()=>{},step:2,
    ...overrides,
  }
  return renderToString(createSSRApp({render,components:{...icons,PhoneInput:{props:['inputId','modelValue'],setup:props=>()=>h('div',{class:'pin'},[
    h('button',{class:'pin-dial',type:'button'},[h('span',{class:'pin-flag'},'QA'),h('span',{class:'pin-code'},'+974'),h(icons.ChevronDown,{size:14,class:'pin-chev'})]),
    h('span',{class:'pin-div'}),h(icons.Phone,{size:15,class:'pin-ico'}),h('input',{id:props.inputId,class:'pin-num',placeholder:'Phone number',value:props.modelValue}),
  ])}},setup:()=>state}))
}
