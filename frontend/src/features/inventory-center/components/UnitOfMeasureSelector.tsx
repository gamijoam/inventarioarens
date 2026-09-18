import { useState } from 'react';
import { Plus, Check, Sparkles } from 'lucide-react';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useSessionStore } from '@/stores/session';
import {
  getAllUnits,
  saveCustomUnit,
  type UnitOfMeasureOption,
} from '../unitsOfMeasure';

interface UnitOfMeasureSelectorProps {
  value?: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}

export function UnitOfMeasureSelector({
  value = 'unit',
  onChange,
  disabled = false,
}: UnitOfMeasureSelectorProps) {
  const tenant = useSessionStore((s) => s.tenant);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [newUnitName, setNewUnitName] = useState('');
  const [options, setOptions] = useState<UnitOfMeasureOption[]>(() =>
    getAllUnits(tenant?.id, value),
  );

  const handleSelectChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const selected = e.target.value;
    if (selected === '__CUSTOM_NEW__') {
      setNewUnitName('');
      setDialogOpen(true);
      return;
    }
    onChange(selected);
  };

  const handleCreateCustomUnit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUnitName.trim()) return;

    const created = saveCustomUnit(newUnitName, tenant?.id);
    const updatedOptions = getAllUnits(tenant?.id, created.value);
    setOptions(updatedOptions);
    onChange(created.value);
    setDialogOpen(false);
    setNewUnitName('');
  };

  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-2">
        <Select
          value={value}
          onChange={handleSelectChange}
          disabled={disabled}
          className="flex-1 font-medium"
        >
          <optgroup label="Unidades Disponibles">
            {options.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </optgroup>
          <optgroup label="Opciones">
            <option value="__CUSTOM_NEW__">➕ Personalizar / Agregar otra unidad...</option>
          </optgroup>
        </Select>

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            setNewUnitName('');
            setDialogOpen(true);
          }}
          title="Agregar nueva unidad de medida personalizada (ej. PAR)"
          className="shrink-0 text-xs gap-1 border-dashed text-primary hover:bg-primary/5"
        >
          <Plus className="size-3.5" />
          <span>Nueva</span>
        </Button>
      </div>

      {/* Modal para crear unidad personalizada */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Sparkles className="size-4 text-primary" />
              <span>Nueva Unidad de Medida</span>
            </DialogTitle>
            <DialogDescription>
              Agrega un tipo o unidad personalizada para este negocio (ej. <strong>PAR</strong>, <strong>DOCENA</strong>, <strong>BULTO</strong>, <strong>ROLLO</strong>).
              Se guardará de forma permanente en tu catálogo.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleCreateCustomUnit} className="space-y-4 pt-2">
            <div className="space-y-2">
              <Label htmlFor="new-unit-name">Nombre de la unidad</Label>
              <Input
                id="new-unit-name"
                placeholder="Ej. Par, Bulto, Docena..."
                value={newUnitName}
                onChange={(e) => setNewUnitName(e.target.value)}
                maxLength={20}
                autoFocus
                required
              />
              <p className="text-[11px] text-text-muted">
                Máximo 20 caracteres. Se mostrará en facturas, inventario y punto de venta.
              </p>
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
              <Button
                type="button"
                variant="outline"
                onClick={() => setDialogOpen(false)}
              >
                Cancelar
              </Button>
              <Button
                type="submit"
                disabled={!newUnitName.trim()}
                className="gap-1.5"
              >
                <Check className="size-4" />
                <span>Guardar y Usar</span>
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
