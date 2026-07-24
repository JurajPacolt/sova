import { TestBed } from '@angular/core/testing';
import { I18nService } from '../../../core/i18n/i18n.service';
import { StatusBadgeComponent } from './status-badge.component';

describe('StatusBadgeComponent', () => {
  it('renders a localized label for the signal input', async () => {
    await TestBed.configureTestingModule({
      imports: [StatusBadgeComponent],
    }).compileComponents();

    const fixture = TestBed.createComponent(StatusBadgeComponent);
    TestBed.inject(I18nService).setLanguage('sk');
    fixture.componentRef.setInput('status', 'in-progress');
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Rozpracovaná');
  });
});
