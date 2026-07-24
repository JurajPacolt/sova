export const SUPPORTED_LANGUAGES = ['sk', 'cs', 'en', 'de', 'pl', 'hu'] as const;

export type LanguageCode = (typeof SUPPORTED_LANGUAGES)[number];

export interface LanguageOption {
  readonly code: LanguageCode;
  readonly nativeName: string;
}

export const DEFAULT_LANGUAGE: LanguageCode = 'en';

export const LANGUAGE_OPTIONS: readonly LanguageOption[] = [
  { code: 'sk', nativeName: 'Slovenčina' },
  { code: 'cs', nativeName: 'Čeština' },
  { code: 'en', nativeName: 'English' },
  { code: 'de', nativeName: 'Deutsch' },
  { code: 'pl', nativeName: 'Polski' },
  { code: 'hu', nativeName: 'Magyar' },
];

export function isLanguageCode(value: string): value is LanguageCode {
  return SUPPORTED_LANGUAGES.some((language) => language === value);
}

export function resolvePreferredLanguage(languages: readonly string[]): LanguageCode {
  for (const language of languages) {
    const normalizedLanguage = language.trim().toLowerCase().split(/[-_]/u)[0] ?? '';

    if (isLanguageCode(normalizedLanguage)) {
      return normalizedLanguage;
    }
  }

  return DEFAULT_LANGUAGE;
}

export function getBrowserLanguages(): readonly string[] {
  if (typeof navigator === 'undefined') {
    return [];
  }

  if (navigator.languages.length > 0) {
    return navigator.languages;
  }

  return navigator.language ? [navigator.language] : [];
}
