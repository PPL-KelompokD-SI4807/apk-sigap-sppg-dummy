import { h, clear, formatValue } from '../core/dom.js';
import { api, submitWithQueue } from '../core/api.js';
import { getUser } from '../core/auth.js';
import { perms } from '../core/permissions.js';
import { navigate, route } from '../core/router.js';
import { toast, showConfirm, setMainButton } from '../core/ui.js';
import { createListView } from './list-view.js';
import { createFormView } from './form-view.js';

const unwrap = (res) => (res && res.data !== undefined && !Array.isArray(res) ? res.data : res);
const me = (slug) => perms(getUser()?.role, slug);

function displayValue(field, rec) {
  if (field.display) return field.display(rec);
  const v = rec[field.name];
  if (field.type === 'select') {
    const o = (field.options || []).map((x) => (typeof x === 'object' ? x : { value: x, label: x })).find((x) => String(x.value) === String(v));
    if (o) return o.label;
  }
  if (field.type === 'relasi' || field.type === 'relation') {
    const rel = field.relation || {};
    const nested = rec[rel.as || field.name.replace(/_id$/, '')];
    if (nested && typeof nested === 'object') return nested[rel.label || 'nama'] ?? '-';
  }
  return formatValue(v, field.type === 'number' ? 'number' : field.type);
}

/**
 * defineCrudModule({slug,title,endpoint,columns,fields, ...})
 *  slug: kunci rute + kunci permissions.js (mis. 'titik-layanan')
 *  endpoint: path API tanpa /api/v1 (mis. '/titik-layanan')
 *  columns: kartu daftar (lihat list-view.js); fields: skema form (lihat form-view.js)
 *  opsional: icon, description, permissionSlug, mapPayload(values, {mode}), listQuery, itemTitle(rec)
 * Mengembalikan objek modul untuk registry: {slug,title,icon,description,permissionSlug,register()}
 */
export function defineCrudModule(def) {
  const { slug, title, endpoint, columns, fields, icon = '📋', description = '', permissionSlug = slug, mapPayload, listQuery = {}, itemTitle } = def;
  const base = `/${slug}`;
  const recTitle = (rec) => (itemTitle ? itemTitle(rec) : rec[(columns.find((c) => c.primary) || columns[0]).key] ?? `#${rec.id}`);

  // Simpan: POST (baru) atau PUT (ubah), sertakan ai_draft_id bila dari draf AI.
  async function save({ mode, id, values, draftId }) {
    const body = { ...(mapPayload ? mapPayload(values, { mode }) : values) };
    if (draftId) body.ai_draft_id = draftId;
    const res = await submitWithQueue(mode === 'create' ? 'POST' : 'PUT', mode === 'create' ? endpoint : `${endpoint}/${id}`, body, { label: title });
    if (res.queued) toast('Tersimpan sebagai draf offline, dikirim saat online.', 'info');
    else toast('Data tersimpan.', 'success');
    const saved = res.data && unwrap(res.data);
    navigate(saved?.id && !res.queued ? `${base}/${saved.id}` : base, { replace: true });
  }

  async function loadDraft(uuid) {
    try {
      const d = await api.get(`/ai-drafts/${encodeURIComponent(uuid)}`, null, { silent: true });
      const draft = unwrap(d);
      if (draft.module && draft.module !== slug) toast('Draf ini untuk modul lain.', 'error');
      return draft;
    } catch (e) {
      toast(e.status === 404 ? 'Draf AI tidak ditemukan atau sudah kedaluwarsa.' : 'Draf AI gagal dimuat.', 'error');
      return null;
    }
  }

  async function renderForm(ctx, { mode, record }) {
    const draftUuid = ctx.query.get('draft');
    const draft = draftUuid ? await loadDraft(draftUuid) : null;
    if (ctx.isStale()) return;
    const values = { ...(record || {}), ...(draft?.payload || {}) };
    clear(ctx.el);
    if (draft) ctx.el.append(h('div', { class: 'notice' }, 'Diisi otomatis oleh Agen AI. Periksa kolom bertanda "Perlu dicek", lalu simpan.'));
    const fv = createFormView({
      fields,
      values,
      confidence: draft?.confidence || {},
      submitText: mode === 'create' ? 'Simpan' : 'Simpan perubahan',
      onSubmit: (v) => save({ mode, id: record?.id, values: v, draftId: draft ? draft.uuid || draftUuid : null }),
    });
    ctx.el.append(fv.el);
    return fv.destroy;
  }

  function register() {
    // Daftar
    route(base, async (ctx) => {
      const p = me(permissionSlug);
      if (p.selfOnly) return navigate(`${base}/${getUser().id}`, { replace: true });
      const lv = createListView({
        endpoint, columns, extraQuery: listQuery,
        createHref: p.C ? `#${base}/baru` : null,
        itemHref: (r) => `#${base}/${r.id}`,
      });
      ctx.el.append(lv.el);
    }, { title: title, guard: () => me(permissionSlug).R });

    // Baru (didaftarkan sebelum :id)
    route(`${base}/baru`, (ctx) => renderForm(ctx, { mode: 'create' }), { title: `Tambah ${title}`, guard: () => me(permissionSlug).C });

    // Detail
    route(`${base}/:id`, async (ctx) => {
      const res = await api.get(`${endpoint}/${ctx.params.id}`);
      if (ctx.isStale()) return;
      const rec = unwrap(res);
      const p = me(permissionSlug);
      ctx.setTitle(recTitle(rec));
      const dl = h('dl', { class: 'detail' });
      for (const f of fields) {
        const dt = h('dt', {}, f.label || f.name);
        const v = rec[f.name];
        const dd = f.type === 'photo' && v ? h('dd', {}, h('img', { class: 'photo-preview', src: v, alt: f.label || f.name })) : h('dd', {}, displayValue(f, rec));
        dl.append(dt, dd);
      }
      ctx.el.append(h('div', { class: 'panel' }, dl));
      if (p.D) {
        ctx.el.append(h('button', {
          class: 'btn btn-danger btn-block', type: 'button',
          onclick: async () => {
            if (!(await showConfirm({ title: 'Hapus data', message: `Hapus "${recTitle(rec)}"? Tindakan ini tidak dapat dibatalkan.`, okText: 'Hapus', destructive: true }))) return;
            await api.del(`${endpoint}/${rec.id}`);
            toast('Data dihapus.', 'success');
            navigate(base, { replace: true });
          },
        }, 'Hapus'));
      }
      if (p.U) setMainButton({ text: 'Ubah', onClick: () => navigate(`${base}/${rec.id}/ubah`) });
    }, { title, guard: () => me(permissionSlug).R });

    // Ubah
    route(`${base}/:id/ubah`, async (ctx) => {
      const rec = unwrap(await api.get(`${endpoint}/${ctx.params.id}`));
      if (ctx.isStale()) return;
      return renderForm(ctx, { mode: 'update', record: rec });
    }, { title: `Ubah ${title}`, guard: () => me(permissionSlug).U });
  }

  return { slug, title, icon, description, permissionSlug, register };
}
