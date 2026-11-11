import { h, clear, formatValue } from '../core/dom.js';
import { api, submitWithQueue, request, newKey } from '../core/api.js';
import { getUser } from '../core/auth.js';
import { userPerms, permsDariIzin, PESAN_BUKAN_HAK } from '../core/permissions.js';
import { tujuanDraf, jalurBersih, draftCocok, pathHapus } from '../core/draf-tujuan.js';
import { muatDraf, pemberitahuanDraf } from './draf-form.js';
import { errorMessage, ringkasGalatAksi } from '../core/errors.js';
import { navigate, route, resolve, alihkanKeBeranda } from '../core/router.js';
import { toast, showConfirm, setMainButton } from '../core/ui.js';
import { createListView } from './list-view.js';
import { createAuthImage } from './photo-upload.js';
import { createFormView } from './form-view.js';

const unwrap = (res) => (res && res.data !== undefined && !Array.isArray(res) ? res.data : res);

// Kirim tanpa antrian offline (aksi yang tidak boleh tertunda, mis. memicu notifikasi). Kunci idempotensi tetap dikirim.
async function submitDirect(method, path, body) {
  try {
    return { queued: false, data: await request(method, path, { body, key: newKey(), silent: true }) };
  } catch (e) {
    if (e?.status !== 422 && e?.status !== 401) toast(errorMessage(e, 'Gagal menyimpan data.'), 'error');
    throw e;
  }
}

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
 *  tambahan (semuanya opsional):
 *   fields bisa fungsi ({mode:'create'|'update'|'detail', user, record}) -> array skema (kolom bergantung peran/mode)
 *   filters, renderCard, searchPlaceholder  -> diteruskan ke list-view
 *   listTop(perms) -> Node di atas daftar; detailExtras(rec, perms, ctx) -> Node(s) setelah panel detail
 *   listRedirect(user, perms) -> path|null: lewati daftar (mis. Kepala SPPG langsung ke SPPG-nya)
 *   rowPerms(rec, perms) -> perms yang disesuaikan per baris untuk KEADAAN yang terbaca di record (mis. tak bisa hapus akun sendiri)
 *   izin: true -> resource memuat `izin` dari server ({ubah, hapus, ...}); tombol Ubah/Hapus dan rute /ubah HANYA mengikuti
 *     izin.ubah/izin.hapus (tanpa izin = tanpa tombol, fail closed). Klien tidak menghitung ulang aturan unit/kepemilikan.
 *   akses(user) -> boolean: syarat tambahan rute baru/detail/ubah (daftar tetap lewat listRedirect), mis. sub-jenis jadwal sesuai unit_scope
 *   bolehTambah(user) -> boolean: syarat tambahan Tambah (mis. formulir tak dapat diselesaikan tanpa hak baca dropdown)
 *   menuInfo(user) -> {title?, description?}: label kartu menu bergantung hak (mis. "Profil Saya" bagi hak R*)
 *   formTop({mode,record,form,collect,values}) -> Node di atas form; onSaved({mode,res}) setelah simpan; prefill(query) -> nilai awal form baru
 *   prefillDraf(payloadBersih) -> nilai form dari payload draf AI (default: payload apa adanya tanpa kunci berawalan "_")
 *   field.onlyIfPresent: kolom detail disembunyikan bila kuncinya tak ada di respons (mis. no_sim untuk peran R)
 * Mengembalikan objek modul untuk registry: {slug,title,icon,description,permissionSlug,register()}
 */
export function defineCrudModule(def) {
  const { slug, title, endpoint, columns, fields: fieldsDef, icon = '📋', description = '', permissionSlug = slug, mapPayload, listQuery = {}, itemTitle, filters, renderCard, searchPlaceholder, createLabel, listTop, detailExtras, rowPerms, formTop, onSaved, prefill } = def;
  // Opsi tambahan (aditif): searchable=false, queueOffline=false (tanpa antrian offline),
  // beforeSave(values)->Promise<boolean> (konfirmasi sebelum kirim).
  // sertakanVersi (R-06b-2): kunci optimistik; saat ubah, body memuat `versi` = versi draf AI (dari server) atau versi record yang dimuat.
  const { searchable = true, queueOffline = true, beforeSave, sertakanVersi = false, izin: berIzin = false, bolehTambah, menuInfo, prefillDraf, akses } = def;
  const me = (s) => userPerms(getUser(), s);
  // akses(user) -> boolean: syarat tambahan untuk rute record (baru/detail/ubah), mis. sub-jenis jadwal sesuai unit_scope.
  const aksesRecord = () => typeof akses !== 'function' || akses(getUser()) === true;
  const bolehBuat = () => me(permissionSlug).C && aksesRecord() && (typeof bolehTambah !== 'function' || bolehTambah(getUser()) === true);
  // Hak per record: modul berizin -> dari izin server; lalu penyesuaian keadaan (rowPerms) bila ada.
  const permsRecord = (rec) => {
    const dasar = me(permissionSlug);
    const p = berIzin ? permsDariIzin(dasar, rec) : dasar;
    return rowPerms ? rowPerms(rec, p) : p;
  };
  const fieldsFor = (mode, record) => (typeof fieldsDef === 'function' ? fieldsDef({ mode, user: getUser(), record }) : fieldsDef);
  const base = `/${slug}`;
  const recTitle = (rec) => (itemTitle ? itemTitle(rec) : rec[(columns.find((c) => c.primary) || columns[0]).key] ?? `#${rec.id}`);

  // Simpan: POST (baru) atau PUT (ubah), sertakan ai_draft_id bila dari draf AI.
  async function save({ mode, id, values, draftId, versi = null }) {
    const body = { ...(mapPayload ? mapPayload(values, { mode }) : values) };
    if (sertakanVersi && mode === 'update' && versi != null) body.versi = Math.trunc(Number(versi));
    if (draftId) body.ai_draft_id = draftId;
    if (beforeSave && !(await beforeSave(body, { mode }))) return;
    const res = await (queueOffline ? submitWithQueue : (m, p, b) => submitDirect(m, p, b))(mode === 'create' ? 'POST' : 'PUT', mode === 'create' ? endpoint : `${endpoint}/${id}`, body, { label: title });
    if (res.queued) toast('Tersimpan sebagai draf offline, dikirim saat online.', 'info');
    else toast('Data tersimpan.', 'success');
    if (onSaved) { try { onSaved({ mode, res }); } catch { /* abaikan */ } }
    const saved = res.data && unwrap(res.data);
    navigate(saved?.id && !res.queued ? `${base}/${saved.id}` : base, { replace: true });
  }

  async function loadDraft(uuid) {
    const draft = await muatDraf(uuid);
    if (draft?.module && draft.module !== slug && draft.module !== permissionSlug) toast('Draf ini untuk modul lain.', 'error');
    return draft;
  }

  async function renderForm(ctx, { mode, record }) {
    const draftUuid = ctx.query.get('draft');
    const draft = draftUuid ? await loadDraft(draftUuid) : null;
    if (ctx.isStale()) return;
    // Draf milik layar lain (mis. mutasi stok, atau lintas sumber daya): alihkan ke layar yang benar, jangan kirim
    // ai_draft_id dari form yang salah (server menolak dengan 422 karena resource/aksi tidak cocok).
    if (draft) {
      const tujuan = tujuanDraf(draft);
      if (jalurBersih(tujuan) !== jalurBersih(ctx.path)) return navigate(`${tujuan}?draft=${encodeURIComponent(draft.uuid || draftUuid)}`, { replace: true });
    }
    // Kunci berawalan "_" pada payload draf (mis. `_resource`, `_peringatan`) adalah penanda server, bukan isian form.
    const bersih = Object.fromEntries(Object.entries(draft?.payload || {}).filter(([k]) => !k.startsWith('_')));
    const isianDraf = typeof prefillDraf === 'function' ? prefillDraf(bersih) : bersih; // prefillDraf: petakan payload draf -> nilai form
    const values = { ...(record || {}), ...(prefill && !record ? prefill(ctx.query) || {} : {}), ...isianDraf };
    clear(ctx.el);
    const pd = pemberitahuanDraf(draft);
    if (pd.info) ctx.el.append(pd.info);
    const konflik = h('div', { 'aria-live': 'assertive' });
    const draftNotice = pd.alert;
    const versi = isianDraf.versi ?? record?.versi ?? null;
    // 409 versi/status: data di layar basi. Draf AI basi tidak boleh dikirim ulang, jadi arahkan ke data terbaru tanpa draf.
    function tampilkanKonflik(e) {
      const g = ringkasGalatAksi(e);
      if (!g.konflik) return;
      const pesan = draft ? `${g.pesan} Draf AI ini sudah basi.` : g.pesan;
      konflik.replaceChildren(h('div', { class: 'alert-danger', role: 'alert' }, pesan, ' ',
        h('button', { class: 'btn', type: 'button', onclick: () => (draft && record?.id ? navigate(`${base}/${record.id}`, { replace: true }) : resolve()) }, draft ? 'Buka data terbaru' : 'Muat ulang data')));
    }
    const fv = createFormView({
      fields: fieldsFor(mode, record),
      values,
      confidence: draft?.confidence || {},
      submitText: mode === 'create' ? 'Simpan' : 'Simpan perubahan',
      onSubmit: async (v) => {
        konflik.replaceChildren();
        draftNotice.hidden = true; // validasi klien lolos: galat draf lama tidak berlaku lagi
        try { await save({ mode, id: record?.id, values: v, draftId: draft ? draft.uuid || draftUuid : null, versi }); } catch (e) { tampilkanKonflik(e); throw e; }
      },
    });
    // Draf "perlu diperbaiki" (salah input di bot): galat per kolom/baris + daftar untuk galat tanpa kolom. Semua lewat textContent.
    pd.pasang(fv);
    ctx.el.append(konflik, draftNotice, fv.el);
    // formTop({mode, record, form, collect}) -> Node (disisipkan di atas formulir), mis. sisa kapasitas / ringkasan gizi.
    const top = formTop ? formTop({ mode, record, form: fv.el, collect: fv.collect, values }) : null;
    if (top) ctx.el.insertBefore(top, fv.el);
    return fv.destroy;
  }

  function register() {
    // Daftar
    route(base, async (ctx) => {
      const p = me(permissionSlug);
      if (p.selfOnly) return navigate(`${base}/${getUser().id}`, { replace: true });
      const redirect = def.listRedirect ? def.listRedirect(getUser(), p) : null;
      if (redirect) return navigate(redirect, { replace: true });
      const top = listTop ? listTop({ ...p, C: bolehBuat() }) : null;
      if (top) ctx.el.append(top);
      const lv = createListView({
        endpoint, columns, searchable, extraQuery: listQuery, filters, renderCard, searchPlaceholder, createLabel,
        createHref: bolehBuat() ? `#${base}/baru` : null,
        itemHref: (r) => `#${base}/${r.id}`,
      });
      ctx.el.append(lv.el);
    }, {
      title: title,
      guard: () => me(permissionSlug).R,
      alihan: () => (me(permissionSlug).selfOnly ? `${base}/${getUser().id}` : (def.listRedirect ? def.listRedirect(getUser(), me(permissionSlug)) : null)),
    });

    // Baru (didaftarkan sebelum :id)
    route(`${base}/baru`, (ctx) => renderForm(ctx, { mode: 'create' }), { title: `Tambah ${title}`, guard: bolehBuat });

    // Detail
    route(`${base}/:id`, async (ctx) => {
      const res = await api.get(`${endpoint}/${encodeURIComponent(ctx.params.id)}`);
      if (ctx.isStale()) return;
      const rec = unwrap(res);
      const p = permsRecord(rec);
      ctx.setTitle(recTitle(rec));
      const dl = h('dl', { class: 'detail' });
      for (const f of fieldsFor('detail', rec)) {
        if (f.onlyIfPresent && !(f.name in rec)) continue;
        const dt = h('dt', {}, f.label || f.name);
        const v = rec[f.name];
        const dd = f.type === 'photo' && v ? h('dd', {}, createAuthImage(v, { alt: f.label || f.name })) : h('dd', {}, displayValue(f, rec));
        dl.append(dt, dd);
      }
      ctx.el.append(h('div', { class: 'panel' }, dl));
      if (detailExtras) {
        const extra = await detailExtras(rec, p, ctx);
        if (ctx.isStale()) return;
        if (extra) ctx.el.append(...[extra].flat().filter(Boolean));
      }
      if (p.D) {
        // Hapus dari draf AI (?draft=uuid): kirim ai_draft_id pada DELETE bila draf memang aksi hapus untuk record ini.
        const uuidHapus = ctx.query.get('draft');
        const drafHapus = uuidHapus ? await muatDraf(uuidHapus) : null;
        if (ctx.isStale()) return;
        const dariDraf = draftCocok(drafHapus, { module: drafHapus?.module, marker: drafHapus?.payload?._resource ?? null, aksi: 'delete', targetId: rec.id })
          && jalurBersih(tujuanDraf(drafHapus)) === jalurBersih(ctx.path);
        if (dariDraf) ctx.el.append(h('div', { class: 'notice' }, 'Penghapusan ini diminta lewat Agen AI. Tekan Hapus untuk mengonfirmasi.'));
        ctx.el.append(h('button', {
          class: 'btn btn-danger btn-block', type: 'button',
          onclick: async () => {
            if (!(await showConfirm({ title: 'Hapus data', message: `Hapus "${recTitle(rec)}"? Tindakan ini tidak dapat dibatalkan.`, okText: 'Hapus', destructive: true }))) return;
            try {
              await api.del(pathHapus(`${endpoint}/${rec.id}`, dariDraf ? drafHapus.uuid || uuidHapus : null), { silent: true });
            } catch (e) {
              if (e?.status === 401) throw e;
              return toast(errorMessage(e, 'Gagal menghapus data.'), 'error');
            }
            toast('Data dihapus.', 'success');
            navigate(base, { replace: true });
          },
        }, 'Hapus'));
      }
      if (p.U) setMainButton({ text: 'Ubah', onClick: () => navigate(`${base}/${rec.id}/ubah`) });
    }, { title, guard: () => me(permissionSlug).R && aksesRecord() });

    // Ubah
    route(`${base}/:id/ubah`, async (ctx) => {
      const rec = unwrap(await api.get(`${endpoint}/${encodeURIComponent(ctx.params.id)}`));
      if (ctx.isStale()) return;
      // Akses langsung ke /ubah: izin per record dari server menentukan (relawan hari lalu, record terkunci, dst.).
      if (!permsRecord(rec).U) return alihkanKeBeranda(PESAN_BUKAN_HAK);
      return renderForm(ctx, { mode: 'update', record: rec });
    }, { title: `Ubah ${title}`, guard: () => me(permissionSlug).U && aksesRecord() });
  }

  return { slug, title, icon, description, permissionSlug, register, menuInfo };
}
