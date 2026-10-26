// Shim Telegram.WebApp untuk dev di browser biasa. Hanya dimuat bila import.meta.env.DEV (lihat main.js).
// Pakai ?dev_role=kepala_sppg (default) dan ?dev_theme=dark.
export function installShim() {
  const q = new URLSearchParams(location.search);
  const role = q.get('dev_role') || 'kepala_sppg';
  const dark = q.get('dev_theme') === 'dark';
  const user = { id: 1000, first_name: 'Pengguna', last_name: 'Dev', username: 'dev_' + role };
  const noop = () => {};
  const btn = () => ({ isVisible: false, show: noop, hide: noop, onClick: noop, offClick: noop, setParams: noop, setText: noop, showProgress: noop, hideProgress: noop, enable: noop, disable: noop });
  const themeParams = dark
    ? { bg_color: '#17212b', text_color: '#f5f5f5', hint_color: '#708499', link_color: '#6ab3f3', button_color: '#5288c1', button_text_color: '#ffffff', secondary_bg_color: '#232e3c', header_bg_color: '#17212b', section_bg_color: '#1c2733', section_separator_color: '#2b3a4a', accent_text_color: '#6ab2f2', destructive_text_color: '#ec3942' }
    : { bg_color: '#ffffff', text_color: '#000000', hint_color: '#707579', link_color: '#2481cc', button_color: '#2481cc', button_text_color: '#ffffff', secondary_bg_color: '#f0f0f5', header_bg_color: '#ffffff', section_bg_color: '#ffffff', section_separator_color: '#e0e0e0', accent_text_color: '#2481cc', destructive_text_color: '#d32f2f' };
  window.Telegram = window.Telegram || {};
  window.Telegram.WebApp = {
    __shim: true,
    version: '8.0',
    platform: 'unknown',
    colorScheme: dark ? 'dark' : 'light',
    themeParams,
    initData: 'query_id=DEV&user=' + encodeURIComponent(JSON.stringify(user)) + '&auth_date=' + Math.floor(Date.now() / 1000) + '&hash=dev&dev_role=' + role,
    initDataUnsafe: { user, start_param: q.get('startapp') || undefined, dev_role: role },
    ready: noop, expand: noop, close: noop, setHeaderColor: noop, setBackgroundColor: noop,
    onEvent: noop, offEvent: noop,
    BackButton: { isVisible: false, show: noop, hide: noop, onClick: noop, offClick: noop },
    MainButton: btn(),
    HapticFeedback: { impactOccurred: noop, notificationOccurred: noop, selectionChanged: noop },
    showConfirm: (message, cb) => cb(window.confirm(message)),
    showPopup: (params, cb) => { window.alert(params.message); cb?.(''); },
  };
  console.info('[SiGAP] Shim Telegram aktif, peran dev:', role);
}
