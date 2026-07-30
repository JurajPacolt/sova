import { TestBed } from '@angular/core/testing';
import { ConnectivityService } from './connectivity.service';

describe('ConnectivityService', () => {
  function create(online: boolean): ConnectivityService {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(online);
    TestBed.configureTestingModule({});

    return TestBed.inject(ConnectivityService);
  }

  afterEach(() => {
    vi.restoreAllMocks();
    TestBed.resetTestingModule();
  });

  it('starts from what the browser already knows', () => {
    expect(create(false).online()).toBe(false);
  });

  it('follows the connection in both directions', () => {
    const connectivity = create(true);

    window.dispatchEvent(new Event('offline'));
    expect(connectivity.online()).toBe(false);

    window.dispatchEvent(new Event('online'));
    expect(connectivity.online()).toBe(true);
  });
});
