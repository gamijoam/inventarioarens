import { toast as sonnerToast, type ExternalToast } from 'sonner';

const MESSAGE = {
  session: 'Tu sesión expiró. Vuelve a iniciar sesión.',
  permission: 'No tienes permiso para esta acción.',
  notFound: 'Recurso no encontrado.',
  validation: 'Los datos ingresados no son válidos.',
  server: 'Error del servidor. Por favor intenta de nuevo.',
  network: 'Error de red. Verifica tu conexión.',
  timeout: 'La solicitud tardó demasiado. Intenta de nuevo.',
  tooMany: 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
  request: 'Error en la solicitud.',
} as const;

function translateKnownError(message: string): string | null {
  const value = message.trim();
  const normalized = value.toLocaleLowerCase('en-US').replace(/[.!?]+$/, '');

  if (
    ['unauthenticated', 'unauthorized', 'session expired', 'your session has expired'].includes(normalized)
  ) return MESSAGE.session;
  if (
    ['this action is unauthorized', 'forbidden', 'permission denied', 'access denied'].includes(normalized)
  ) return MESSAGE.permission;
  if (
    ['not found', 'resource not found', 'the requested resource was not found'].includes(normalized)
  ) return MESSAGE.notFound;
  if (
    ['the given data was invalid', 'validation failed', 'invalid data'].includes(normalized)
  ) return MESSAGE.validation;
  if (
    ['server error', 'internal server error', 'service unavailable', 'bad gateway'].includes(normalized)
  ) return MESSAGE.server;
  if (
    ['network error', 'failed to fetch', 'load failed', 'network request failed', 'err_network'].includes(normalized)
  ) return MESSAGE.network;
  if (/^(?:timeout|request timed out|timed out|timeout of .+ exceeded)$/i.test(value)) return MESSAGE.timeout;
  if (['too many attempts', 'too many requests', 'rate limit exceeded'].includes(normalized)) return MESSAGE.tooMany;

  const statusMatch = value.match(/(?:request failed with status code|http)\s*(\d{3})/i);
  if (statusMatch) return fallbackForStatus(Number(statusMatch[1]));

  if (/^no query results for model\b/i.test(value) || /\b(?:not found|could not be found)\b/i.test(value)) {
    return MESSAGE.notFound;
  }
  if (/\b(?:unauthorized|not authorized|forbidden|permission denied|access denied)\b/i.test(value)) {
    return MESSAGE.permission;
  }
  if (/\b(?:network error|failed to fetch|network request failed|err_network)\b/i.test(value)) {
    return MESSAGE.network;
  }
  if (/\b(?:timed out|timeout of .+ exceeded|request timeout)\b/i.test(value)) return MESSAGE.timeout;
  return null;
}

function fallbackForStatus(status: number): string {
  if (status === 401) return MESSAGE.session;
  if (status === 403) return MESSAGE.permission;
  if (status === 404) return MESSAGE.notFound;
  if (status === 422) return MESSAGE.validation;
  if (status === 429) return MESSAGE.tooMany;
  if (status >= 500) return MESSAGE.server;
  if (status === 0) return MESSAGE.network;
  return MESSAGE.request;
}

const englishErrorIndicators =
  /\b(?:the|this action|you are|you cannot|could not|failed|failure|invalid|unauthorized|forbidden|not found|not allowed|denied|server|unavailable|please try|try again|does not exist|no query results)\b/i;

export function localizeApiErrorMessage(message: string | undefined, status: number): string {
  const value = message?.trim();
  if (!value) return fallbackForStatus(status);

  const knownTranslation = translateKnownError(value);
  if (knownTranslation) return knownTranslation;

  // Se conserva cualquier mensaje ya escrito en español. Si el servidor envía
  // una frase genérica en inglés, mostramos el mensaje localizado del estado.
  if (status === 401) return MESSAGE.session;
  if (status === 0) return MESSAGE.network;
  if (status === 422) return value;
  if (englishErrorIndicators.test(value) && status >= 400) return fallbackForStatus(status);
  return value;
}

function hashMessage(message: string): string {
  let hash = 2166136261;
  for (let index = 0; index < message.length; index += 1) {
    hash ^= message.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(36);
}

const errorToast: typeof sonnerToast.error = (message, data?: ExternalToast) => {
  if (typeof message !== 'string') return sonnerToast.error(message, data);

  const localized = translateKnownError(message) ?? message;
  return sonnerToast.error(localized, {
    ...data,
    id: data?.id ?? `sdi-error-${hashMessage(localized)}`,
  });
};

export const toast = Object.assign(
  (message: Parameters<typeof sonnerToast>[0], data?: ExternalToast) => sonnerToast(message, data),
  sonnerToast,
  { error: errorToast },
) as typeof sonnerToast;
