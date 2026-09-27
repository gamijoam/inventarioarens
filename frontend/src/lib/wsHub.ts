/**
 * Cliente WebSocket de alto rendimiento para WsHub (Go).
 * Provee conexiones persistentes, reconexión automática y despacho de eventos en tiempo real.
 */

export interface WsEventMessage {
  channel: string;
  event: string;
  data?: any;
}

export type EventHandler = (data: any, event: string) => void;

export class WsHubClient {
  private ws: WebSocket | null = null;
  private url: string;
  private channels: Set<string> = new Set();
  private handlers: Map<string, Set<EventHandler>> = new Map();
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private shouldReconnect = true;
  private isConnecting = false;

  constructor(url?: string) {
    if (url) {
      this.url = url;
    } else {
      const host = typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1';
      const protocol = typeof window !== 'undefined' && window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      this.url = `${protocol}//${host}:16666/ws`;
    }
  }

  public connect(): void {
    if (typeof window === 'undefined' || this.ws || this.isConnecting) {
      return;
    }

    try {
      this.isConnecting = true;
      const chParam = Array.from(this.channels).join(',');
      const connectUrl = chParam ? `${this.url}?channels=${encodeURIComponent(chParam)}` : this.url;

      this.ws = new WebSocket(connectUrl);

      this.ws.onopen = () => {
        this.isConnecting = false;
        // Re-subscribe to all tracked channels
        for (const ch of this.channels) {
          this.sendAction('subscribe', ch);
        }
      };

      this.ws.onmessage = (event) => {
        if (typeof event.data === 'string') {
          this.handleRawMessage(event.data);
        }
      };

      this.ws.onclose = () => {
        this.ws = null;
        this.isConnecting = false;
        if (this.shouldReconnect) {
          this.scheduleReconnect();
        }
      };

      this.ws.onerror = () => {
        if (this.ws) {
          this.ws.close();
        }
      };
    } catch {
      this.isConnecting = false;
      this.scheduleReconnect();
    }
  }

  public disconnect(): void {
    this.shouldReconnect = false;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
  }

  public subscribe(channel: string): void {
    this.channels.add(channel);
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.sendAction('subscribe', channel);
    } else if (!this.ws && !this.isConnecting) {
      this.connect();
    }
  }

  public unsubscribe(channel: string): void {
    this.channels.delete(channel);
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.sendAction('unsubscribe', channel);
    }
  }

  public on(event: string, handler: EventHandler): () => void {
    if (!this.handlers.has(event)) {
      this.handlers.set(event, new Set());
    }
    this.handlers.get(event)!.add(handler);

    return () => {
      this.handlers.get(event)?.delete(handler);
    };
  }

  public handleRawMessage(raw: string): void {
    try {
      const parsed: WsEventMessage = JSON.parse(raw);
      if (!parsed.event) {
        return;
      }

      const eventHandlers = this.handlers.get(parsed.event);
      if (eventHandlers) {
        for (const h of eventHandlers) {
          try {
            h(parsed.data, parsed.event);
          } catch (err) {
            console.error(`[WsHub] Error in handler for ${parsed.event}:`, err);
          }
        }
      }
    } catch {
      // Ignore invalid JSON frames
    }
  }

  private sendAction(action: 'subscribe' | 'unsubscribe', channel: string): void {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify({ action, channel }));
    }
  }

  private scheduleReconnect(): void {
    if (this.reconnectTimer) {
      return;
    }
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      if (this.shouldReconnect) {
        this.connect();
      }
    }, 3000);
  }
}

let defaultClient: WsHubClient | null = null;

export function getWsHubClient(): WsHubClient {
  if (!defaultClient) {
    defaultClient = new WsHubClient();
  }
  return defaultClient;
}
