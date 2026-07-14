/**
 * BulkActionsMenu: DropdownMenu con 5 acciones masivas sobre los productos
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
import { BULK_ACTIONS, type BulkAction } from '@/features/inventory-center/schemas';

export interface BulkActionsMenuProps {
  selectedIds: number[];
  onClearSelection: () => void;
  onSuccess?: () => void;
}

const ACTION_LABELS: Record<BulkAction, string> = {
  activate: 'Activar',
  deactivate: 'Desactivar',
  assign_warranty_policy: 'Asignar garantia...',
  assign_exchange_rate_type: 'Asignar tipo de tasa...',
  fill_missing_price_list: 'Rellenar lista de precio...',
  update_price_list: 'Actualizar lista de precio...',
};

export function BulkActionsMenu({ selectedIds, onClearSelection, onSuccess }: BulkActionsMenuProps) {
  const [activeAction, setActiveAction] = useState<string | null>(null);

  if (selectedIds.length === 0) return null;

  return (
    <>
      <div className="flex items-center gap-2 rounded-md border border-border bg-bg px-3 py-2 text-sm">
        <CheckSquare className="size-4 text-primary" aria-hidden="true" />
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
          productIds={selectedIds}
          onSuccess={() => {
            setActiveAction(null);
            onSuccess?.();
          }}
        />
      )}
    </>
  );
}
