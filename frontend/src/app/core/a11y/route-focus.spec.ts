import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterOutlet } from '@angular/router';
import { provideRouteFocus } from './route-focus';

@Component({
  selector: 'app-first-page',
  standalone: true,
  template: '<h1>Projects</h1>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class FirstPageComponent {}

@Component({
  selector: 'app-second-page',
  standalone: true,
  template: '<h1>Issues</h1>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class SecondPageComponent {}

@Component({
  selector: 'app-headless-page',
  standalone: true,
  template: '<p>No heading here.</p>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class HeadlessPageComponent {}

@Component({
  selector: 'app-host',
  standalone: true,
  imports: [RouterOutlet],
  template: '<main><router-outlet /></main>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class HostComponent {}

describe('provideRouteFocus', () => {
  let fixture: ComponentFixture<HostComponent>;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'projects', component: FirstPageComponent },
          { path: 'issues', component: SecondPageComponent },
          { path: 'plain', component: HeadlessPageComponent },
        ]),
        provideRouteFocus(),
      ],
    });

    router = TestBed.inject(Router);
    fixture = TestBed.createComponent(HostComponent);
    fixture.autoDetectChanges();
  });

  async function navigate(path: string): Promise<void> {
    await router.navigateByUrl(path);
    fixture.detectChanges();
    // Focus is taken once the application settles after the new screen is
    // drawn, so the test waits for that same moment.
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  /**
   * A single-page application changes the screen without changing the document,
   * so a keyboard reader is otherwise left in the navigation they just used.
   */
  it('moves focus to the heading of the screen that was opened', async () => {
    await navigate('/projects');
    await navigate('/issues');

    expect(document.activeElement?.tagName).toBe('H1');
    expect(document.activeElement?.textContent).toBe('Issues');
  });

  /**
   * Nothing has moved on the first navigation, and taking focus during a cold
   * load fights the browser over scroll restoration and any legitimate autofocus.
   */
  it('leaves the first navigation alone', async () => {
    await navigate('/projects');

    // Nothing was focused, so focus is still where the document put it. The
    // tag is what says that: `activeElement` falls back to the body, whose
    // text is the whole page and would match any heading on it.
    expect(document.activeElement?.tagName).toBe('BODY');
  });

  it('falls back to the main landmark when a screen has no heading', async () => {
    await navigate('/projects');
    await navigate('/plain');

    expect(document.activeElement?.tagName).toBe('MAIN');
  });
});
