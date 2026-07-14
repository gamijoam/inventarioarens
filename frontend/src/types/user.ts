/**
 * Tipos del modelo User, Tenant y Role.
 * Reflejan la respuesta del backend en /api/auth/me y /api/auth/tenants.
 */

export interface Tenant {
  id: number;
  slug: string;
  name: string;
  is_active: boolean;
}

export interface Role {
  id: number;
  name: string;
  slug: string;
  is_protected: boolean;
}

export interface User {
  id: number;
  email: string;
  name: string;
  is_active: boolean;
}

export interface UserSession {
  user: User;
  tenant: Tenant;
  roles: Role[];
  permissions: string[];
  expires_at: string;
  scope_status: 'none' | 'allow' | 'restrict';
  scopes: UserScopes;
}

export interface UserScopes {
  branches: number[];
  warehouses: number[];
  customer_groups: number[];
  vendor_of: number[];
  branches_count: number;
  warehouses_count: number;
  customer_groups_count: number;
  vendor_of_count: number;
}

/** Respuesta de POST /api/auth/tenants (lookup por email). */
export interface TenantLookupResponse {
  data: TenantOption[];
}

export interface TenantOption {
  id: number;
  slug: string;
  name: string;
  is_active: boolean;
}

/** Respuesta de POST /api/auth/login. */
export interface LoginResponse {
  data: {
    token: string;
    expires_at: string;
    user: User;
    tenant: Tenant;
    roles: Role[];
    permissions: string[];
    scope_status: UserSession['scope_status'];
    scopes: UserScopes;
  };
}