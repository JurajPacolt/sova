import { Location } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { ResetPasswordPageComponent } from './reset-password-page.component';

describe('ResetPasswordPageComponent', () => {
  const token = 'A'.repeat(43);
  const api = {
    resetPassword: vi.fn(),
  };
  const location = {
    replaceState: vi.fn(),
  };

  beforeEach(async () => {
    api.resetPassword.mockReset();
    api.resetPassword.mockReturnValue(of(undefined));
    location.replaceState.mockReset();

    await TestBed.configureTestingModule({
      imports: [ResetPasswordPageComponent],
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
      ],
    }).compileComponents();
  });

  it('removes the token from browser history and submits it only in the API body', () => {
    const fixture = TestBed.createComponent(ResetPasswordPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const password = root.querySelector<HTMLInputElement>('#new-password');
    const confirmation = root.querySelector<HTMLInputElement>('#password-confirmation');
    const form = root.querySelector<HTMLFormElement>('form');
    const passphrase = 'a unique frontend passphrase';

    password!.value = passphrase;
    password!.dispatchEvent(new Event('input'));
    confirmation!.value = passphrase;
    confirmation!.dispatchEvent(new Event('input'));
    form!.dispatchEvent(new Event('submit'));

    expect(location.replaceState).toHaveBeenCalledWith('/reset-password');
    expect(api.resetPassword).toHaveBeenCalledWith({
      token,
      password: passphrase,
      password_confirmation: passphrase,
    });
  });
});
