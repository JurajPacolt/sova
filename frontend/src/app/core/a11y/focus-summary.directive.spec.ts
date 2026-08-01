import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FocusSummaryDirective } from './focus-summary.directive';

@Component({
  selector: 'app-host',
  standalone: true,
  imports: [FocusSummaryDirective],
  template: `
    <input id="field" />
    @if (failed()) {
      <div id="summary" role="alert" appFocusSummary>{{ message() }}</div>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class HostComponent {
  readonly failed = signal(false);
  readonly message = signal('The code is already taken.');
}

describe('FocusSummaryDirective', () => {
  /**
   * A form that refuses to save while the reason sits somewhere above appears to
   * do nothing — for somebody reading with a screen reader, quite literally.
   */
  it('takes focus when the summary appears', () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();

    fixture.componentInstance.failed.set(true);
    fixture.detectChanges();

    expect(document.activeElement?.id).toBe('summary');
  });

  /** Focusable, but never a stop on the way through the form. */
  it('does not join the tab order', () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.failed.set(true);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('#summary')?.getAttribute('tabindex')).toBe('-1');
  });

  /**
   * The same rules forbid chasing focus while somebody types, so an error that
   * merely rewords itself leaves the reader where they are.
   */
  it('does not take focus again when the wording changes', () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.failed.set(true);
    fixture.detectChanges();

    const field = fixture.nativeElement.querySelector('#field') as HTMLInputElement;
    field.focus();

    fixture.componentInstance.message.set('The name is already taken.');
    fixture.detectChanges();

    expect(document.activeElement?.id).toBe('field');
  });
});
