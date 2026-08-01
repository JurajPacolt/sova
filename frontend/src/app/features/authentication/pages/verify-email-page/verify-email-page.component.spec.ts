import { Location } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { VerifyEmailPageComponent } from './verify-email-page.component';

describe('VerifyEmailPageComponent', () => {
  const token = 'B'.repeat(43);
  const api = {
    verifyEmail: vi.fn(),
  };
  const location = {
    replaceState: vi.fn(),
  };

  beforeEach(async () => {
    api.verifyEmail.mockReset();
    api.verifyEmail.mockReturnValue(of({ status: 'VERIFIED' }));
    location.replaceState.mockReset();

    await TestBed.configureTestingModule({
      imports: [VerifyEmailPageComponent],
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

  it('verifies automatically and removes the token from browser history', () => {
    const fixture = TestBed.createComponent(VerifyEmailPageComponent);
    fixture.detectChanges();

    expect(location.replaceState).toHaveBeenCalledWith('/verify-email');
    expect(api.verifyEmail).toHaveBeenCalledWith({ token });
    expect((fixture.nativeElement as HTMLElement).querySelector('.alert-success')).not.toBeNull();
  });
});
