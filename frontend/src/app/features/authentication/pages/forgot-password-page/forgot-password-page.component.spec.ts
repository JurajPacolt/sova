import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { ForgotPasswordPageComponent } from './forgot-password-page.component';

describe('ForgotPasswordPageComponent', () => {
  const api = {
    requestPasswordReset: vi.fn(),
  };

  beforeEach(async () => {
    api.requestPasswordReset.mockReset();
    api.requestPasswordReset.mockReturnValue(of({ message: 'accepted' }));

    await TestBed.configureTestingModule({
      imports: [ForgotPasswordPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    }).compileComponents();
  });

  it('submits only the email and shows the generic accepted state', () => {
    const fixture = TestBed.createComponent(ForgotPasswordPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const email = root.querySelector<HTMLInputElement>('#recovery-email');
    const form = root.querySelector<HTMLFormElement>('form');

    expect(email).not.toBeNull();
    expect(form).not.toBeNull();

    email!.value = 'member@example.test';
    email!.dispatchEvent(new Event('input'));
    form!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(api.requestPasswordReset).toHaveBeenCalledWith({
      email: 'member@example.test',
    });
    expect(root.querySelector('[role="status"]')).not.toBeNull();
  });
});
