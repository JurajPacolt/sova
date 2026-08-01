(() => {
  const storageKey = 'sova.theme';
  const root = document.documentElement;
  let preference = 'system';

  try {
    const storedPreference = localStorage.getItem(storageKey);

    if (storedPreference === 'light' || storedPreference === 'dark') {
      preference = storedPreference;
    }
  } catch {
    // Storage can be unavailable in privacy modes; the system preference still works.
  }

  const systemPrefersDark =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-color-scheme: dark)').matches;
  const resolvedTheme =
    preference === 'system' ? (systemPrefersDark ? 'dark' : 'light') : preference;

  root.setAttribute('data-bs-theme', resolvedTheme);
  root.setAttribute('data-sova-theme-preference', preference);
})();
