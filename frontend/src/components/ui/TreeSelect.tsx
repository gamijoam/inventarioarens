/**
 * TreeSelect multi-select para categorias jerarquicas.
 * Muestra el arbol jerarquico (con indentacion por nivel) y permite
 * seleccionar varios nodos. Soporta expandir/colapsar cada nodo.
 *
 * UX:
 *   - Click en el icono de flecha expande/colapsa el nodo.
 *   - Click en el checkbox selecciona/deselecciona el nodo.
 *   - El nombre del nodo esta indentado segun su nivel.
 *   - Al final del nombre se muestra el breadcrumb (full_path) si difiere.
 */
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Checkbox } from './Checkbox';

export interface TreeSelectNode {
  id: string | number;
  label: string;
  children?: TreeSelectNode[];
}

export interface TreeSelectProps {
  nodes: TreeSelectNode[];
  value: TreeSelectNode['id'][];
  onChange: (next: TreeSelectNode['id'][]) => void;
  renderExtra?: (node: TreeSelectNode) => ReactNode;
  disabled?: boolean;
  className?: string;
  emptyMessage?: string;
}

export function TreeSelect({
  nodes,
  value,
  onChange,
  renderExtra,
  disabled = false,
  className,
  emptyMessage = 'Sin opciones',
}: TreeSelectProps) {
  const [expanded, setExpanded] = useState<Set<string | number>>(() => {
    // Por default, todos los nodos raiz y primer nivel estan expandidos.
    const initial = new Set<string | number>();
    const visit = (ns: TreeSelectNode[], depth: number) => {
      for (const n of ns) {
        if (depth < 1) initial.add(n.id);
        if (n.children) visit(n.children, depth + 1);
      }
    };
    visit(nodes, 0);
    return initial;
  });

  const toggleExpand = (id: string | number) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleSelect = (id: string | number) => {
    if (disabled) return;
    if (value.includes(id)) {
      onChange(value.filter((v) => v !== id));
    } else {
      onChange([...value, id]);
    }
  };

  const renderNodes = (ns: TreeSelectNode[], level: number) => {
    return ns.map((node) => {
      const hasChildren = (node.children?.length ?? 0) > 0;
      const isExpanded = expanded.has(node.id);
      const isSelected = value.includes(node.id);

      return (
        <div key={node.id}>
          <div
            className={cn(
              'group flex items-center gap-1.5 rounded px-1.5 py-1 text-sm transition-colors',
              !disabled && 'hover:bg-bg cursor-pointer',
              isSelected && 'bg-primary/5',
            )}
            style={{ paddingLeft: `${level * 16 + 6}px` }}
            onClick={(e) => {
              // Evitar toggle si el click fue en el checkbox o en el toggle de expand.
              if ((e.target as HTMLElement).closest('[data-tree-toggle], [data-tree-expand]')) return;
              toggleSelect(node.id);
            }}
          >
            {hasChildren ? (
              <button
                type="button"
                data-tree-expand
                onClick={(e) => {
                  e.stopPropagation();
                  toggleExpand(node.id);
                }}
                className="rounded p-0.5 text-text-muted hover:bg-bg hover:text-text-primary"
                aria-label={isExpanded ? 'Colapsar' : 'Expandir'}
              >
                {isExpanded ? (
                  <ChevronDown className="size-3.5" aria-hidden="true" />
                ) : (
                  <ChevronRight className="size-3.5" aria-hidden="true" />
                )}
              </button>
            ) : (
              <span className="w-4" />
            )}

            <div data-tree-toggle onClick={(e) => e.stopPropagation()}>
              <Checkbox
                checked={isSelected}
                onCheckedChange={() => toggleSelect(node.id)}
                disabled={disabled}
                aria-label={`Seleccionar ${node.label}`}
              />
            </div>

            <span className="flex-1 truncate">{node.label}</span>

            {renderExtra?.(node)}
          </div>

          {hasChildren && isExpanded && (
            <div>{renderNodes(node.children!, level + 1)}</div>
          )}
        </div>
      );
    });
  };

  const hasAnyNode = useMemo(() => {
    const visit = (ns: TreeSelectNode[]): boolean => ns.length > 0;
    return visit(nodes);
  }, [nodes]);

  return (
    <div
      className={cn(
        'rounded border border-border-strong bg-surface p-2',
        disabled && 'opacity-50',
        className,
      )}
    >
      {!hasAnyNode ? (
        <p className="px-3 py-2 text-sm text-text-muted">{emptyMessage}</p>
      ) : (
        renderNodes(nodes, 0)
      )}
    </div>
  );
}