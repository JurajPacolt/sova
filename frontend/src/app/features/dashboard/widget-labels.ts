import { TRANSLATIONS, TranslationKey } from '../../core/i18n/translations';

/**
 * Wording for the values the widget registry ships.
 *
 * The registry carries **keys, never text** — that is what keeps user-facing
 * wording in the six catalogs and out of the server. A key this build does not
 * know is therefore not rendered as itself: a raw `statusCategory` or an
 * invented type key on the page would read as something a person wrote.
 */
export function widgetTypeLabelKey(labelKey: string): TranslationKey {
  return known(labelKey) ?? 'dashboard.widget.unknownType';
}

/** An aggregation dimension (`status`, `statusCategory`, …) as a catalog key. */
export function widgetDimensionLabelKey(dimension: string): TranslationKey {
  return known(`dashboard.dimension.${dimension}`) ?? 'dashboard.dimension.unknown';
}

/** The English catalog derives `TranslationKey`, so it is the one to ask. */
function known(key: string): TranslationKey | null {
  return key in TRANSLATIONS.en ? (key as TranslationKey) : null;
}
