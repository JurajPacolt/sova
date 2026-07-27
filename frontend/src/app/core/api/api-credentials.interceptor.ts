import { DOCUMENT } from '@angular/common';
import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';

const API_PREFIX = '/api/';
const CSRF_COOKIE_NAME = 'sova_csrf';
const CSRF_HEADER_NAME = 'X-CSRF-Token';
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export const apiCredentialsInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith(API_PREFIX)) {
    return next(request);
  }

  let authenticatedRequest = request.clone({ withCredentials: true });

  if (!SAFE_METHODS.has(request.method.toUpperCase())) {
    const csrfToken = readCookie(inject(DOCUMENT).cookie, CSRF_COOKIE_NAME);

    if (csrfToken !== null) {
      authenticatedRequest = authenticatedRequest.clone({
        setHeaders: {
          [CSRF_HEADER_NAME]: csrfToken,
        },
      });
    }
  }

  return next(authenticatedRequest);
};

export function readCookie(cookieHeader: string, name: string): string | null {
  for (const cookie of cookieHeader.split(';')) {
    const separatorIndex = cookie.indexOf('=');

    if (separatorIndex < 0) {
      continue;
    }

    const candidateName = cookie.slice(0, separatorIndex).trim();

    if (candidateName !== name) {
      continue;
    }

    const value = cookie.slice(separatorIndex + 1);

    try {
      return decodeURIComponent(value);
    } catch {
      return null;
    }
  }

  return null;
}
