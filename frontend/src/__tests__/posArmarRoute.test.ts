import { QueryClient } from '@tanstack/react-query';
import { createRouter } from '@tanstack/react-router';
import { describe, expect, it } from 'vitest';

import { routeTree } from '@/routeTree.gen';

describe('ruta tactil para armar pedidos', () => {
  it('se monta directamente bajo el layout autenticado y no dentro de PosTerminal', () => {
    const router = createRouter({
      routeTree,
      context: { queryClient: new QueryClient() },
    });

    const route = Object.values(router.routesById).find(
      (candidate) => candidate.fullPath === '/pos/armar',
    );

    expect(route).toBeDefined();
    expect(route?.parentRoute.id).toBe('/_authed');
  });
});
