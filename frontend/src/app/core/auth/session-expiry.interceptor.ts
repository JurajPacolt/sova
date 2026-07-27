import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { isSessionRequiredError } from './session-error';
import { SessionExpiryHandler } from './session-expiry.handler';

export const sessionExpiryInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) {
    return next(request);
  }

  const expiryHandler = inject(SessionExpiryHandler);

  return next(request).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && isSessionRequiredError(error)) {
        expiryHandler.handle();
      }

      return throwError(() => error);
    }),
  );
};
