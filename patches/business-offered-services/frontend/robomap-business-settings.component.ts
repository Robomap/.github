import { Component, Input, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslateService } from '@ngx-translate/core';
import { environment } from '../../../../environments/environment';

/** Adjust import paths to match the frontend repo layout. */

export interface OfferedService {
  category_id: string;
  service_type: string;
}

export interface CatalogCategory {
  id: string;
  icon?: string;
  services: string[];
}

interface BusinessForm {
  name: string;
  location: string;
  city: string;
  country: string;
  vat: string;
  description: string;
  offered_services: OfferedService[];
}

@Component({
  selector: 'app-robomap-business-settings',
  templateUrl: './robomap-business-settings.component.html',
  styleUrls: ['./robomap-business-settings.component.scss'],
  standalone: false,
})
export class RobomapBusinessSettingsComponent implements OnInit {
  @Input() embedded = false;

  loading = true;
  saving = false;
  isBusinessAccount = false;

  form: BusinessForm = {
    name: '',
    location: '',
    city: '',
    country: '',
    vat: '',
    description: '',
    offered_services: [],
  };

  catalog: CatalogCategory[] = [];
  catalogLoading = false;
  catalogError = '';

  /** category_id -> Set of selected service_type */
  selectedByCategory: Record<string, Set<string>> = {};

  logoUrl: string | null = null;
  uploadingLogo = false;
  logoError = '';
  logoAccept = 'image/jpeg,image/jpg,image/png,image/gif,image/webp';

  private readonly apiUrl = `${environment.apiUrl}/account/business-profile`;
  private readonly logoUploadUrl = `${environment.apiUrl}/account/business-profile/logo`;
  private readonly catalogUrl = `${environment.apiUrl}/on-demand-services/catalog`;

  constructor(
    private breadcrumbService: any,
    private http: HttpClient,
    private snackBar: MatSnackBar,
    private translateService: TranslateService,
    private authService: any,
    private router: Router,
  ) {}

  ngOnInit(): void {
    if (!this.embedded) {
      this.breadcrumbService.resetBreadcrumb();
      this.breadcrumbService.resetBreadcrumbTranslate();
      this.breadcrumbService.addBreadcrumbTranslate(
        'ACCOUNT_USER_PROFILE',
        'title',
        '/account/user-profile',
      );
      this.breadcrumbService.addBreadcrumbTranslate(
        'ACCOUNT_ROBOMAP_BUSINESS',
        'title',
        '/account/robomap-business',
      );
    }
    this.loadCatalog();
    this.loadProfile();
    document.body.scrollTop = 0;
  }

  get selectedCount(): number {
    return Object.values(this.selectedByCategory).reduce((n, set) => n + set.size, 0);
  }

  openBusinessWorkspace(): void {
    this.router.navigate(['/robomap-business']);
  }

  onCancel(): void {
    if (this.embedded) {
      document.body.scrollTop = 0;
      return;
    }
    this.router.navigate(['/dashboard']);
  }

  isSelected(categoryId: string, serviceType: string): boolean {
    return this.selectedByCategory[categoryId]?.has(serviceType) ?? false;
  }

  toggleService(categoryId: string, serviceType: string, checked: boolean): void {
    if (!this.selectedByCategory[categoryId]) {
      this.selectedByCategory[categoryId] = new Set();
    }
    if (checked) {
      this.selectedByCategory[categoryId].add(serviceType);
    } else {
      this.selectedByCategory[categoryId].delete(serviceType);
    }
  }

  selectAllInCategory(category: CatalogCategory): void {
    this.selectedByCategory[category.id] = new Set(category.services);
  }

  clearCategory(categoryId: string): void {
    this.selectedByCategory[categoryId] = new Set();
  }

  categoryLabel(categoryId: string): string {
    const key = `WORKSPACE_ON_DEMAND_SERVICES.categories.${categoryId}`;
    const label = this.translateService.instant(key);
    return label !== key ? label : categoryId;
  }

  onSubmit(): void {
    if (this.saving) {
      return;
    }
    if (!this.form.name.trim()) {
      this.showMessage(this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.name_required'));
      return;
    }
    if (!this.form.location.trim()) {
      this.showMessage(this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.location_required'));
      return;
    }
    if (!this.form.vat.trim()) {
      this.showMessage(this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.vat_required'));
      return;
    }

    this.saving = true;
    const upgrade = !this.isBusinessAccount;
    const body = {
      ...this.form,
      offered_services: this.collectOfferedServices(),
      upgrade_to_business: upgrade,
    };

    this.http.post<any>(this.apiUrl, body, { withCredentials: true }).subscribe({
      next: (res) => {
        this.saving = false;
        this.isBusinessAccount = res.is_business;
        this.applyBusiness(res.business);
        this.authService.setAccountType(res.account_type);
        this.showMessage(
          res.message ||
            this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.save_success'),
        );
        if (upgrade && res.is_business) {
          window.setTimeout(() => window.location.reload(), 600);
        }
      },
      error: (err) => {
        this.saving = false;
        const msg =
          err?.error?.error ||
          this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.save_error');
        this.showMessage(msg);
      },
    });
  }

  loadCatalog(): void {
    this.catalogLoading = true;
    this.catalogError = '';
    this.http.get<any>(this.catalogUrl, { withCredentials: true }).subscribe({
      next: (res) => {
        this.catalog = Array.isArray(res?.categories) ? res.categories : [];
        this.catalogLoading = false;
        this.syncSelectionFromForm();
      },
      error: () => {
        this.catalog = [];
        this.catalogLoading = false;
        this.catalogError = this.translateService.instant(
          'ACCOUNT_ROBOMAP_BUSINESS.services_catalog_error',
        );
      },
    });
  }

  loadProfile(): void {
    this.loading = true;
    this.http.get<any>(this.apiUrl, { withCredentials: true }).subscribe({
      next: (res) => {
        this.isBusinessAccount =
          res.is_business || this.authService.isBusinessAccount();
        this.applyBusiness(res.business);
        if (!this.form.country.trim()) {
          this.form.country = localStorage.getItem('country') || '';
        }
        this.loading = false;
      },
      error: () => {
        this.loading = false;
        this.isBusinessAccount = this.authService.isBusinessAccount();
        this.form.country = localStorage.getItem('country') || '';
        this.showMessage(
          this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.load_error'),
        );
      },
    });
  }

  applyBusiness(business: any): void {
    if (!business) {
      return;
    }
    this.form = {
      name: business.name || '',
      location: business.location || '',
      city: business.city || '',
      country: business.country || this.form.country,
      vat: business.vat || '',
      description: business.description || '',
      offered_services: Array.isArray(business.offered_services)
        ? business.offered_services
        : [],
    };
    this.logoUrl = business.main_logo || null;
    this.syncSelectionFromForm();
  }

  onLogoSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
      return;
    }
    const error = this.validateLogoFile(file);
    if (error) {
      this.logoError = error;
      input.value = '';
      return;
    }
    this.uploadingLogo = true;
    this.logoError = '';
    const data = new FormData();
    data.append('logo', file);
    this.http.post<any>(this.logoUploadUrl, data, { withCredentials: true }).subscribe({
      next: (res) => {
        this.uploadingLogo = false;
        this.logoUrl = res.business.main_logo;
        this.showMessage(res.message);
        input.value = '';
      },
      error: (err) => {
        this.uploadingLogo = false;
        this.logoError =
          err?.error?.error ||
          this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.logo_upload_error');
        input.value = '';
      },
    });
  }

  onRemoveLogo(): void {
    this.http.delete<any>(this.logoUploadUrl, { withCredentials: true }).subscribe({
      next: (res) => {
        this.logoUrl = res.business.main_logo;
        this.showMessage(res.message);
      },
      error: (err) => {
        this.logoError =
          err?.error?.error ||
          this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.logo_remove_error');
      },
    });
  }

  validateLogoFile(file: File): string | null {
    const ok = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!ok.includes(file.type)) {
      return this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.logo_invalid_type');
    }
    if (file.size > 2097152) {
      return this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.logo_too_large', {
        maxSize: 2,
      });
    }
    return null;
  }

  showMessage(message: string): void {
    this.snackBar.open(
      message,
      this.translateService.instant('ACCOUNT_ROBOMAP_BUSINESS.close'),
      { duration: 4000 },
    );
  }

  private collectOfferedServices(): OfferedService[] {
    const offered: OfferedService[] = [];
    for (const [categoryId, types] of Object.entries(this.selectedByCategory)) {
      for (const serviceType of types) {
        offered.push({ category_id: categoryId, service_type: serviceType });
      }
    }
    return offered;
  }

  private syncSelectionFromForm(): void {
    const next: Record<string, Set<string>> = {};
    for (const category of this.catalog) {
      next[category.id] = new Set();
    }
    for (const row of this.form.offered_services || []) {
      const categoryId = row?.category_id;
      const serviceType = row?.service_type;
      if (!categoryId || !serviceType) {
        continue;
      }
      const category = this.catalog.find((c) => c.id === categoryId);
      if (!category || !category.services.includes(serviceType)) {
        continue;
      }
      if (!next[categoryId]) {
        next[categoryId] = new Set();
      }
      next[categoryId].add(serviceType);
    }
    this.selectedByCategory = next;
  }
}
