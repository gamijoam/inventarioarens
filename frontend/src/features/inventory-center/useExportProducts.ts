/**
 * Hook useExportProducts: descarga el CSV de productos segun los filtros activos.
 * Usa la API /api/inventory-center/export.
 */
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';

import { useSessionStore } from '@/stores/session';
import type { InventoryFilters } from './schemas';

export function useExportProducts() {
  const [isExporting, setIsExporting] = useState(false);
  const qc = useQueryClient();

  const exportCsv = async (filters: InventoryFilters) => {
    setIsExporting(true);
    try {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value == null || value === '' || value === 'all') continue;
        params.set(key, String(value));
      }
      const query = params.toString();
      const url = `/api/inventory-center/export${query ? `?${query}` : ''}`;
      const { token, tenant } = useSessionStore.getState();
      const res = await fetch(url, {
        headers: {
          Accept: 'text/csv',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(tenant?.slug ? { 'X-Tenant': tenant.slug } : {}),
        },
      });
      if (!res.ok) throw new Error(`Error ${res.status} al exportar`);
      const blob = await res.blob();
      const filename = `productos_${new Date().toISOString().slice(0, 10)}.csv`;
      const downloadUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = downloadUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(downloadUrl);
      toast.success(`Exportado como ${filename}`);
      // Invalida cualquier cache relacionado (no es necesario pero lo dejo).
      void qc.invalidateQueries();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al exportar');
    } finally {
      setIsExporting(false);
    }
  };

  return { exportCsv, isExporting };
}
