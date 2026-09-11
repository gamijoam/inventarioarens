import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useTenantFavicon } from '../useTenantFavicon';

describe('useTenantFavicon', () => {
  let initialLink: HTMLLinkElement;

  beforeEach(() => {
    document.head.innerHTML = '';
    initialLink = document.createElement('link');
    initialLink.rel = 'icon';
    initialLink.href = '/favicon.svg';
    document.head.appendChild(initialLink);
  });

  afterEach(() => {
    document.head.innerHTML = '';
  });

  it('actualiza el favicon cuando se pasa un logoUrl valido', () => {
    const { rerender } = renderHook(({ url }) => useTenantFavicon(url), {
      initialProps: { url: '/storage/tenants/1/logo.png' as string | null },
    });

    const link = document.querySelector<HTMLLinkElement>("link[rel~='icon']");
    expect(link?.href).toContain('/storage/tenants/1/logo.png');

    // Cambiar a null debe restaurar el default
    rerender({ url: null });
    expect(link?.href).toContain('/favicon.svg');
  });

  it('restaura el favicon previo al desmontar', () => {
    const { unmount } = renderHook(() => useTenantFavicon('/storage/tenants/1/logo_custom.png'));

    const link = document.querySelector<HTMLLinkElement>("link[rel~='icon']");
    expect(link?.href).toContain('/storage/tenants/1/logo_custom.png');

    unmount();
    expect(link?.href).toContain('/favicon.svg');
  });
});
