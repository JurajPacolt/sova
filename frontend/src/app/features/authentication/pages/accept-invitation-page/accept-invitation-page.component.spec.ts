import { Location } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { AcceptInvitationPageComponent } from './accept-invitation-page.component';

describe('AcceptInvitationPageComponent', () => {
  const token = 'C'.repeat(43);
  const api = {
    inspectInvitation: vi.fn(),
    acceptNewAccountInvitation: vi.fn(),
  };
  const auth = {
    ensureAuthenticated: vi.fn(),
  };
  const location = {
    replaceState: vi.fn(),
  };

  beforeEach(async () => {
    api.inspectInvitation.mockReset();
    api.acceptNewAccountInvitation.mockReset();
    auth.ensureAuthenticated.mockReset();
    location.replaceState.mockReset();
    api.inspectInvitation.mockReturnValue(
      of({
        invitation: {
          tenant_name: 'Acme',
          tenant_slug: 'acme',
          email: 'invited@example.test',
          invited_by_display_name: 'Owner',
          expires_at: '2026-08-02T00:00:00+00:00',
        },
      }),
    );
    api.acceptNewAccountInvitation.mockReturnValue(
      of({
        user_id: '019f9f00-0000-7000-8000-000000000001',
        tenant_id: '019f9f00-0000-7000-8000-000000000002',
        tenant_slug: 'acme',
        membership_created: true,
      }),
    );
    auth.ensureAuthenticated.mockReturnValue(of(false));

    await TestBed.configureTestingModule({
      imports: [AcceptInvitationPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: convertToParamMap({ token }),
            },
          },
        },
        {
          provide: Location,
          useValue: location,
        },
        {
          provide: SovaApiClient,
          useValue: api,
        },
        {
          provide: AuthSessionService,
          useValue: auth,
        },
      ],
    }).compileComponents();
  });

  it('inspects the token then creates the invited account without exposing it in the URL', () => {
    const fixture = TestBed.createComponent(AcceptInvitationPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const displayName = root.querySelector<HTMLInputElement>('#invitation-display-name');
    const password = root.querySelector<HTMLInputElement>('#invitation-password');
    const confirmation = root.querySelector<HTMLInputElement>('#invitation-password-confirmation');
    const form = root.querySelector<HTMLFormElement>('form');
    const passphrase = 'a unique invitation passphrase';

    displayName!.value = 'Invited Member';
    displayName!.dispatchEvent(new Event('input'));
    password!.value = passphrase;
    password!.dispatchEvent(new Event('input'));
    confirmation!.value = passphrase;
    confirmation!.dispatchEvent(new Event('input'));
    form!.dispatchEvent(new Event('submit'));

    expect(location.replaceState).toHaveBeenCalledWith('/accept-invitation');
    expect(api.inspectInvitation).toHaveBeenCalledWith({ token });
    expect(api.acceptNewAccountInvitation).toHaveBeenCalledWith({
      token,
      display_name: 'Invited Member',
      preferred_locale: 'en',
      password: passphrase,
      password_confirmation: passphrase,
    });
  });
});
