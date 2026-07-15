/**
 * PermissionTree: arbol jerarquico navegable de permisos del backend.
 *
 * Se renderiza con modulos expandibles (Tree), cada modulo contiene las
 * acciones (verb) que se pueden check/uncheck.
 *
 * Props:
 *   - selected: Set de permisos (strings tipo 'sales.create') ya asignados.
 *   - onToggle(permission, checked): callback cuando un permiso cambia.
 *   - disabled: si true, no permite cambios (modo lectura).
 *
 * Opcional: filtro de busqueda que oculta modulos sin acciones
 * coincidentes.
 */
import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, Search } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { cn } from '@/lib/cn';

import type { PermissionModule } from './api';

interface PermissionTreeProps {
  modules: PermissionModule[];
  selected: Set<string>;
  onToggle: (permission: string, checked: boolean) => void;
  disabled?: boolean;
  initialSearch?: string;
}

export function PermissionTree({
  modules,
  selected,
  onToggle,
  disabled = false,
  initialSearch = '',
}: PermissionTreeProps) {
  const [search, setSearch] = useState(initialSearch);
  // Por defecto todos los modulos estan colapsados para evitar un
  // arbol gigante cuando hay 33 modulos con varios permisos cada uno.
  const [collapsed, setCollapsed] = useState<Set<string>>(
    () => new Set(modules.map((m) => m.module)),
  );

  const filteredModules = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return modules;
    return modules
      .map((m) => {
        const matchingActions = m.actions.filter(
          (a) =>
            a.permission.toLowerCase().includes(term) ||
            a.label.toLowerCase().includes(term) ||
            a.verb.toLowerCase().includes(term),
        );
        if (matchingActions.length === 0) return null;
        return { ...m, actions: matchingActions } satisfies PermissionModule;
      })
      .filter((m): m is PermissionModule => m !== null);
  }, [modules, search]);

  // Expandir todos los modulos cuando hay un search activo.
  useEffect(() => {
    if (search.trim()) {
      setCollapsed(new Set());
    }
  }, [search]);

  function toggleModule(moduleName: string) {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(moduleName)) next.delete(moduleName);
      else next.add(moduleName);
      return next;
    });
  }

  function expandAll() {
    setCollapsed(new Set());
  }
  function collapseAll() {
    setCollapsed(new Set(modules.map((m) => m.module)));
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar permiso o modulo..."
            className="pl-8"
            data-testid="permission-tree-search"
          />
        </div>
        <button
          type="button"
          onClick={expandAll}
          className="text-xs text-text-muted hover:text-text-primary"
          data-testid="permission-tree-expand-all"
        >
          Expandir
        </button>
        <button
          type="button"
          onClick={collapseAll}
          className="text-xs text-text-muted hover:text-text-primary"
          data-testid="permission-tree-collapse-all"
        >
          Colapsar
        </button>
      </div>

      <div className="max-h-[420px] overflow-y-auto rounded border border-border bg-bg/30 p-2">
        {filteredModules.length === 0 ? (
          <p className="px-2 py-4 text-center text-sm text-text-muted">
            Sin permisos que coincidan.
          </p>
        ) : (
          <ul className="space-y-0.5">
            {filteredModules.map((m) => {
              const isCollapsed = collapsed.has(m.module);
              const modulePermissions = m.actions.map((a) => a.permission);
              const selectedCount = modulePermissions.filter((p) => selected.has(p)).length;
              const allSelected = selectedCount === modulePermissions.length;
              return (
                <li key={m.module}>
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => toggleModule(m.module)}
                      className="flex flex-1 items-center gap-1 rounded px-1 py-1.5 text-left text-sm font-medium hover:bg-bg/60"
                      data-testid={`permission-tree-module-${m.module}`}
                    >
                      {isCollapsed ? (
                        <ChevronRight className="size-3.5 shrink-0 text-text-muted" aria-hidden="true" />
                      ) : (
                        <ChevronDown className="size-3.5 shrink-0 text-text-muted" aria-hidden="true" />
                      )}
                      <span className="flex-1">{m.label}</span>
                      <Badge variant="info" className="text-[10px]">
                        {selectedCount}/{m.actions.length}
                      </Badge>
                    </button>
                    {!disabled && (
                      <button
                        type="button"
                        onClick={() => {
                          m.actions.forEach((a) => onToggle(a.permission, !allSelected));
                        }}
                        className="rounded px-2 py-1 text-xs text-text-muted hover:bg-bg/60 hover:text-text-primary"
                        title={allSelected ? 'Deseleccionar todos' : 'Seleccionar todos'}
                        data-testid={`permission-tree-toggle-all-${m.module}`}
                      >
                        {allSelected ? 'Quitar todos' : 'Todos'}
                      </button>
                    )}
                  </div>
                  {!isCollapsed && (
                    <ul className={cn('ml-4 mt-0.5 space-y-0.5 border-l border-border pl-2')}>
                      {m.actions.map((a) => {
                        const checked = selected.has(a.permission);
                        return (
                          <li key={a.permission}>
                            <label
                              className={cn(
                                'flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-bg/60',
                                disabled && 'opacity-60',
                              )}
                            >
                              <input
                                type="checkbox"
                                checked={checked}
                                disabled={disabled}
                                onChange={(e) => onToggle(a.permission, e.target.checked)}
                                className="rounded"
                                data-testid={`permission-tree-permission-${a.permission}`}
                              />
                              <span>{a.label}</span>
                              {a.danger === 'high' && (
                                <Badge variant="warning" className="ml-auto text-[10px]">
                                  Peligroso
                                </Badge>
                              )}
                              <code className="ml-auto rounded bg-bg px-1.5 py-0.5 font-mono text-[10px] text-text-muted">
                                {a.permission}
                              </code>
                            </label>
                          </li>
                        );
                      })}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}