import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { AdminOverviewPageComponent } from './admin-overview-page.component';

describe('AdminOverviewPageComponent', () => {
  function render(permissions: readonly string[]): HTMLElement {
    TestBed.configureTestingModule({
      imports: [AdminOverviewPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: TenantStore,
          useValue: {
            hasAnyPermission: (codes: readonly string[]) =>
              codes.some((code) => permissions.includes(code)),
            hasPermission: (code: string) => permissions.includes(code),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(AdminOverviewPageComponent);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function hrefs(element: HTMLElement): readonly (string | null)[] {
    return Array.from(element.querySelectorAll<HTMLAnchorElement>('a[href]')).map((link) =>
      link.getAttribute('href'),
    );
  }

  afterEach(() => TestBed.resetTestingModule());

  it('links every section it offers, projects included', () => {
    const links = hrefs(
      render([
        'tenant.settings.manage',
        'tenant.members.view',
        'tenant.roles.view',
        'tenant.workgroups.manage',
        'tenant.audit.view',
      ]),
    );

    expect(links).toContain('/members');
    expect(links).toContain('/settings');
    expect(links).toContain('/roles');
    expect(links).toContain('/audit');
    expect(links).toContain('/workgroups');
    // Projects live outside the administration area, so the card leaves it.
    expect(links).toContain('/projects');
  });

  /**
   * A card that leads straight to the 403 screen is a promise the overview
   * cannot keep, so the same permissions the route guard asks for decide
   * whether it is drawn at all.
   */
  it('shows only the sections the caller may actually open', () => {
    const links = hrefs(render(['tenant.audit.view']));

    expect(links).toContain('/audit');
    expect(links).toContain('/projects');
    expect(links).not.toContain('/members');
    expect(links).not.toContain('/settings');
    expect(links).not.toContain('/roles');
    expect(links).not.toContain('/workgroups');
  });
});
