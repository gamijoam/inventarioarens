import { Sparkles, RotateCcw, CheckSquare, Layers } from 'lucide-react';
import { toast } from 'sonner';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Switch } from '@/components/ui/Switch';
import { Checkbox } from '@/components/ui/Checkbox';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import {
  useUiModeStore,
  AVAILABLE_SIMPLE_MODE_ITEMS,
  DEFAULT_VISIBLE_ROUTES,
  type SimpleModeItemConfig,
} from '@/stores/uiMode';
import { useUpdateUiPreferences } from './api';

export function SimpleModeSettingsPanel() {
  const isSimpleMode = useUiModeStore((s) => s.isSimpleMode);
  const visibleRoutes = useUiModeStore((s) => s.visibleRoutes);
  const toggleSimpleMode = useUiModeStore((s) => s.toggleSimpleMode);
  const toggleRoute = useUiModeStore((s) => s.toggleRoute);
  const toggleModuleGroup = useUiModeStore((s) => s.toggleModuleGroup);
  const setVisibleRoutes = useUiModeStore((s) => s.setVisibleRoutes);
  const resetToDefaults = useUiModeStore((s) => s.resetToDefaults);
  const updateUiPreferences = useUpdateUiPreferences();

  const handleToggleMode = () => {
    const nextMode = !isSimpleMode;
    toggleSimpleMode();
    updateUiPreferences.mutate({
      simple_mode: {
        is_simple_mode: nextMode,
        visible_routes: visibleRoutes,
      },
    });
    toast.success(
      nextMode ? 'Modo Fácil activado' : 'Modo Completo activado (todas las opciones visibles)',
    );
  };

  const handleToggleRoute = (route: string, label: string) => {
    toggleRoute(route);
    const willBeVisible = !visibleRoutes.includes(route);
    const nextRoutes = willBeVisible
      ? [...visibleRoutes, route]
      : visibleRoutes.filter((r) => r !== route);

    updateUiPreferences.mutate({
      simple_mode: {
        is_simple_mode: isSimpleMode,
        visible_routes: nextRoutes,
      },
    });
    toast.info(`${label}: ${willBeVisible ? 'visible en menú' : 'oculto del menú'}`);
  };

  const handleToggleParentGroup = (item: SimpleModeItemConfig) => {
    const isAnyActive =
      visibleRoutes.includes(item.to) ||
      Boolean(item.children?.some((c) => visibleRoutes.includes(c.to)));

    toggleModuleGroup(item.to, !isAnyActive);
    const updatedRoutes = useUiModeStore.getState().visibleRoutes;
    updateUiPreferences.mutate({
      simple_mode: {
        is_simple_mode: isSimpleMode,
        visible_routes: updatedRoutes,
      },
    });
    toast.info(
      !isAnyActive
        ? `Módulo ${item.label} y sus submódulos activados`
        : `Módulo ${item.label} desactivado`,
    );
  };

  const handleSelectAll = () => {
    const allRoutes: string[] = [];
    AVAILABLE_SIMPLE_MODE_ITEMS.forEach((item) => {
      if (!allRoutes.includes(item.to)) allRoutes.push(item.to);
      if (item.children) {
        item.children.forEach((c) => {
          if (!allRoutes.includes(c.to)) allRoutes.push(c.to);
        });
      }
    });
    setVisibleRoutes(allRoutes);
    updateUiPreferences.mutate({
      simple_mode: {
        is_simple_mode: isSimpleMode,
        visible_routes: allRoutes,
      },
    });
    toast.success('Todos los módulos y submódulos seleccionados');
  };

  const handleResetDefaults = () => {
    resetToDefaults();
    updateUiPreferences.mutate({
      simple_mode: {
        is_simple_mode: true,
        visible_routes: DEFAULT_VISIBLE_ROUTES,
      },
    });
    toast.success('Configuración recomendada para repuestos restablecida');
  };

  const sections = Array.from(new Set(AVAILABLE_SIMPLE_MODE_ITEMS.map((i) => i.section)));

  return (
    <div className="space-y-6">
      {/* Tarjeta de Control Principal */}
      <Card className="border-border shadow-sm">
        <CardHeader className="pb-4">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="p-2.5 rounded-lg bg-primary/10 text-primary">
                <Sparkles className="size-6" />
              </div>
              <div>
                <CardTitle className="text-lg font-bold flex items-center gap-2">
                  Modo Fácil (Navegación Simplificada)
                  <Badge variant={isSimpleMode ? 'success' : 'outline'}>
                    {isSimpleMode ? 'Activo' : 'Inactivo (Modo Completo)'}
                  </Badge>
                </CardTitle>
                <CardDescription className="text-sm text-text-muted mt-0.5">
                  Optimiza la interfaz para operaciones rápidas ocultando herramientas que tu negocio no utiliza.
                </CardDescription>
              </div>
            </div>

            <div className="flex items-center gap-3 bg-surface p-2 rounded-lg border border-border">
              <span className="text-sm font-medium text-text-primary">
                {isSimpleMode ? 'Modo Fácil activado' : 'Modo Fácil desactivado'}
              </span>
              <Switch
                aria-label="Activar modo fácil"
                checked={isSimpleMode}
                onCheckedChange={handleToggleMode}
              />
            </div>
          </div>
        </CardHeader>
      </Card>

      {/* Tarjeta de Selección de Módulos */}
      <Card className="border-border shadow-sm">
        <CardHeader className="pb-3 border-b border-border">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
              <CardTitle className="text-base font-semibold flex items-center gap-2">
                <Layers className="size-4 text-primary" />
                Módulos y submódulos visibles en el menú
              </CardTitle>
              <CardDescription className="text-xs text-text-muted mt-1">
                Marca únicamente las pantallas y sub-opciones que el personal necesita ver en el día a día.
                Por ejemplo, puedes mantener visible Inventario con sus Tipos de tasa y Catálogos, pero ocultar opciones avanzadas.
              </CardDescription>
            </div>

            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={handleSelectAll}
                className="text-xs h-8"
              >
                <CheckSquare className="size-3.5 mr-1.5" />
                Marcar todos
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={handleResetDefaults}
                className="text-xs h-8 text-primary"
              >
                <RotateCcw className="size-3.5 mr-1.5" />
                Restablecer recomendados
              </Button>
            </div>
          </div>
        </CardHeader>

        <CardContent className="p-4 sm:p-6 space-y-6">
          {sections.map((sectionName) => {
            const sectionItems = AVAILABLE_SIMPLE_MODE_ITEMS.filter(
              (i) => i.section === sectionName,
            );

            return (
              <div key={sectionName} className="space-y-3">
                <h4 className="text-xs font-bold uppercase tracking-wider text-text-muted px-1 flex items-center gap-2">
                  <span className="w-2 h-2 rounded-full bg-primary/60" />
                  {sectionName}
                </h4>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  {sectionItems.map((item) => {
                    const isParentChecked = visibleRoutes.includes(item.to);
                    const isLocked = item.to === '/settings/company';
                    const hasChildren = Boolean(item.children && item.children.length > 0);

                    const activeChildrenCount = item.children
                      ? item.children.filter((c) => visibleRoutes.includes(c.to)).length
                      : 0;

                    return (
                      <div
                        key={item.to}
                        className={`rounded-lg border transition-all ${
                          hasChildren ? 'md:col-span-2 p-3.5' : 'p-3'
                        } ${
                          isParentChecked || activeChildrenCount > 0
                            ? 'bg-primary/[0.03] border-primary/30'
                            : 'bg-surface border-border opacity-70 hover:opacity-100'
                        }`}
                      >
                        {/* Cabecera del Módulo */}
                        <label
                          htmlFor={`module-${item.to}`}
                          className={`flex items-start gap-3 cursor-pointer ${
                            isLocked ? 'cursor-not-allowed' : ''
                          }`}
                        >
                          <Checkbox
                            id={`module-${item.to}`}
                            aria-label={item.label}
                            checked={isParentChecked || activeChildrenCount > 0}
                            disabled={isLocked}
                            onCheckedChange={() => {
                              if (isLocked) return;
                              if (hasChildren) {
                                handleToggleParentGroup(item);
                              } else {
                                handleToggleRoute(item.to, item.label);
                              }
                            }}
                            className="mt-0.5"
                          />
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className="text-sm font-semibold text-text-primary">
                                {item.label}
                              </span>
                              {item.defaultVisible && (
                                <span className="text-[10px] px-1.5 py-0.2 rounded bg-bg text-text-muted font-medium border border-border">
                                  Básico
                                </span>
                              )}
                              {hasChildren && (
                                <Badge variant="outline" className="text-[10px] py-0 px-1.5">
                                  {activeChildrenCount} de {item.children?.length} submódulos activos
                                </Badge>
                              )}
                              {isLocked && (
                                <span className="text-[10px] px-1.5 py-0.2 rounded bg-amber-50 text-amber-700 font-medium border border-amber-200">
                                  Siempre activo
                                </span>
                              )}
                            </div>
                            <p className="text-xs text-text-muted mt-0.5 leading-snug">
                              {item.description}
                            </p>
                          </div>
                        </label>

                        {/* Submódulos Anidados */}
                        {hasChildren && item.children && (
                          <div className="mt-3.5 ml-6 pl-3 border-l-2 border-primary/20 space-y-2">
                            <div className="text-[11px] font-semibold text-text-secondary uppercase tracking-wider mb-2">
                              Submódulos de {item.label}:
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                              {item.children.map((sub) => {
                                const isSubChecked = visibleRoutes.includes(sub.to);
                                const isSubLocked =
                                  sub.to === '/settings/company' || sub.to === '/settings/simple-mode';

                                return (
                                  <label
                                    key={sub.to + sub.label}
                                    htmlFor={`submodule-${sub.to}-${sub.label}`}
                                    className={`flex items-start gap-2.5 p-2 rounded-md border text-xs transition-colors cursor-pointer ${
                                      isSubChecked
                                        ? 'bg-surface border-primary/40 text-text-primary shadow-xs'
                                        : 'bg-bg/40 border-border/70 text-text-muted hover:border-border'
                                    } ${isSubLocked ? 'cursor-not-allowed' : ''}`}
                                  >
                                    <Checkbox
                                      id={`submodule-${sub.to}-${sub.label}`}
                                      aria-label={sub.label}
                                      checked={isSubChecked}
                                      disabled={isSubLocked}
                                      onCheckedChange={() => {
                                        if (isSubLocked) return;
                                        handleToggleRoute(
                                          sub.to,
                                          `${item.label} > ${sub.label}`,
                                        );
                                      }}
                                      className="mt-0.5 size-3.5"
                                    />
                                    <div className="min-w-0 flex-1">
                                      <div className="flex items-center gap-1.5">
                                        <span
                                          className={`font-medium truncate ${
                                            isSubChecked ? 'text-text-primary' : 'text-text-muted'
                                          }`}
                                        >
                                          {sub.label}
                                        </span>
                                        {sub.defaultVisible && (
                                          <span className="text-[9px] px-1 rounded bg-primary/10 text-primary font-medium">
                                            Recomendado
                                          </span>
                                        )}
                                      </div>
                                      <p className="text-[10px] text-text-muted line-clamp-1 mt-0.5">
                                        {sub.description}
                                      </p>
                                    </div>
                                  </label>
                                );
                              })}
                            </div>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </CardContent>
      </Card>
    </div>
  );
}
