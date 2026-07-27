import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AdminOverviewPageComponent } from './admin-overview-page.component';

describe('AdminOverviewPageComponent', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdminOverviewPageComponent],
      providers: [provideRouter([])],
    }).compileComponents();
  });

  it('links the members, roles, audit, and workgroup sections to their routes', () => {
    const fixture = TestBed.createComponent(AdminOverviewPageComponent);
    fixture.detectChanges();
    const links = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('a[href]'),
    ).map((link) => link.getAttribute('href'));

    expect(links).toContain('/members');
    expect(links).toContain('/roles');
    expect(links).toContain('/audit');
    expect(links).toContain('/workgroups');
  });

  it('disables the not-yet-available project section', () => {
    const fixture = TestBed.createComponent(AdminOverviewPageComponent);
    fixture.detectChanges();
    const disabledButtons = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>(
        'button[disabled]',
      ),
    );

    expect(disabledButtons).toHaveLength(1);
  });
});
