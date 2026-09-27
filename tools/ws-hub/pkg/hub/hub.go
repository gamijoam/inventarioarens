package hub

import (
	"sync"
)

type Client interface {
	ID() string
	Send(msg []byte)
}

type Hub struct {
	mu          sync.RWMutex
	clients     map[string]Client
	// channelName -> set of client IDs
	channels    map[string]map[string]struct{}
	// client ID -> set of channel names
	clientChans map[string]map[string]struct{}
}

func NewHub() *Hub {
	return &Hub{
		clients:     make(map[string]Client),
		channels:    make(map[string]map[string]struct{}),
		clientChans: make(map[string]map[string]struct{}),
	}
}

func (h *Hub) Register(c Client) {
	h.mu.Lock()
	defer h.mu.Unlock()

	id := c.ID()
	h.clients[id] = c
	h.clientChans[id] = make(map[string]struct{})
}

func (h *Hub) Unregister(c Client) {
	h.mu.Lock()
	defer h.mu.Unlock()

	id := c.ID()
	// Remove from all subscribed channels
	if chans, ok := h.clientChans[id]; ok {
		for chName := range chans {
			if subscribers, exists := h.channels[chName]; exists {
				delete(subscribers, id)
				if len(subscribers) == 0 {
					delete(h.channels, chName)
				}
			}
		}
		delete(h.clientChans, id)
	}

	delete(h.clients, id)
}

func (h *Hub) Subscribe(c Client, channel string) {
	h.mu.Lock()
	defer h.mu.Unlock()

	id := c.ID()
	if _, ok := h.clients[id]; !ok {
		return
	}

	if _, exists := h.channels[channel]; !exists {
		h.channels[channel] = make(map[string]struct{})
	}
	h.channels[channel][id] = struct{}{}

	if h.clientChans[id] == nil {
		h.clientChans[id] = make(map[string]struct{})
	}
	h.clientChans[id][channel] = struct{}{}
}

func (h *Hub) Unsubscribe(c Client, channel string) {
	h.mu.Lock()
	defer h.mu.Unlock()

	id := c.ID()
	if subscribers, exists := h.channels[channel]; exists {
		delete(subscribers, id)
		if len(subscribers) == 0 {
			delete(h.channels, channel)
		}
	}

	if chans, ok := h.clientChans[id]; ok {
		delete(chans, channel)
	}
}

func (h *Hub) Broadcast(channel string, message []byte) int {
	h.mu.RLock()
	defer h.mu.RUnlock()

	subscribers, exists := h.channels[channel]
	if !exists || len(subscribers) == 0 {
		return 0
	}

	delivered := 0
	for clientID := range subscribers {
		if client, ok := h.clients[clientID]; ok {
			client.Send(message)
			delivered++
		}
	}

	return delivered
}

func (h *Hub) ClientCount() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.clients)
}

func (h *Hub) ChannelCount() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.channels)
}
