import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  model,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { debounceTime, distinctUntilChanged, finalize, Subject, switchMap } from 'rxjs';
import {
  IssueQueryBasicForm,
  IssueQueryCondition,
  IssueQueryError,
  IssueQueryField,
  IssueQueryLimits,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TRANSLATIONS, TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { IssueWorkspaceService } from '../../issue-workspace.service';

interface EditorProblem {
  readonly code: string;
  readonly messageKey: TranslationKey;
  readonly snippet: string;
}

/**
 * The SovaQL text editor.
 *
 * Validation is the server's, not a second grammar reimplemented here: a client
 * copy would drift from the language and would start disagreeing about what is
 * legal. The endpoint exists precisely so the editor can ask without running
 * anything, and it answers with stable codes, exact ranges and a `message_key`
 * the catalogs resolve.
 *
 * Ranges are UTF-8 codepoint offsets, so the offending text is cut out with the
 * spread operator rather than `substring`, which counts UTF-16 units and would
 * slice the wrong characters after an emoji or an astral symbol.
 */
@Component({
  selector: 'app-query-editor',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './query-editor.component.html',
  styleUrl: './query-editor.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QueryEditorComponent implements OnInit {
  readonly query = model<string>('');
  readonly submitted = output<void>();

  private readonly workspace = inject(IssueWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());
  private readonly requests = new Subject<string>();

  protected readonly checking = signal(false);
  protected readonly problems = signal<readonly EditorProblem[]>([]);
  protected readonly valid = signal<boolean | null>(null);

  /**
   * How the same query looks to the control-based editor. It comes from the
   * server together with the verdict, so both modes describe one AST — a client
   * that parsed the text itself would be a second grammar, free to disagree.
   */
  protected readonly basicForm = signal<IssueQueryBasicForm | null>(null);
  protected readonly basicMode = signal(false);

  protected readonly fields = signal<readonly IssueQueryField[]>([]);
  protected readonly limits = signal<IssueQueryLimits | null>(null);
  protected readonly referenceVisible = signal(false);

  ngOnInit(): void {
    this.requests
      .pipe(
        // Typing must not fire a request per keystroke, and repeating the same
        // text is not worth asking about twice.
        debounceTime(400),
        distinctUntilChanged(),
        switchMap((query) => {
          this.checking.set(true);

          return this.workspace
            .validate(this.tenantId() ?? '', query)
            .pipe(finalize(() => this.checking.set(false)));
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.valid.set(response.valid);
          this.problems.set(response.errors.map((error) => this.toProblem(error)));
          this.basicForm.set(response.basic_form);
        },
        // A failed check must not masquerade as a verdict either way.
        error: () => {
          this.valid.set(null);
          this.problems.set([]);
        },
      });

    this.loadMetadata();
  }

  protected changed(value: string): void {
    this.query.set(value);

    if (value.trim() === '') {
      // An empty query is legal — it means "everything I may see" — so there
      // is nothing to check and nothing to report.
      this.valid.set(null);
      this.problems.set([]);
      this.basicForm.set({ representable: true, conditions: [], sort: [] });

      return;
    }

    this.requests.next(value);
  }

  protected submit(): void {
    this.submitted.emit();
  }

  protected toggleMode(): void {
    this.basicMode.update((basic) => !basic);
  }

  /**
   * Removing a condition rebuilds the text from the server's own canonical
   * pieces and lets it validate the result — the editor never reasons about
   * what the remaining query means.
   */
  protected removeCondition(index: number): void {
    const form = this.basicForm();

    if (form === null || !form.representable) {
      return;
    }

    const kept = form.conditions.filter(
      (_: IssueQueryCondition, position: number) => position !== index,
    );
    const filter = kept
      .map((condition: IssueQueryCondition) => this.conditionText(condition))
      .join(' AND ');
    const sort = form.sort
      .map(
        (item) =>
          `${item.field} ${item.direction}` + (item.nulls === null ? '' : ` NULLS ${item.nulls}`),
      )
      .join(', ');
    const text = [filter, sort === '' ? '' : `ORDER BY ${sort}`].filter(Boolean).join(' ');

    this.changed(text);
  }

  protected conditionText(condition: IssueQueryCondition): string {
    if (condition.operator === 'IS EMPTY' || condition.operator === 'IS NOT EMPTY') {
      return `${condition.field} ${condition.operator}`;
    }

    if (condition.operator === 'IN' || condition.operator === 'NOT IN') {
      const values = condition.values.join(', ');

      // A set built from a function keeps its call shape; a list gets brackets.
      return condition.values.length === 1 && values.endsWith(')')
        ? `${condition.field} ${condition.operator} ${values}`
        : `${condition.field} ${condition.operator} (${values})`;
    }

    return `${condition.field} ${condition.operator} ${condition.values.join(', ')}`;
  }

  protected toggleReference(): void {
    this.referenceVisible.update((visible) => !visible);
  }

  private toProblem(error: IssueQueryError): EditorProblem {
    return {
      code: error.code,
      messageKey: this.messageKeyOf(error.message_key),
      snippet: this.snippetOf(error.start, error.end),
    };
  }

  /**
   * The key arrives as a plain string from the server, so it is checked against
   * the catalog before use — an unknown key would otherwise render as itself.
   */
  private messageKeyOf(candidate: string): TranslationKey {
    return candidate in TRANSLATIONS.en
      ? (candidate as TranslationKey)
      : 'query.errors.syntaxInvalid';
  }

  private snippetOf(start: number, end: number): string {
    const codepoints = [...this.query()];

    return codepoints.slice(start, Math.max(end, start + 1)).join('');
  }

  private loadMetadata(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.workspace
      .queryMetadata(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (metadata) => {
          // Fields whose storage has not landed are simply absent from the
          // response, so the reference never advertises something unusable.
          this.fields.set(metadata.fields);
          this.limits.set(metadata.limits);
        },
        error: () => this.fields.set([]),
      });
  }
}
