<script setup lang="ts">
/**
 * CUSTOM(fork): řádkové akce faktury (seznam faktur).
 * Hlavní akce jako inline „flat" (solid) ikony — Upravit (admin) / Exportovat do PDF / Uhradit —
 * + „…" dropdown menu s plnou nabídkou (vč. nestandardních) a oddělenou Smazat (admin).
 * Reuse existujících endpointů — žádné nové API.
 *
 * Menu je teleportované do <body> a fixed-pozicované podle triggeru → neořezává ho overflow
 * tabulky. Zavírá se klikem mimo (backdrop), Esc, scrollem nebo po akci.
 *
 * Izolovaná komponenta — žádný háček do upstreamu kromě použití v InvoiceList.vue.
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
const canSend = () => props.invoice.status !== 'draft' && props.invoice.status !== 'cancelled'
const canPay = () => {
  const s = props.invoice.status
  return s !== 'draft' && s !== 'cancelled' && s !== 'paid' && props.invoice.payment_status !== 'paid'
}

// ─── akce (reuse endpointů z detailu) ───
async function clone() {
  if (!confirm(t('invoice.clone_confirm', { varsymbol: vs() }))) return
  const incrementMonths = confirm(t('invoice.clone_increment_confirm'))
  try {
    const r = await invoicesApi.clone(props.invoice.id, { increment_month_in_descriptions: incrementMonths })
    router.push(`/invoices/${r.draft_id}/edit`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.clone_failed'))
  }
}
function download() {
  window.open(invoicesApi.pdfUrl(props.invoice.id, false), '_blank')
}
async function send() {
  if (!confirm(cs() ? `Odeslat fakturu ${vs()} e-mailem klientovi?` : `Send invoice ${vs()} to the client by e-mail?`)) return
  try {
    await invoicesApi.send(props.invoice.id)
    toast.success(cs() ? 'Faktura odeslána' : 'Invoice sent')
    emit('changed')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || (cs() ? 'Odeslání selhalo' : 'Send failed'))
  }
}
async function pay() {
  if (!confirm(cs() ? `Označit fakturu ${vs()} jako uhrazenou (k dnešnímu dni)?` : `Mark invoice ${vs()} as paid (as of today)?`)) return
  try {
    await invoicesApi.markPaid(props.invoice.id)
    toast.success(cs() ? 'Označeno jako uhrazeno' : 'Marked as paid')
    emit('changed')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || (cs() ? 'Akce selhala' : 'Action failed'))
  }
}
function edit() {
  const force = props.invoice.status !== 'draft' ? '?force=1' : ''
  router.push(`/invoices/${props.invoice.id}/edit${force}`)
}
async function remove() {
  if (!confirm(cs()
    ? `Opravdu smazat fakturu ${vs()}? Tato akce je nevratná a smaže i navázané položky.`
    : `Really delete invoice ${vs()}? This is irreversible and also deletes linked items.`)) return
  try {
    const res = await invoicesApi.delete(props.invoice.id)
    toast.success(t('invoice.deleted_with_cascade', { n: res.cascade_deleted }))
    emit('changed')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.delete_failed'))
  }
}

// ─── flat (solid) ikony, viewBox 20×20, fill currentColor; pole = víc path segmentů ───
const IC: Record<string, string[]> = {
  edit:  ['M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z'],
  send:  ['M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z', 'M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z'],
  pdf:   ['M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z'],
  pay:   ['M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z', 'M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z'],
  clone: ['M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z', 'M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h8a2 2 0 00-2-2H5z'],
  trash: ['M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z'],
  dots:  ['M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM18 10a2 2 0 11-4 0 2 2 0 014 0z'],
}

// Plné menu (kromě Smazat — danger sekce dole).
const items = computed(() => [
  { show: auth.isAdmin,               label: cs() ? 'Upravit' : 'Edit',                   icon: IC.edit,  fn: edit },
  { show: auth.canWrite && canSend(), label: cs() ? 'Odeslat' : 'Send',                   icon: IC.send,  fn: send },
  { show: true,                       label: cs() ? 'Exportovat do PDF' : 'Export to PDF', icon: IC.pdf,   fn: download },
  { show: auth.canWrite && canPay(),  label: cs() ? 'Uhradit' : 'Mark paid',              icon: IC.pay,   fn: pay },
  { show: auth.canWrite,              label: cs() ? 'Kopírovat' : 'Duplicate',            icon: IC.clone, fn: clone },
].filter(i => i.show))

// ─── dropdown open + pozicování (teleport/fixed) ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 224
  const mh = menuRef.value?.offsetHeight ?? 300
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4) // flip nahoru u spodních řádků
  pos.value = { top, left }
}
async function toggle() {
  if (open.value) { open.value = false; return }
  reposition()
  open.value = true
  await nextTick()
  reposition()
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

const iconBtn = 'cursor-pointer p-1.5 rounded text-neutral-400 hover:text-primary-600 hover:bg-neutral-100'
const iconBtnSuccess = 'cursor-pointer p-1.5 rounded text-neutral-400 hover:text-success-600 hover:bg-success-50'
</script>

<template>
  <span class="inline-flex items-center gap-0.5" @click.stop>
    <!-- Hlavní inline akce (flat ikony) -->
    <button v-if="auth.isAdmin" type="button" @click="edit" :class="iconBtn" :title="cs() ? 'Upravit' : 'Edit'">
      <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in IC.edit" :key="i" :d="d" /></svg>
    </button>
    <button type="button" @click="download" :class="iconBtn" :title="cs() ? 'Exportovat do PDF' : 'Export to PDF'">
      <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in IC.pdf" :key="i" :d="d" /></svg>
    </button>
    <button v-if="auth.canWrite && canPay()" type="button" @click="pay" :class="iconBtnSuccess" :title="cs() ? 'Uhradit' : 'Mark paid'">
      <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in IC.pay" :key="i" :d="d" /></svg>
    </button>

    <!-- „…" dropdown s plnou nabídkou -->
    <button ref="triggerRef" type="button" @click.stop="toggle"
      class="cursor-pointer p-1.5 rounded text-neutral-400 hover:text-primary-600 hover:bg-neutral-100"
      :class="{ 'bg-neutral-100 text-primary-600': open }"
      :aria-expanded="open" :title="cs() ? 'Další akce' : 'More actions'">
      <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in IC.dots" :key="i" :d="d" /></svg>
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
            <svg class="w-4 h-4 text-neutral-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in it.icon" :key="i" :d="d" /></svg>
            <span>{{ it.label }}</span>
          </button>
          <template v-if="auth.isAdmin">
            <div class="my-1 border-t border-neutral-100"></div>
            <button type="button" @click="run(remove)"
              class="w-full flex items-center gap-2.5 px-3 py-2 text-danger-600 hover:bg-danger-50 cursor-pointer text-left">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path v-for="(d, i) in IC.trash" :key="i" :d="d" /></svg>
              <span>{{ cs() ? 'Smazat' : 'Delete' }}</span>
            </button>
          </template>
        </div>
      </template>
    </Teleport>
  </span>
</template>
