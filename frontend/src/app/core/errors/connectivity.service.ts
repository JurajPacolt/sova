import { DestroyRef, inject, Injectable, signal } from '@angular/core';

/**
 * Whether the browser believes it has a connection.
 *
 * SOVA is not an offline application (webflow `05-STAVY-ROZHRANIA.md` §10); it
 * only has to survive short drops. So this is deliberately small: it reports the
 * state, and screens decide what that means for them — keep what is on screen
 * and mark it out of date, keep what is being typed, and never repeat a mutation
 * on its own.
 *
 * `navigator.onLine` is a hint, not a guarantee: it says the machine has a
 * network, not that the API is reachable. A request that fails with status `0`
 * is the stronger signal, which is why `describeApiError()` reports that
 * separately rather than asking this service.
 */
@Injectable({ providedIn: 'root' })
export class ConnectivityService {
  private readonly connected = signal(true);

  readonly online = this.connected.asReadonly();

  constructor() {
    const destroyRef = inject(DestroyRef);

    if (typeof window === 'undefined') {
      return;
    }

    this.connected.set(window.navigator.onLine);

    const goOnline = (): void => this.connected.set(true);
    const goOffline = (): void => this.connected.set(false);

    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);

    destroyRef.onDestroy(() => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
    });
  }
}
