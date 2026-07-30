import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { AccessibleTenant, LoginResponse } from '../../../../core/api/api.models';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { LoginPageComponent } from './login-page.component';

const TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme',
  slug: 'acme',
  status: 'ACTIVE',
  access: {
    type: 'MEMBERSHIP',
    membership_id: '019f9f00-0000-7000-8000-000000000002',
  },
};

const LOGIN: LoginResponse = {
  user: {
    id: '019f9f00-0000-7000-8000-000000000003',
    email: 'member@example.test',
    display_name: 'Member',
    preferred_locale: 'sk',
    is_superadmin: false,
  },
  session: {
    id: '019f9f00-0000-7000-8000-000000000004',
    expires_at: '2026-07-27T00:00:00+00:00',
  },
  mfa: {
    enabled: false,
    verified: false,
    enrollment_required: false,
    recovery_codes_remaining: 0,
  },
};

describe('LoginPageComponent', () => {
  const auth = {
    login: vi.fn(),
  };
  const router = {
    navigateByUrl: vi.fn().mockResolvedValue(true),
  };

  beforeEach(async () => {
    auth.login.mockReset();
    router.navigateByUrl.mockClear();

    await TestBed.configureTestingModule({
      imports: [LoginPageComponent],
      providers: [
        {
          provide: AuthSessionService,
          useValue: auth,
        },
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              queryParamMap: convertToParamMap({
                returnUrl: '/t/acme/projects',
              }),
            },
          },
        },
        {
          provide: Router,
          useValue: router,
        },
      ],
    }).compileComponents();
  });

  it('submits credentials to the API service and follows an authorized return URL', () => {
    auth.login.mockReturnValue(of({ login: LOGIN, tenants: [TENANT] }));
    const fixture = TestBed.createComponent(LoginPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const email = root.querySelector<HTMLInputElement>('#email');
    const password = root.querySelector<HTMLInputElement>('#password');
    const form = root.querySelector<HTMLFormElement>('form');

    expect(email).not.toBeNull();
    expect(password).not.toBeNull();
    expect(form).not.toBeNull();

    email!.value = 'member@example.test';
    email!.dispatchEvent(new Event('input'));
    password!.value = 'correct horse battery staple';
    password!.dispatchEvent(new Event('input'));
    form!.dispatchEvent(new Event('submit'));

    expect(auth.login).toHaveBeenCalledWith({
      email: 'member@example.test',
      password: 'correct horse battery staple',
    });
    expect(router.navigateByUrl).toHaveBeenCalledWith('/t/acme/projects');
  });

  it('requests and resubmits the second factor after an MFA challenge', () => {
    auth.login
      .mockReturnValueOnce(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 401,
              error: {
                type: 'urn:sova:problem:authentication-required',
                title: 'Authentication required',
                status: 401,
                detail: 'A multi-factor authentication code is required.',
                instance: '/api/v1/auth/login',
                request_id: 'request-id',
                code: 'MFA_CODE_REQUIRED',
              },
            }),
        ),
      )
      .mockReturnValueOnce(
        of({ login: { ...LOGIN, mfa: { ...LOGIN.mfa, enabled: true } }, tenants: [TENANT] }),
      );
    const fixture = TestBed.createComponent(LoginPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const email = root.querySelector<HTMLInputElement>('#email')!;
    const password = root.querySelector<HTMLInputElement>('#password')!;
    const form = root.querySelector<HTMLFormElement>('form')!;

    email.value = 'member@example.test';
    email.dispatchEvent(new Event('input'));
    password.value = 'correct horse battery staple';
    password.dispatchEvent(new Event('input'));
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const code = root.querySelector<HTMLInputElement>('#mfa-code');
    expect(code).not.toBeNull();
    code!.value = '123456';
    code!.dispatchEvent(new Event('input'));
    form.dispatchEvent(new Event('submit'));

    expect(auth.login).toHaveBeenLastCalledWith({
      email: 'member@example.test',
      password: 'correct horse battery staple',
      mfa_code: '123456',
    });
  });
});
