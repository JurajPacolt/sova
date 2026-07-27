import { inject, Injectable } from '@angular/core';
import { Router } from '@angular/router';
import { sanitizeReturnUrl } from '../navigation/return-url';
import { AuthSessionService } from './auth-session.service';

@Injectable({
  providedIn: 'root',
})
export class SessionExpiryHandler {
  private readonly auth = inject(AuthSessionService);
  private readonly router = inject(Router);
  private navigating = false;

  handle(): void {
    this.auth.invalidate();
    const navigation = this.router.currentNavigation();
    const navigationUrl =
      navigation === null
        ? null
        : this.router.serializeUrl(navigation.finalUrl ?? navigation.extractedUrl);

    if (
      this.navigating ||
      this.router.url.startsWith('/login') ||
      isPublicAccessPath(this.router.url) ||
      (navigationUrl !== null && isPublicAccessPath(navigationUrl))
    ) {
      return;
    }

    this.navigating = true;
    const returnUrl = sanitizeReturnUrl(this.router.url);
    const tree = this.router.createUrlTree(['/login'], {
      queryParams: returnUrl === null ? undefined : { returnUrl },
    });

    void this.router.navigateByUrl(tree).finally(() => {
      this.navigating = false;
    });
  }
}

export function isPublicAccessPath(url: string): boolean {
  const path = url.split(/[?#]/u, 1)[0] ?? '';

  return ['/forgot-password', '/reset-password', '/verify-email', '/accept-invitation'].some(
    (publicPath) => path === publicPath || path.startsWith(`${publicPath}/`),
  );
}
