import { toast } from '@/lib/toast';

type TransferEvent =
  | 'created'
  | 'accepted'
  | 'rejected'
  | 'cancelled'
  | 'prepared'
  | 'dispatched'
  | 'delivered'
  | 'received';

interface TransferEventPayload extends Record<string, unknown> {
  inventory_transfer_request_id?: number | string;
  event_type?: string;
  tenant_id?: number | string;
  origin_tenant_id?: number | string;
  destination_tenant_id?: number | string;
  origin_tenant_name?: string | null;
  destination_tenant_name?: string | null;
  document_number?: string | null;
  flow_type?: 'stock_request' | 'shipment_offer' | string;
  sender_tenant_id?: number | string;
  receiver_tenant_id?: number | string;
  sender_tenant_name?: string | null;
  receiver_tenant_name?: string | null;
  response_notes?: string | null;
}

interface TransferToastContent {
  requestId: string;
  eventType: TransferEvent;
  toastId: string;
  title: string;
  description: string;
  kind: 'info' | 'success' | 'warning';
}

const EVENT_TYPES = new Set<TransferEvent>([
  'created',
  'accepted',
  'rejected',
  'cancelled',
  'prepared',
  'dispatched',
  'delivered',
  'received',
]);
const recentTransferEvents = new Map<string, number>();
const localTransferEvents = new Map<string, number>();
const EVENT_DEDUPE_MS = 60_000;
const LOCAL_ECHO_DEDUPE_MS = 120_000;

function cleanName(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.trim() ? value.trim() : fallback;
}

function sameTenant(first: unknown, second: number | string | null | undefined): boolean {
  return first != null && second != null && String(first) === String(second);
}

function cacheKey(requestId: string, eventType: TransferEvent, tenantId: number | string): string {
  return `${requestId}:${eventType}:${tenantId}`;
}

function toastIdFor(key: string): string {
  return `intercompany-transfer:${key}`;
}

function hasUnexpired(map: Map<string, number>, key: string, now = Date.now()): boolean {
  const expiry = map.get(key);
  if (!expiry) return false;
  if (expiry <= now) {
    map.delete(key);
    return false;
  }
  return true;
}

function remember(map: Map<string, number>, key: string, duration: number): void {
  const now = Date.now();
  for (const [existingKey, expiry] of map) {
    if (expiry <= now) map.delete(existingKey);
  }
  map.set(key, now + duration);
}

export function buildIntercompanyTransferToast(
  payload: TransferEventPayload,
  eventName: string,
  currentTenantId: number | string | null | undefined,
): TransferToastContent | null {
  const requestId = payload.inventory_transfer_request_id;
  const originId = payload.origin_tenant_id;
  const destinationId = payload.destination_tenant_id;
  if (requestId == null || currentTenantId == null || originId == null || destinationId == null) return null;

  const isOrigin = sameTenant(currentTenantId, originId);
  const isDestination = sameTenant(currentTenantId, destinationId);
  if (!isOrigin && !isDestination) return null;

  const rawEvent = payload.event_type || eventName.split('.').at(-1);
  if (!rawEvent || !EVENT_TYPES.has(rawEvent as TransferEvent)) return null;
  const eventType = rawEvent as TransferEvent;
  const document = cleanName(payload.document_number, `solicitud #${requestId}`);
  const originName = cleanName(payload.origin_tenant_name, isOrigin ? 'tu empresa' : 'la otra empresa');
  const destinationName = cleanName(payload.destination_tenant_name, isDestination ? 'tu empresa' : 'la otra empresa');
  const isShipmentOffer = payload.flow_type === 'shipment_offer';
  const itemName = isShipmentOffer ? 'propuesta de envío' : 'solicitud de stock';
  const senderIsOrigin = payload.sender_tenant_id != null
    ? sameTenant(payload.sender_tenant_id, originId)
    : isShipmentOffer;
  const currentIsSender = payload.sender_tenant_id != null
    ? sameTenant(currentTenantId, payload.sender_tenant_id)
    : senderIsOrigin ? isOrigin : isDestination;
  const senderName = cleanName(payload.sender_tenant_name, senderIsOrigin ? originName : destinationName);
  const receiverName = cleanName(payload.receiver_tenant_name, senderIsOrigin ? destinationName : originName);
  let title = '';
  let description = '';
  let kind: TransferToastContent['kind'] = 'info';

  switch (eventType) {
    case 'created':
      title = isOrigin
        ? (isShipmentOffer ? 'Propuesta de envío enviada' : 'Solicitud de stock enviada')
        : (isShipmentOffer ? 'Nueva propuesta de envío' : 'Nueva solicitud de stock');
      description = isOrigin
        ? `Enviaste una ${itemName} a ${destinationName} (${document}).`
        : `${originName} ${isShipmentOffer ? 'propone enviarte mercancía' : 'solicita mercancía'} (${document}).`;
      break;
    case 'accepted':
      title = isShipmentOffer ? 'Propuesta de envío aceptada' : 'Solicitud de stock aceptada';
      description = isDestination
        ? `Aceptaste la ${itemName} de ${originName} (${document}).`
        : `${destinationName} aceptó tu ${itemName} (${document}).`;
      kind = 'success';
      break;
    case 'rejected':
      title = isShipmentOffer ? 'Propuesta de envío rechazada' : 'Solicitud de stock rechazada';
      description = isDestination
        ? `Rechazaste la ${itemName} de ${originName} (${document}).`
        : `${destinationName} rechazó tu ${itemName} (${document}).`;
      kind = 'warning';
      break;
    case 'cancelled':
      title = 'Solicitud interempresa cancelada';
      description = isOrigin
        ? `Cancelaste la solicitud enviada a ${destinationName} (${document}).`
        : `${originName} canceló la solicitud interempresa (${document}).`;
      kind = 'warning';
      break;
    case 'prepared':
      title = 'Envío preparado';
      description = currentIsSender
        ? `Preparaste la mercancía para ${receiverName} (${document}).`
        : `${senderName} preparó la mercancía para tu empresa (${document}).`;
      break;
    case 'dispatched':
      title = 'Mercancía despachada';
      description = currentIsSender
        ? `Despachaste mercancía para ${receiverName} (${document}).`
        : `${senderName} despachó mercancía para tu empresa (${document}).`;
      break;
    case 'delivered':
      title = 'Mercancía entregada';
      description = currentIsSender
        ? `La mercancía enviada a ${receiverName} fue entregada (${document}).`
        : `${senderName} entregó la mercancía (${document}). Confirma la recepción.`;
      break;
    case 'received':
      title = 'Mercancía recibida';
      description = currentIsSender
        ? `${receiverName} confirmó la recepción de la mercancía (${document}).`
        : `Confirmaste la recepción de mercancía de ${senderName} (${document}).`;
      kind = 'success';
      break;
  }

  const responseNotes = payload.response_notes;
  if (
    (eventType === 'rejected' || eventType === 'cancelled') &&
    typeof responseNotes === 'string' &&
    responseNotes.trim()
  ) {
    description += `\nMotivo: ${responseNotes}`;
  }

  const key = cacheKey(String(requestId), eventType, currentTenantId);
  return {
    requestId: String(requestId),
    eventType,
    toastId: toastIdFor(key),
    title,
    description,
    kind,
  };
}

export function rememberLocalTransferAction(
  requestId: number | string | null | undefined,
  eventType: string,
  tenantId: number | string | null | undefined,
): void {
  if (requestId == null || tenantId == null || !EVENT_TYPES.has(eventType as TransferEvent)) return;

  const key = cacheKey(String(requestId), eventType as TransferEvent, tenantId);
  remember(localTransferEvents, key, LOCAL_ECHO_DEDUPE_MS);
  remember(recentTransferEvents, key, EVENT_DEDUPE_MS);
  toast.dismiss(toastIdFor(key));
}

export function showIntercompanyTransferToast(
  payload: TransferEventPayload,
  eventName: string,
  tenantId: number | string | null | undefined,
  onOpenRequest?: (requestId: string) => void,
): void {
  const content = buildIntercompanyTransferToast(payload, eventName, tenantId);
  if (!content || tenantId == null) return;

  const key = cacheKey(content.requestId, content.eventType, tenantId);
  if (hasUnexpired(localTransferEvents, key) || hasUnexpired(recentTransferEvents, key)) return;
  remember(recentTransferEvents, key, EVENT_DEDUPE_MS);

  const commonOptions = {
    id: content.toastId,
    description: content.description,
    duration: 8_000,
    action: onOpenRequest
      ? {
          label: 'Ver',
          onClick: () => onOpenRequest(content.requestId),
        }
      : undefined,
  };
  if (content.kind === 'success') toast.success(content.title, commonOptions);
  else if (content.kind === 'warning') toast.warning(content.title, commonOptions);
  else toast.info(content.title, commonOptions);
}
