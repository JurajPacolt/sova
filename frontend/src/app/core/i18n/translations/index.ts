import type { LanguageCode } from '../language';
import { CS_TRANSLATIONS } from './cs';
import { DE_TRANSLATIONS } from './de';
import { EN_TRANSLATIONS } from './en';
import type { TranslationKey } from './en';
import { HU_TRANSLATIONS } from './hu';
import { PL_TRANSLATIONS } from './pl';
import { SK_TRANSLATIONS } from './sk';

export const TRANSLATIONS: Record<LanguageCode, Readonly<Record<TranslationKey, string>>> = {
  cs: CS_TRANSLATIONS,
  de: DE_TRANSLATIONS,
  en: EN_TRANSLATIONS,
  hu: HU_TRANSLATIONS,
  pl: PL_TRANSLATIONS,
  sk: SK_TRANSLATIONS,
};

export type { TranslationKey };
