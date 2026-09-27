import { describe, it, expect, vi, beforeEach } from 'vitest';
import { WsHubClient } from '../wsHub';

describe('WsHubClient', () => {
  let client: WsHubClient;

  beforeEach(() => {
    client = new WsHubClient('ws://127.0.0.1:16666/ws');
  });

  it('manages event handlers and dispatches events correctly', () => {
    const handler = vi.fn();
    const unsubscribe = client.on('rate.updated', handler);

    // Simulate incoming message
    client.handleRawMessage(JSON.stringify({
      channel: 'tenant:1',
      event: 'rate.updated',
      data: { rate: 45.85 },
    }));

    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler).toHaveBeenCalledWith({ rate: 45.85 }, 'rate.updated');

    // Unsubscribe
    unsubscribe();
    client.handleRawMessage(JSON.stringify({
      channel: 'tenant:1',
      event: 'rate.updated',
      data: { rate: 46.00 },
    }));

    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('ignores invalid JSON or events without handlers', () => {
    expect(() => {
      client.handleRawMessage('invalid json {');
      client.handleRawMessage(JSON.stringify({ unknown: true }));
    }).not.toThrow();
  });
});
