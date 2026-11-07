// Penyusun menu beranda dari peta hak server (murni, tanpa Vite/DOM agar dapat diuji node --test).
import { petaValid, userPerms } from '../core/permissions.js';

/**
 * Menu = modul tidak tersembunyi yang punya hak baca (R) menurut peta server. Peta tak valid => menu kosong
 * (gagal tertutup). `menuInfo(user)` pada modul boleh mengganti judul/deskripsi kartu sesuai hak.
 */
export function menuUntuk(user, modules) {
  if (!petaValid(user)) return [];
  return modules
    .filter((m) => !m.hidden && userPerms(user, m.permissionSlug ?? m.slug).R)
    .map((m) => {
      const info = typeof m.menuInfo === 'function' ? m.menuInfo(user) ?? {} : {};
      return { ...m, title: info.title ?? m.title, description: info.description ?? m.description };
    });
}
