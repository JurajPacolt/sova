import { DOCUMENT } from '@angular/common';
import { effect, inject, Injectable, signal } from '@angular/core';
import {
  DEFAULT_LANGUAGE,
  getBrowserLanguages,
  LanguageCode,
  resolvePreferredLanguage,
} from './language';
import { TRANSLATIONS, TranslationKey } from './translations';

export type TranslationParams = Readonly<Record<string, string | number>>;

@Injectable({
  providedIn: 'root',
})
export class I18nService {
  private readonly document = inject(DOCUMENT);
  private readonly currentLanguage = signal(resolvePreferredLanguage(getBrowserLanguages()));

  readonly language = this.currentLanguage.asReadonly();

  constructor() {
    effect(() => {
      this.document.documentElement.lang = this.currentLanguage();
    });
  }

  setLanguage(language: LanguageCode): void {
    this.currentLanguage.set(language);
  }

  translate(key: TranslationKey, params: TranslationParams = {}): string {
    const activeCatalog = TRANSLATIONS[this.currentLanguage()];
    const template = activeCatalog[key] ?? TRANSLATIONS[DEFAULT_LANGUAGE][key] ?? key;

    return template.replace(/\{\{(\w+)\}\}/gu, (placeholder, parameterName: string) => {
      const value = params[parameterName];

      return value === undefined ? placeholder : String(value);
    });
  }
}
