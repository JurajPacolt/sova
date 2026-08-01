import { HttpErrorResponse } from '@angular/common/http';
import { isProblemDetails } from '../api/api.models';

const SESSION_ERROR_CODES = new Set(['SESSION_REQUIRED', 'AUTHENTICATION_REQUIRED']);

export function isSessionRequiredError(error: HttpErrorResponse): boolean {
  return (
    error.status === 401 &&
    isProblemDetails(error.error) &&
    SESSION_ERROR_CODES.has(error.error.code)
  );
}
