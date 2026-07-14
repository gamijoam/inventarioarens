/**
 * Tipos compartidos para las respuestas del backend Laravel.
 * El backend envuelve SIEMPRE las respuestas en { data: ... } o
 * { data: [...], meta: {...} } para paginacion.
 */

/** Error HTTP retornado por el backend (401, 403, 404, 422, 500). */
export interface ApiErrorBody {
  message: string;
  errors?: Record<string, string[]>;
}

/** Metadata de paginacion que retorna el backend. */
export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

/** Links de paginacion (HATEOAS opcional del backend). */
export interface PaginationLinks {
  first?: string;
  last?: string;
  prev?: string;
  next?: string;
}

/** Respuesta paginada del backend. */
export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
  links?: PaginationLinks;
}

/** Respuesta simple del backend (no paginada). */
export interface ApiResponse<T> {
  data: T;
}

/** Parametros base de cualquier query paginada al backend. */
export interface PaginationParams {
  page?: number;
  per_page?: number;
  search?: string;
}

/** Sort helpers. */
export type SortDirection = 'asc' | 'desc';
export interface SortParam {
  sort_by: string;
  sort_dir: SortDirection;
}

/** Errores tipados para el cliente HTTP. */
export class HttpError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body?: ApiErrorBody,
  ) {
    super(message);
    this.name = 'HttpError';
  }
}

export class ValidationError extends HttpError {
  constructor(message: string, public readonly fieldErrors: Record<string, string[]> = {}) {
    super(422, message, { message, errors: fieldErrors });
    this.name = 'ValidationError';
  }

  get errors(): Record<string, string[]> {
    return this.fieldErrors;
  }
}

export class UnauthorizedError extends HttpError {
  constructor(message = 'No autenticado') {
    super(401, message);
    this.name = 'UnauthorizedError';
  }
}

export class ForbiddenError extends HttpError {
  constructor(message = 'Sin permiso') {
    super(403, message);
    this.name = 'ForbiddenError';
  }
}