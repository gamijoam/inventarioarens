/**
 * BulkActionsMenu: DropdownMenu con acciones masivas sobre los productos
 * seleccionados. Solo se renderiza si hay al menos 1 producto seleccionado.
 */
import { useState } from 'react';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/DropdownMenu';
import { Button } from '@/components/ui/Button';
import { ChevronDown, CheckSquare, X } from 'lucide-react';

import { ActionDialog } from './ActionDialogs';
import { useBulkOperation } from './useBulkAction';
import { BULK_ACTIONS, type BulkAction } from '@/features/inventory-center/schemas';

export interface BulkActionsMenuProps {
  selectedIds: number[];
  allMatching: boolean;
  allVisibleSelected: boolean;
  totalMatching: number;
  visibleCount: number;
  filters: Record<string, unknown>;
  onClearSelection: () => void;
  onSelectAllMatching: () => void;
  onUseVisibleSelection: () => void;
  onSuccess?: () => void;
}

const ACTION_LABELS: Record<BulkAction, string> = {
  activate: 'Activar',
  deactivate: 'Desactivar',
  assign_warranty_policy: 'Asignar garantia...',
  assign_exchange_rate_type: 'Asignar tipo de tasa...',
  assign_fiscal_tax_rate: 'Asignar tratamiento fiscal...',
  fill_missing_price_list: 'Rellenar lista de precio...',
  update_price_list: 'Actualizar lista de precio...',
};

export function BulkActionsMenu({
  selectedIds,
  allMatching,
  allVisibleSelected,
  totalMatching,
  visibleCount,
  filters,
  onClearSelection,
  onSelectAllMatching,
  onUseVisibleSelection,
  onSuccess,
}: BulkActionsMenuProps) {
  const [activeAction, setActiveAction] = useState<string | null>(null);
  const [operationId, setOperationId] = useState<number | null>(null);
  const { data: operation } = useBulkOperation(operationId);

  if (selectedIds.length === 0) return null;

  return (
    <>
      <div className="border-border bg-bg flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
        <CheckSquare className="text-primary size-4" aria-hidden="true" />
        <span className="font-medium">
          {selectedIds.length} seleccionado{selectedIds.length === 1 ? '' : 's'}
        </span>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onClearSelection}
          aria-label="Limpiar selección"
        >
          <X className="size-4" aria-hidden="true" />
        </Button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="outline" data-testid="bulk-actions-trigger">
              Acciones
              <ChevronDown className="size-3.5" aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>Acciones masivas</DropdownMenuLabel>
            <DropdownMenuSeparator />
            {BULK_ACTIONS.map((action) => (
              <DropdownMenuItem
                key={action}
                onSelect={() => setActiveAction(action)}
                data-testid={`bulk-action-${action}`}
              >
                {ACTION_LABELS[action]}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {activeAction && (
        <ActionDialog
          open
          onOpenChange={(open) => {
            if (!open) setActiveAction(null);
          }}
          action={activeAction as BulkAction}
          productIds={allMatching ? [] : selectedIds}
          allMatching={allMatching}
          filters={filters}
          onSuccess={(result) => {
            setActiveAction(null);
            if (result.status && result.id) {
              setOperationId(result.id);
            } else {
              onSuccess?.();
            }
          }}
        />
      )}

      {operation && (
        <div className="border-border bg-surface mt-2 flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
          <span>
            {operation.status === 'completed'
              ? `Clasificación fiscal terminada: ${operation.updated_count ?? 0} actualizados, ${operation.skipped_count ?? 0} conservados.`
              : operation.status === 'failed'
                ? 'La clasificación fiscal masiva no pudo completarse.'
                : `Clasificando productos: ${operation.progress_percent ?? 0}% (${operation.processed_count ?? 0} de ${operation.requested_count ?? 0}).`}
          </span>
          <Button variant="ghost" size="sm" onClick={() => setOperationId(null)}>
            Ocultar
          </Button>
        </div>
      )}

      {allVisibleSelected && totalMatching > visibleCount && (
        <div className="border-primary/30 bg-primary/5 mt-2 flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
          <span>
            {allMatching
              ? `Se seleccionaron los ${totalMatching.toLocaleString('es-VE')} productos filtrados.`
              : `Se seleccionaron los ${visibleCount} productos de esta página.`}
          </span>
          <Button
            variant="ghost"
            size="sm"
            onClick={allMatching ? onUseVisibleSelection : onSelectAllMatching}
          >
            {allMatching ? 'Usar solo esta página' : `Seleccionar los ${totalMatching} resultados`}
          </Button>
        </div>
      )}
    </>
  );
}
