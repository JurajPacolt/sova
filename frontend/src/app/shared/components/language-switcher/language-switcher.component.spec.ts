import { TestBed } from '@angular/core/testing';
import { I18nService } from '../../../core/i18n/i18n.service';
import { LanguageSwitcherComponent } from './language-switcher.component';

describe('LanguageSwitcherComponent', () => {
  it('shows the active runtime language after options render', async () => {
    await TestBed.configureTestingModule({
      imports: [LanguageSwitcherComponent],
    }).compileComponents();

    const fixture = TestBed.createComponent(LanguageSwitcherComponent);
    TestBed.inject(I18nService).setLanguage('de');
    fixture.detectChanges();

    const selector = fixture.nativeElement.querySelector('select') as HTMLSelectElement;

    expect(selector.value).toBe('de');
  });
});
