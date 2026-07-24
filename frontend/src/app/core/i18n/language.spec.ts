import { DEFAULT_LANGUAGE, resolvePreferredLanguage } from './language';

describe('resolvePreferredLanguage', () => {
  it('normalizes a supported regional browser language', () => {
    expect(resolvePreferredLanguage(['sk-SK'])).toBe('sk');
    expect(resolvePreferredLanguage(['cs_CZ'])).toBe('cs');
  });

  it('uses the first supported language from the browser preference order', () => {
    expect(resolvePreferredLanguage(['fr-FR', 'de-AT', 'en-US'])).toBe('de');
  });

  it('falls back to English when no browser language is supported', () => {
    expect(resolvePreferredLanguage(['fr-FR', 'it-IT'])).toBe(DEFAULT_LANGUAGE);
    expect(DEFAULT_LANGUAGE).toBe('en');
  });
});
