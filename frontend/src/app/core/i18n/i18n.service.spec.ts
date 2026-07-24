import { TestBed } from '@angular/core/testing';
import { I18nService } from './i18n.service';

describe('I18nService', () => {
  it('switches catalogs at runtime', () => {
    const service = TestBed.inject(I18nService);

    service.setLanguage('de');

    expect(service.language()).toBe('de');
    expect(service.translate('auth.submit')).toBe('Anmelden');
  });

  it('interpolates translation parameters', () => {
    const service = TestBed.inject(I18nService);
    service.setLanguage('en');

    expect(service.translate('projects.openIssues', { count: 12 })).toBe('Open issues: 12');
  });
});
