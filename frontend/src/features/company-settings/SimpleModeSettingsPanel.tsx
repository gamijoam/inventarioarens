import { Sparkles, RotateCcw, CheckSquare, Layers } from 'lucide-react';
import { toast } from 'sonner';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Switch } from '@/components/ui/Switch';
import { Checkbox } from '@/components/ui/Checkbox';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { useUiModeStore, AVAILABLE_SIMPLE_MODE_ITEMS } from '@/stores/uiMode';

export function SimpleModeSettingsPanel() {
  const isSimpleMode = useUiModeStore((s) => s.isSimpleMode);
  const visibleRoutes = useUiModeStore((s) => s.visibleRoutes);
  const toggleSimpleMode = useUiModeStore((s) => s.toggleSimpleMode);
  const toggleRoute = useUiModeStore((s) => s.toggleRoute);
  const setVisibleRoutes = useUiModeStore((s) => s.setVisibleRoutes);
  const resetToDefaults = useUiModeStore((s) => s.resetToDefaults);

  const handleToggleMode = () => {
    toggleSimpleMode();
    toast.success(
      !isSimpleMode ? 'Modo Fácil activado' : 'Modo Completo activado (todas las opciones visibles)',
    );
  };

  const handleToggleRoute = (route: string, label: string) => {
    toggleRoute(route);
    const willBeVisible = !visibleRoutes.includes(route);
    toast.info(`${label}: ${willBeVisible ? 'visible en menú' : 'oculto del menú'}`);
  };

  const handleSelectAll = () => {
    setVisibleRoutes(AVAILABLE_SIMPLE_MODE_ITEMS.map((i) => i.to));
    toast.success('Todos los módulos seleccionados');
  };

  const handleResetDefaults = () => {
    resetToDefaults();
    toast.success('Configuración recomendada para repuestos restablecida');
  };

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
                Módulos visibles en el menú principal
              </CardTitle>
              <CardDescription className="text-xs text-text-muted mt-1">
                Marca únicamente las pantallas que el personal necesita ver en el día a día.
                Configuración siempre permanece accesible.
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

        <CardContent className="p-4 sm:p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {AVAILABLE_SIMPLE_MODE_ITEMS.map((item) => {
              const isChecked = visibleRoutes.includes(item.to);
              const isLocked = item.to === '/settings/company';

              return (
                <label
                  key={item.to}
                  className={`flex items-start gap-3 p-3 rounded-lg border transition-all cursor-pointer ${
                    isChecked
                      ? 'bg-primary/5 border-primary/30 text-text-primary'
                      : 'bg-surface border-border opacity-70 hover:opacity-100'
                  } ${isLocked ? 'cursor-not-allowed' : ''}`}
                >
                  <Checkbox
                    id={`module-${item.to}`}
                    aria-label={item.label}
                    checked={isChecked}
                    disabled={isLocked}
                    onCheckedChange={() => !isLocked && handleToggleRoute(item.to, item.label)}
                    className="mt-0.5"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold truncate">{item.label}</span>
                      {item.defaultVisible && (
                        <span className="text-[10px] px-1.5 py-0.2 rounded bg-bg text-text-muted font-medium border border-border">
                          Básico
                        </span>
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
              );
            })}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
