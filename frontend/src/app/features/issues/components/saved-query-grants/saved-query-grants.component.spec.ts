import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { SavedQueryGrantsComponent } from './saved-query-grants.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const SAVED_QUERY_ID = '019f9f00-0000-7000-8000-0000000000aa';
const GRANTS = `/api/v1/tenants/${TENANT_ID}/saved-queries/${SAVED_QUERY_ID}/grants`;

describe('SavedQueryGrantsComponent', () => {
  let fixture: ComponentFixture<SavedQueryGrantsComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [SavedQueryGrantsComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: TenantStore,
          useValue: { activeTenantId: () => TENANT_ID, hasPermission: () => true },
        },
      ],
    });

    fixture = TestBed.createComponent(SavedQueryGrantsComponent);
    fixture.componentRef.setInput('savedQueryId', SAVED_QUERY_ID);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  function clickButton(label: string): void {
    const element: HTMLElement = fixture.nativeElement;
    const target = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes(label),
    );

    if (target === undefined) {
      throw new Error(`No button labelled "${label}".`);
    }

    target.click();
    fixture.detectChanges();
  }

  function initialise(
    grants: readonly Record<string, unknown>[],
    memberships: readonly Record<string, unknown>[] = [],
    workgroups: readonly Record<string, unknown>[] = [],
  ): void {
    fixture.detectChanges();
    http.expectOne(GRANTS).flush({ grants });
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/memberships`).flush({ memberships });
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/workgroups`).flush({ workgroups });
    fixture.detectChanges();
  }

  it('says plainly that sharing does not hand out the issues', () => {
    initialise([]);

    // The wording is load-bearing: a grant lets somebody run the query, and the
    // rows are still intersected with their own issue.view scope.
    expect(fixture.nativeElement.textContent).toContain(
      'It never grants access to issues they could not already see.',
    );
  });

  it('sends the complete set so a removed principal really loses access', () => {
    initialise([
      { id: 'g-1', membership_id: 'm-1', workgroup_id: null, display_name: 'Ada', access: 'VIEW' },
      { id: 'g-2', membership_id: 'm-2', workgroup_id: null, display_name: 'Bo', access: 'EDIT' },
    ]);

    const element: HTMLElement = fixture.nativeElement;
    const removeButtons = [...element.querySelectorAll('button')].filter((button) =>
      button.textContent?.includes('Remove'),
    );
    removeButtons[0].click();
    fixture.detectChanges();

    clickButton('Save sharing');

    const request = http.expectOne(GRANTS);
    expect(request.request.method).toBe('PUT');
    // Ada is not sent as a deletion — she is simply absent from the new set.
    expect(request.request.body).toEqual({
      grants: [{ membership_id: 'm-2', workgroup_id: null, access: 'EDIT' }],
    });
    request.flush({ grants: [] });
  });

  it('names exactly one principal per grant', () => {
    initialise([], [], [{ id: 'w-1', name: 'Platform', status: 'ACTIVE', member_count: 3 }]);

    (
      fixture.componentInstance as unknown as { selectedPrincipal: { set(value: string): void } }
    ).selectedPrincipal.set('w-1');
    fixture.detectChanges();

    clickButton('Add');
    clickButton('Save sharing');

    const request = http.expectOne(GRANTS);
    // A workgroup grant carries a null membership, never both identifiers.
    expect(request.request.body).toEqual({
      grants: [{ membership_id: null, workgroup_id: 'w-1', access: 'VIEW' }],
    });
    request.flush({ grants: [] });
  });

  it('does not offer a disabled member as a candidate', () => {
    initialise(
      [],
      [
        { id: 'm-1', user: { display_name: 'Ada' }, status: 'ACTIVE' },
        { id: 'm-2', user: { display_name: 'Removed person' }, status: 'DISABLED' },
      ],
    );

    const element: HTMLElement = fixture.nativeElement;
    const options = [...element.querySelectorAll('option')].map((option) => option.textContent);
    expect(options).toContain('Ada');
    // The server would reject it identically to somebody who does not exist, so
    // there is nothing to gain by offering it.
    expect(options).not.toContain('Removed person');
  });
});
