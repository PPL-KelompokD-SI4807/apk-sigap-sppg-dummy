import { can } from '../core/permissions.js';

// Setiap modules/<nama>/index.js mengekspor default: objek modul (hasil defineCrudModule) atau array-nya.
// Modul baru cukup menambah folder; tidak ada daftar yang perlu diedit.
const files = import.meta.glob('./*/index.js', { eager: true });

const modules = [];
const seen = new Set();
// Folder berawalan "_" (contoh) diproses terakhir: modul asli menang bila slug sama.
const entries = Object.entries(files).sort(([a], [b]) => Number(a.startsWith('./_')) - Number(b.startsWith('./_')) || a.localeCompare(b));
for (const [path, mod] of entries) {
  for (const m of [mod.default].flat().filter(Boolean)) {
    if (seen.has(m.slug)) { console.warn(`[SiGAP] Slug modul ganda dilewati: ${m.slug} (${path})`); continue; }
    seen.add(m.slug);
    modules.push(m);
  }
}

export const getModules = () => modules;
export const menuFor = (role) => modules.filter((m) => !m.hidden && can(role, m.permissionSlug ?? m.slug, 'R'));
export function registerAllRoutes() { modules.forEach((m) => m.register?.()); }
