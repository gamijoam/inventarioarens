import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { useUsers } from '@/features/users/api';
import { usePrepareTransferRequest, useReceiveTransferRequest } from '../api';
import type { GuideItemQuantity, TransferRequest } from '../schemas';

type Mode = 'prepare' | 'receive';

export function TransferRequestGuideDialog({
  request,
  mode,
  open,
  onOpenChange,
}: {
  request: TransferRequest;
  mode: Mode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const prepare = usePrepareTransferRequest();
  const receive = useReceiveTransferRequest();
  const { data: usersResponse } = useUsers({
    status: 'active',
    scope: 'tenant',
    page: 1,
    per_page: 100,
  });
  const carrierUsers = (usersResponse?.data ?? []).filter((user) =>
    user.roles.some((role) => role.name === 'Transportista'),
  );
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [serials, setSerials] = useState<Record<number, string>>({});
  const [transportMode, setTransportMode] = useState<'simple' | 'controlled'>('simple');
  const [showTransportDetails, setShowTransportDetails] = useState(false);
  const [carrier, setCarrier] = useState({
    userId: '',
    name: '',
    document: '',
    phone: '',
    plate: '',
    company: '',
  });

  useEffect(() => {
    if (!open) return;
    const next: Record<number, number> = {};
    for (const item of request.items ?? []) {
      const guideItem = request.guide?.items?.find(
        (candidate) => candidate.inventory_transfer_request_item_id === item.id,
      );
      next[item.id] =
        mode === 'prepare'
          ? Number(guideItem?.prepared_quantity ?? item.quantity)
          : Number(guideItem?.prepared_quantity ?? item.quantity);
    }
    setQuantities(next);
    setReasons({});
    setSerials({});
    setTransportMode(request.guide?.transport_mode ?? 'simple');
    setShowTransportDetails(Boolean(request.guide?.carrier_name));
    setCarrier({
      userId: request.guide?.carrier_user_id ? String(request.guide.carrier_user_id) : '',
      name: request.guide?.carrier_name ?? '',
      document: request.guide?.carrier_document_number ?? '',
      phone: request.guide?.carrier_phone ?? '',
      plate: request.guide?.vehicle_plate ?? '',
      company: request.guide?.carrier_company ?? '',
    });
  }, [mode, open, request]);

  if (!open) return null;

  const isPrepare = mode === 'prepare';
  const isShipmentOffer = request.flow_type === 'shipment_offer';
  const mutation = isPrepare ? prepare : receive;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const items: GuideItemQuantity[] = [];
    for (const item of request.items ?? []) {
      const quantity = quantities[item.id] ?? 0;
      const guideItem = request.guide?.items?.find(
        (candidate) => candidate.inventory_transfer_request_item_id === item.id,
      );
      const expectedQuantity = isPrepare
        ? Number(item.quantity)
        : Number(guideItem?.prepared_quantity ?? item.quantity);
      if (quantity < expectedQuantity && !reasons[item.id]?.trim()) {
        toast.error(
          `Indica el motivo de la diferencia para ${item.origin_product?.name ?? `item #${item.id}`}.`,
        );
        return;
      }
      const serialValues =
        serials[item.id]
          ?.split(',')
          .map((value) => value.trim())
          .filter(Boolean) ?? [];
      if (new Set(serialValues).size !== serialValues.length) {
        toast.error(
          `No puedes repetir un IMEI o serial para ${item.origin_product?.name ?? `item #${item.id}`}.`,
        );
        return;
      }
      if (item.origin_product?.tracking_type === 'serialized' && serialValues.length !== quantity) {
        toast.error(
          `Indica ${quantity} IMEI(s)/serial(es) para ${item.origin_product?.name ?? `item #${item.id}`}.`,
        );
        return;
      }
      items.push({
        request_item_id: item.id,
        ...(isPrepare ? { prepared_quantity: quantity } : { received_quantity: quantity }),
        difference_reason: reasons[item.id]?.trim() || undefined,
        ...(isPrepare
          ? {
              prepared_serial_units: serialValues.map((serial_number) => ({
                serial_type: 'imei' as const,
                serial_number,
              })),
            }
          : {
              received_serial_units: serialValues.map((serial_number) => ({
                serial_type: 'imei' as const,
                serial_number,
              })),
            }),
      });
    }

    try {
      await mutation.mutateAsync({
        id: request.id,
        items,
        ...(isPrepare
          ? {
              transport_mode: transportMode,
              carrier_name: carrier.name.trim() || undefined,
              carrier_document_number: carrier.document.trim() || undefined,
              carrier_phone: carrier.phone.trim() || undefined,
              vehicle_plate: carrier.plate.trim() || undefined,
              carrier_company: carrier.company.trim() || undefined,
              ...(carrier.userId ? { carrier_user_id: Number(carrier.userId) } : {}),
            }
          : {}),
      });
      toast.success(isPrepare ? 'Guía preparada.' : 'Recepción registrada y stock aplicado.');
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo actualizar la guía.');
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
    >
      <div className="bg-surface border-border max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border p-5">
        <h2 className="text-lg font-semibold">
          {isPrepare ? 'Preparar envío' : 'Verificar y recibir mercancía'}
        </h2>
        <p className="text-text-muted mt-1 text-sm">
          {isPrepare
            ? isShipmentOffer
              ? 'Confirma la mercancía que ofreciste y selecciona los IMEIs o seriales que saldrán.'
              : 'Confirma la mercancía solicitada que enviarás y registra cualquier diferencia.'
            : 'Compara lo recibido con la guía despachada. El stock ingresará al confirmar la recepción.'}
        </p>
        <form onSubmit={submit} className="mt-5 space-y-4">
          {isPrepare && (
            <div className="border-border bg-bg/30 rounded border p-3">
              <div className="mb-3">
                <div className="text-sm font-medium">Modalidad de envío</div>
                <p className="text-text-muted mt-1 text-xs">
                  El envío simple conserva la verificación de mercancía y deja los datos del
                  transportista como opcionales.
                </p>
                <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <button
                    type="button"
                    onClick={() => {
                      setTransportMode('simple');
                      setShowTransportDetails(false);
                    }}
                    className={`rounded border px-3 py-2 text-left text-sm ${transportMode === 'simple' ? 'border-primary bg-primary/10' : 'border-border-strong bg-surface'}`}
                  >
                    <span className="block font-medium">Envío simple</span>
                    <span className="text-text-muted mt-1 block text-xs">
                      Verifica cantidades, seriales y recepción.
                    </span>
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setTransportMode('controlled');
                      setShowTransportDetails(true);
                    }}
                    className={`rounded border px-3 py-2 text-left text-sm ${transportMode === 'controlled' ? 'border-primary bg-primary/10' : 'border-border-strong bg-surface'}`}
                  >
                    <span className="block font-medium">Envío controlado</span>
                    <span className="text-text-muted mt-1 block text-xs">
                      Registra transportista, vehículo y empresa.
                    </span>
                  </button>
                </div>
              </div>
              {transportMode === 'simple' && !showTransportDetails && (
                <button
                  type="button"
                  className="text-primary text-sm font-medium"
                  onClick={() => setShowTransportDetails(true)}
                >
                  Agregar datos logísticos opcionales
                </button>
              )}
              {(transportMode === 'controlled' || showTransportDetails) && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="carrier-user">Usuario transportista</Label>
                    <select
                      id="carrier-user"
                      value={carrier.userId}
                      onChange={(e) => setCarrier({ ...carrier, userId: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                      required={transportMode === 'controlled' && carrierUsers.length > 0}
                    >
                      <option value="">
                        {carrierUsers.length > 0
                          ? 'Selecciona un usuario con rol Transportista...'
                          : 'No hay usuarios con rol Transportista'}
                      </option>
                      {carrierUsers.map((user) => (
                        <option key={user.id} value={user.id}>
                          {user.name} ({user.email})
                        </option>
                      ))}
                    </select>
                    {carrierUsers.length === 0 && (
                      <p className="text-warning mt-1 text-xs">
                        Puedes registrar el nombre manualmente o asignar el rol Transportista desde
                        Acceso &gt; Usuarios.
                      </p>
                    )}
                  </div>
                  <div>
                    <Label htmlFor="carrier-name">Transportista</Label>
                    <input
                      id="carrier-name"
                      value={carrier.name}
                      onChange={(e) => setCarrier({ ...carrier, name: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                      required={transportMode === 'controlled'}
                    />
                  </div>
                  <div>
                    <Label htmlFor="carrier-company">Empresa transportista</Label>
                    <input
                      id="carrier-company"
                      value={carrier.company}
                      onChange={(e) => setCarrier({ ...carrier, company: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                    />
                  </div>
                  <div>
                    <Label htmlFor="carrier-document">Documento</Label>
                    <input
                      id="carrier-document"
                      value={carrier.document}
                      onChange={(e) => setCarrier({ ...carrier, document: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                    />
                  </div>
                  <div>
                    <Label htmlFor="carrier-phone">Teléfono</Label>
                    <input
                      id="carrier-phone"
                      value={carrier.phone}
                      onChange={(e) => setCarrier({ ...carrier, phone: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                    />
                  </div>
                  <div>
                    <Label htmlFor="carrier-plate">Placa</Label>
                    <input
                      id="carrier-plate"
                      value={carrier.plate}
                      onChange={(e) => setCarrier({ ...carrier, plate: e.target.value })}
                      className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                    />
                  </div>
                </div>
              )}
              {transportMode === 'simple' && showTransportDetails && (
                <button
                  type="button"
                  className="text-primary mt-3 text-sm font-medium"
                  onClick={() => setShowTransportDetails(false)}
                >
                  Ocultar datos logísticos opcionales
                </button>
              )}
            </div>
          )}
          {(request.items ?? []).map((item) => {
            const guideItem = request.guide?.items?.find(
              (candidate) => candidate.inventory_transfer_request_item_id === item.id,
            );
            const expected = isPrepare
              ? Number(item.quantity)
              : Number(guideItem?.prepared_quantity ?? item.quantity);
            const quantity = quantities[item.id] ?? 0;
            const expectedLabel = isPrepare
              ? isShipmentOffer
                ? 'Ofrecido'
                : 'Solicitado'
              : 'Despachado';
            return (
              <div key={item.id} className="border-border rounded border p-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <div className="font-medium">
                      {item.origin_product?.name ?? `Producto #${item.origin_product_id}`}
                    </div>
                    <div className="text-text-muted text-xs">
                      {expectedLabel}: {expected}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Label htmlFor={`guide-qty-${item.id}`} className="text-xs">
                      Cantidad
                    </Label>
                    <input
                      id={`guide-qty-${item.id}`}
                      type="number"
                      min={0}
                      max={expected}
                      step="0.0001"
                      value={quantity}
                      onChange={(event) =>
                        setQuantities((current) => ({
                          ...current,
                          [item.id]: Number(event.target.value),
                        }))
                      }
                      className="border-border-strong bg-surface w-24 rounded border px-2 py-1 text-sm"
                    />
                  </div>
                </div>
                {quantity < expected && (
                  <input
                    value={reasons[item.id] ?? ''}
                    onChange={(event) =>
                      setReasons((current) => ({ ...current, [item.id]: event.target.value }))
                    }
                    placeholder="Motivo de la diferencia"
                    maxLength={255}
                    required
                    className="border-border-strong bg-surface mt-3 w-full rounded border px-3 py-2 text-sm"
                  />
                )}
                {item.origin_product?.tracking_type === 'serialized' && (
                  <input
                    value={serials[item.id] ?? ''}
                    onChange={(event) =>
                      setSerials((current) => ({ ...current, [item.id]: event.target.value }))
                    }
                    placeholder={
                      isPrepare
                        ? 'IMEIs/seriales que saldrán, separados por coma'
                        : 'Escanea o escribe los IMEIs/seriales recibidos, separados por coma'
                    }
                    className="border-border-strong bg-surface mt-3 w-full rounded border px-3 py-2 text-sm"
                  />
                )}
                {item.origin_product?.tracking_type === 'serialized' && (
                  <p className="text-text-muted mt-1 text-xs">
                    {isPrepare
                      ? 'Estos seriales quedarán registrados en la guía y saldrán del almacén al despachar.'
                      : 'Debes verificar exactamente los seriales despachados por la empresa remitente.'}
                  </p>
                )}
              </div>
            );
          })}
          <div className="border-border flex justify-end gap-2 border-t pt-3">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              {isPrepare ? 'Confirmar preparación' : 'Confirmar recepción'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
