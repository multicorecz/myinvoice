<script setup lang="ts">
/**
 * CUSTOM(fork): řádkové akce faktury (seznam faktur).
 * Hlavní akce jako inline outline ikony (barevně odlišené) — Upravit (admin) / Exportovat do PDF /
 * Uhradit — + „…" dropdown s plnou nabídkou.
 *
 * Akce s vyplňovacím dialogem (Odeslat / Uhradit / Částečná úhrada / Storno / Dobropis) NEduplikují
 * UI — navigují do detailu faktury s `?action=…`, kde se otevře TENTÝŽ dialog jako v detailu
 * (hook v InvoiceDetail.vue). Jednoduché akce (Upravit / PDF / Kopírovat / Smazat) běží přímo.
 *
 * Menu je teleportované do <body> → neořezává ho overflow tabulky. Izolovaná komponenta.
 */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'

const props = defineProps<{ invoice: InvoiceListItem }>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const router = useRouter()
const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const cs = () => locale.value === 'cs'
const vs = () => props.invoice.varsymbol || `#${props.invoice.id}`
const errMsg = (e: any, fb: string) => e?.response?.data?.error?.message || fb

const canSend = () => props.invoice.status !== 'draft' && props.invoice.status !== 'cancelled'
const canPay = () => {
  const s = props.invoice.status
  return s !== 'draft' && s !== 'cancelled' && s !== 'paid' && props.invoice.payment_status !== 'paid'
}
const canCancel = () => {
  const s = props.invoice.status, ty = props.invoice.invoice_type
  return s !== 'draft' && s !== 'cancelled' && (ty === 'invoice' || ty === 'tax_document')
}

// Navigace do detailu s akcí → detail otevře tentýž dialog (hook applyRouteAction).
function go(action: string, mode?: string) {
  router.push({ path: `/invoices/${props.invoice.id}`, query: { action, ...(mode ? { mode } : {}) } })
}

// Jednoduché přímé akce.
function edit() {
  const force = props.invoice.status !== 'draft' ? '?force=1' : ''
  router.push(`/invoices/${props.invoice.id}/edit${force}`)
}
function download() {
  window.open(invoicesApi.pdfUrl(props.invoice.id, false), '_blank')
}
async function clone() {
  if (!confirm(t('invoice.clone_confirm', { varsymbol: vs() }))) return
  const incrementMonths = confirm(t('invoice.clone_increment_confirm'))
  try {
    const r = await invoicesApi.clone(props.invoice.id, { increment_month_in_descriptions: incrementMonths })
    router.push(`/invoices/${r.draft_id}/edit`)
  } catch (e: any) { toast.error(errMsg(e, t('invoice.clone_failed'))) }
}
async function remove() {
  if (!confirm(cs()
    ? `Opravdu smazat fakturu ${vs()}? Tato akce je nevratná a smaže i navázané položky.`
    : `Really delete invoice ${vs()}? This is irreversible and also deletes linked items.`)) return
  try {
    const res = await invoicesApi.delete(props.invoice.id)
    toast.success(t('invoice.deleted_with_cascade', { n: res.cascade_deleted }))
    emit('changed')
  } catch (e: any) { toast.error(errMsg(e, t('invoice.delete_failed'))) }
}

// ─── outline ikony (stroke, viewBox 24) ───
const IC: Record<string, string> = {
  edit:    'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z',
  send:    'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5',
  pdf:     'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  pay:     'M2.25 8.25h19.5M2.25 9v6.75A2.25 2.25 0 004.5 18h15a2.25 2.25 0 002.25-2.25V9A2.25 2.25 0 0019.5 6.75h-15A2.25 2.25 0 002.25 9z',
  partial: 'M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
  storno:  'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  refund:  'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
  clone:   'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75',
  trash:   'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
  dots:    'M6.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM12.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM18.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
}

// barva = akcent ikony (paleta appky)
const items = computed(() => [
  { show: auth.isAdmin,                 label: cs() ? 'Upravit' : 'Edit',                   icon: IC.edit,    color: 'text-primary-500',  fn: edit },
  { show: auth.canWrite && canSend(),   label: cs() ? 'Odeslat' : 'Send',                   icon: IC.send,    color: 'text-accent-500',   fn: () => go('send') },
  { show: true,                         label: cs() ? 'Exportovat do PDF' : 'Export to PDF', icon: IC.pdf,     color: 'text-neutral-400',  fn: download },
  { show: auth.canWrite && canPay(),    label: cs() ? 'Uhradit' : 'Mark paid',              icon: IC.pay,     color: 'text-success-600',  fn: () => go('mark-paid') },
  { show: auth.canWrite && canPay(),    label: cs() ? 'Částečná úhrada' : 'Partial payment', icon: IC.partial, color: 'text-success-500',  fn: () => go('partial-payment') },
  { show: auth.canWrite && canCancel(), label: cs() ? 'Storno' : 'Cancel',                  icon: IC.storno,  color: 'text-warning-600',  fn: () => go('cancel', 'internal') },
  { show: auth.canWrite && canCancel(), label: cs() ? 'Dobropis' : 'Credit note',           icon: IC.refund,  color: 'text-accent-600',   fn: () => go('cancel', 'credit_note') },
  { show: auth.canWrite,                label: cs() ? 'Kopírovat' : 'Duplicate',            icon: IC.clone,   color: 'text-neutral-400',  fn: clone },
].filter(i => i.show))

// ─── dropdown open + pozicování ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 224
  const mh = menuRef.value?.offsetHeight ?? 340
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4)
  pos.value = { top, left }
}
async function toggle() {
  if (open.value) { open.value = false; return }
  reposition(); open.value = true; await nextTick(); reposition()
}
function close() { open.value = false }
function run(fn: () => void | Promise<void>) { close(); fn() }

function onKey(e: KeyboardEvent) { if (e.key === 'Escape') close() }
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})

const iconBtn = 'cursor-pointer p-1.5 rounded hover:bg-neutral-100'
</script>

<template>
  <span class="inline-flex items-center gap-0.5" @click.stop>
    <!-- Hlavní inline akce (outline, barevné) -->
    <button v-if="auth.isAdmin" type="button" @click="edit" :class="[iconBtn, 'text-primary-500 hover:text-primary-700']" :title="cs() ? 'Upravit' : 'Edit'">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" :d="IC.edit" /></svg>
    </button>
    <button type="button" @click="download" :class="[iconBtn, 'text-neutral-400 hover:text-primary-600']" :title="cs() ? 'Exportovat do PDF' : 'Export to PDF'">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" :d="IC.pdf" /></svg>
    </button>
    <button v-if="auth.canWrite && canPay()" type="button" @click="go('mark-paid')" :class="[iconBtn, 'text-success-600 hover:text-success-700']" :title="cs() ? 'Uhradit' : 'Mark paid'">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" :d="IC.pay" /></svg>
    </button>

    <!-- „…" dropdown -->
    <button ref="triggerRef" type="button" @click.stop="toggle"
      class="cursor-pointer p-1.5 rounded text-neutral-400 hover:text-primary-600 hover:bg-neutral-100"
      :class="{ 'bg-neutral-100 text-primary-600': open }" :aria-expanded="open" :title="cs() ? 'Další akce' : 'More actions'">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path :d="IC.dots" /></svg>
    </button>

    <Teleport to="body">
      <template v-if="open">
        <div class="fixed inset-0 z-[60]" @click="close" @contextmenu.prevent="close" aria-hidden="true"></div>
        <div ref="menuRef" class="fixed z-[61] w-56 bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 text-sm"
          :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
          <div class="px-3 py-2 text-xs font-semibold text-neutral-500 text-center border-b border-neutral-100 truncate">
            {{ cs() ? 'Faktura' : 'Invoice' }} {{ vs() }}
          </div>
          <button v-for="it in items" :key="it.label" type="button" @click="run(it.fn)"
            class="w-full flex items-center gap-2.5 px-3 py-2 text-neutral-700 hover:bg-neutral-50 cursor-pointer text-left">
            <svg :class="['w-4 h-4 shrink-0', it.color]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" :d="it.icon" /></svg>
            <span>{{ it.label }}</span>
          </button>
          <template v-if="auth.isAdmin">
            <div class="my-1 border-t border-neutral-100"></div>
            <button type="button" @click="run(remove)"
              class="w-full flex items-center gap-2.5 px-3 py-2 text-danger-600 hover:bg-danger-50 cursor-pointer text-left">
              <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" :d="IC.trash" /></svg>
              <span>{{ cs() ? 'Smazat' : 'Delete' }}</span>
            </button>
          </template>
        </div>
      </template>
    </Teleport>
  </span>
</template>
