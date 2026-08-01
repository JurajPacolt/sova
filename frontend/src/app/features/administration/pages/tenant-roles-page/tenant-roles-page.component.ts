import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';
import {
  PermissionScope,
  TenantPermissionDefinition,
  TenantRole,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { TenantRoleAdministrationService } from '../../tenant-role-administration.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

type FormMode = 'create' | 'edit' | null;

const SCOPE_ORDER: readonly PermissionScope[] = ['TENANT', 'PROJECT', 'WORKGROUP'];

interface PermissionGroup {
  readonly scope: PermissionScope;
  readonly permissions: readonly TenantPermissionDefinition[];
}

@Component({
  selector: 'app-tenant-roles-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  templateUrl: './tenant-roles-page.component.html',
  styleUrl: './tenant-roles-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantRolesPageComponent implements OnInit {
  private readonly administration = inject(TenantRoleAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly roles = signal<readonly TenantRole[]>([]);
  protected readonly permissions = signal<readonly TenantPermissionDefinition[]>([]);
  protected readonly loading = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);

  protected readonly permissionGroups = computed<readonly PermissionGroup[]>(() =>
    SCOPE_ORDER.map((scope) => ({
      scope,
      permissions: this.permissions().filter((permission) => permission.scope === scope),
    })).filter((group) => group.permissions.length > 0),
  );

  protected readonly formMode = signal<FormMode>(null);
  protected readonly editingRole = signal<TenantRole | null>(null);
  protected readonly selectedPermissions = signal<ReadonlySet<string>>(new Set());
  protected readonly submitting = signal(false);
  protected readonly formError = signal(false);
  protected readonly roleForm = this.formBuilder.nonNullable.group({
    code: [
      '',
      [Validators.required, Validators.pattern(/^[A-Z][A-Z0-9_]{1,63}$/), Validators.maxLength(64)],
    ],
    name: ['', [Validators.required, Validators.maxLength(160)]],
    description: ['', Validators.maxLength(500)],
  });

  protected readonly archiveTarget = signal<TenantRole | null>(null);
  protected readonly archivingRoleId = signal<string | null>(null);
  protected readonly archiveError = signal(false);

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.loadFailure.set(null);
    this.loading.set(true);
    this.administration
      .list(tenantId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (roleList) => {
          this.roles.set(roleList.roles);
          this.permissions.set(roleList.permissions);
        },
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected openCreate(): void {
    this.formError.set(false);
    this.formMode.set('create');
    this.editingRole.set(null);
    this.selectedPermissions.set(new Set());
    this.roleForm.reset();
    this.roleForm.controls.code.enable();
  }

  protected openEdit(role: TenantRole): void {
    if (!role.is_editable) {
      return;
    }

    this.formError.set(false);
    this.formMode.set('edit');
    this.editingRole.set(role);
    this.selectedPermissions.set(new Set(role.permissions));
    this.roleForm.setValue({
      code: role.code,
      name: role.name,
      description: role.description,
    });
    this.roleForm.controls.code.disable();
  }

  protected cancelForm(): void {
    this.formMode.set(null);
    this.editingRole.set(null);
    this.formError.set(false);
  }

  protected isSelected(code: string): boolean {
    return this.selectedPermissions().has(code);
  }

  protected togglePermission(code: string, checked: boolean): void {
    const next = new Set(this.selectedPermissions());

    if (checked) {
      for (const dependency of this.dependencyClosure(code)) {
        next.add(dependency);
      }
    } else {
      next.delete(code);

      for (const dependent of this.dependentsOf(code)) {
        next.delete(dependent);
      }
    }

    this.selectedPermissions.set(next);
  }

  protected submit(): void {
    const tenantId = this.tenantId();
    const mode = this.formMode();

    if (tenantId === null || mode === null || this.roleForm.invalid || this.submitting()) {
      this.roleForm.markAllAsTouched();
      return;
    }

    const raw = this.roleForm.getRawValue();
    const permissions = Array.from(this.selectedPermissions());
    this.formError.set(false);
    this.submitting.set(true);

    const request$ =
      mode === 'create'
        ? this.administration.create(tenantId, {
            code: raw.code,
            name: raw.name,
            description: raw.description,
            permissions,
          })
        : this.administration.update(tenantId, this.editingRole()?.id ?? '', {
            name: raw.name,
            description: raw.description,
            permissions,
            revision: this.editingRole()?.revision ?? 0,
          });

    request$
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: () => {
          this.cancelForm();
          this.refresh();
        },
        error: () => this.formError.set(true),
      });
  }

  protected selectArchive(role: TenantRole): void {
    this.archiveError.set(false);
    this.archiveTarget.set(role);
  }

  protected cancelArchive(): void {
    this.archiveTarget.set(null);
    this.archiveError.set(false);
  }

  protected confirmArchive(): void {
    const tenantId = this.tenantId();
    const target = this.archiveTarget();

    if (tenantId === null || target === null || this.archivingRoleId() !== null) {
      return;
    }

    this.archiveError.set(false);
    this.archivingRoleId.set(target.id);
    this.administration
      .archive(tenantId, target.id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.archivingRoleId.set(null)),
      )
      .subscribe({
        next: () => {
          this.cancelArchive();
          this.refresh();
        },
        error: () => this.archiveError.set(true),
      });
  }

  private dependencyClosure(code: string, visited = new Set<string>()): Set<string> {
    if (visited.has(code)) {
      return visited;
    }

    visited.add(code);
    const definition = this.permissions().find((permission) => permission.code === code);

    for (const dependency of definition?.dependencies ?? []) {
      this.dependencyClosure(dependency, visited);
    }

    return visited;
  }

  private dependentsOf(code: string): ReadonlySet<string> {
    const result = new Set<string>();

    for (const permission of this.permissions()) {
      if (permission.code === code) {
        continue;
      }

      if (this.dependencyClosure(permission.code).has(code)) {
        result.add(permission.code);
      }
    }

    return result;
  }
}
