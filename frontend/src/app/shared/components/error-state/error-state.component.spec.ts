import { HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ErrorStateComponent } from './error-state.component';

function failure(status: number, body: unknown = null): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    statusText: 'Error',
    error: body,
    headers: new HttpHeaders({ 'X-Request-ID': 'req-42' }),
    url: '/api/v1/things',
  });
}

describe('ErrorStateComponent', () => {
  let fixture: ComponentFixture<ErrorStateComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [ErrorStateComponent] });
    fixture = TestBed.createComponent(ErrorStateComponent);
  });

  function render(error: unknown): HTMLElement {
    fixture.componentRef.setInput('error', error);
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  it('says what the status means rather than showing a code', () => {
    const element = render(failure(403));

    expect(element.textContent).toContain('You do not have permission');
    expect(element.querySelector('[role="alert"]')).not.toBeNull();
  });

  /** Repeating a refusal is not a retry, it is a second refusal. */
  it('offers a retry for a server fault and none for a refusal', () => {
    expect(render(failure(500)).querySelector('button')).not.toBeNull();

    fixture.componentRef.setInput('error', failure(403));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('button')).toBeNull();
  });

  /**
   * The correlation identifier is the only thing that finds this exact request
   * in the logs, and it is worth the space precisely when the fault is ours.
   */
  it('shows the correlation identifier for a server fault only', () => {
    expect(render(failure(500)).textContent).toContain('req-42');

    fixture.componentRef.setInput('error', failure(404));
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).not.toContain('req-42');
  });

  it('lets a screen keep a sentence of its own', () => {
    fixture.componentRef.setInput('messageKey', 'dashboard.notFound');
    const element = render(failure(404));

    expect(element.textContent).toContain('This dashboard is no longer available.');
  });

  it('reports the wait when the server said how long', () => {
    const rateLimited = new HttpErrorResponse({
      status: 429,
      statusText: 'Too Many Requests',
      headers: new HttpHeaders({ 'Retry-After': '30' }),
      url: '/api/v1/things',
    });

    expect(render(rateLimited).textContent).toContain('30');
  });
});
