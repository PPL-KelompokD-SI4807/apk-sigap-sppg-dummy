// Antrian draf lokal (IndexedDB) untuk kiriman yang gagal karena offline. Blob/File ikut tersimpan.
const DB = 'sigap-miniapp';
const STORE = 'queue';

function open() {
  return new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) return reject(new Error('IndexedDB tidak tersedia'));
    const req = indexedDB.open(DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function tx(mode, fn) {
  const db = await open();
  return new Promise((resolve, reject) => {
    const t = db.transaction(STORE, mode);
    const out = fn(t.objectStore(STORE));
    t.oncomplete = () => resolve(out.result);
    t.onerror = () => reject(t.error);
  });
}

export const enqueue = (item) => tx('readwrite', (s) => s.put(item)).catch(() => false);
export const removeItem = (id) => tx('readwrite', (s) => s.delete(id)).catch(() => false);
export const listQueue = () => tx('readonly', (s) => s.getAll()).then((r) => (r || []).sort((a, b) => a.createdAt - b.createdAt)).catch(() => []);
export const countQueue = () => tx('readonly', (s) => s.count()).catch(() => 0);
