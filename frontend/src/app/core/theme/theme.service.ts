import { DOCUMENT } from '@angular/common';
import { computed, DestroyRef, effect, inject, Injectable, signal } from '@angular/core';
import { isThemePreference, resolveTheme, ThemePreference, THEME_PREFERENCES } from './theme';

const STORAGE_KEY = 'sova.theme';
const SYSTEM_DARK_QUERY = '(prefers-color-scheme: dark)';

@Injectable({
  providedIn: 'root',
})
export class ThemeService {
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private readonly mediaQuery = this.createMediaQuery();
  private readonly systemPrefersDark = signal(this.mediaQuery?.matches ?? false);
  private readonly selectedPreference = signal(this.readInitialPreference());

  readonly preferences = THEME_PREFERENCES;
  readonly preference = this.selectedPreference.asReadonly();
  readonly resolvedTheme = computed(() =>
    resolveTheme(this.selectedPreference(), this.systemPrefersDark()),
  );

  constructor() {
    const mediaQueryListener = (event: MediaQueryListEvent): void => {
      this.systemPrefersDark.set(event.matches);
    };

    this.mediaQuery?.addEventListener('change', mediaQueryListener);
    this.destroyRef.onDestroy(() => {
      this.mediaQuery?.removeEventListener('change', mediaQueryListener);
    });

    effect(() => {
      const preference = this.selectedPreference();
      const root = this.document.documentElement;

      root.setAttribute('data-bs-theme', this.resolvedTheme());
      root.setAttribute('data-sova-theme-preference', preference);
      this.persistPreference(preference);
    });
  }

  setPreference(preference: ThemePreference): void {
    this.selectedPreference.set(preference);
  }

  private createMediaQuery(): MediaQueryList | null {
    const view = this.document.defaultView;

    return typeof view?.matchMedia === 'function' ? view.matchMedia(SYSTEM_DARK_QUERY) : null;
  }

  private readInitialPreference(): ThemePreference {
    const initialAttribute = this.document.documentElement.getAttribute(
      'data-sova-theme-preference',
    );

    if (initialAttribute !== null && isThemePreference(initialAttribute)) {
      return initialAttribute;
    }

    const storedPreference = this.readStoredPreference();

    return storedPreference ?? 'system';
  }

  private readStoredPreference(): ThemePreference | null {
    try {
      const value = this.document.defaultView?.localStorage.getItem(STORAGE_KEY);

      return value !== undefined && value !== null && isThemePreference(value) ? value : null;
    } catch {
      return null;
    }
  }

  private persistPreference(preference: ThemePreference): void {
    try {
      const storage = this.document.defaultView?.localStorage;

      if (preference === 'system') {
        storage?.removeItem(STORAGE_KEY);
      } else {
        storage?.setItem(STORAGE_KEY, preference);
      }
    } catch {
      // Theme selection remains active for the current page if storage is unavailable.
    }
  }
}
