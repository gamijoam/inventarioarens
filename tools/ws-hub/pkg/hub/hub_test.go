package hub

import (
	"bytes"
	"testing"
	"time"
)

type mockClient struct {
	id       string
	channels map[string]struct{}
	inbox    chan []byte
}

func newMockClient(id string) *mockClient {
	return &mockClient{
		id:       id,
		channels: make(map[string]struct{}),
		inbox:    make(chan []byte, 10),
	}
}

func (m *mockClient) ID() string {
	return m.id
}

func (m *mockClient) Send(msg []byte) {
	select {
	case m.inbox <- msg:
	default:
	}
}

func TestHub_RegisterAndUnregister(t *testing.T) {
	h := NewHub()

	c1 := newMockClient("client-1")
	h.Register(c1)

	if h.ClientCount() != 1 {
		t.Fatalf("Expected 1 client, got %d", h.ClientCount())
	}

	h.Unregister(c1)

	if h.ClientCount() != 0 {
		t.Fatalf("Expected 0 clients after unregister, got %d", h.ClientCount())
	}
}

func TestHub_SubscribeAndBroadcast(t *testing.T) {
	h := NewHub()

	c1 := newMockClient("c1")
	c2 := newMockClient("c2")
	c3 := newMockClient("c3")

	h.Register(c1)
	h.Register(c2)
	h.Register(c3)

	// c1 and c2 subscribe to tenant:4
	h.Subscribe(c1, "tenant:4")
	h.Subscribe(c2, "tenant:4")

	// c3 subscribes to tenant:1
	h.Subscribe(c3, "tenant:1")

	// Broadcast to tenant:4
	msg := []byte(`{"event":"rate.updated","rate":45.50}`)
	delivered := h.Broadcast("tenant:4", msg)

	if delivered != 2 {
		t.Errorf("Expected delivered to 2 clients, got %d", delivered)
	}

	// Verify c1 and c2 received it
	select {
	case received := <-c1.inbox:
		if !bytes.Equal(received, msg) {
			t.Errorf("c1 received %s, want %s", received, msg)
		}
	case <-time.After(100 * time.Millisecond):
		t.Error("c1 timed out waiting for message")
	}

	select {
	case received := <-c2.inbox:
		if !bytes.Equal(received, msg) {
			t.Errorf("c2 received %s, want %s", received, msg)
		}
	case <-time.After(100 * time.Millisecond):
		t.Error("c2 timed out waiting for message")
	}

	// Verify c3 did NOT receive tenant:4 message
	select {
	case unexpected := <-c3.inbox:
		t.Errorf("c3 should not have received tenant:4 message, got %s", unexpected)
	default:
		// OK
	}
}

func TestHub_Unsubscribe(t *testing.T) {
	h := NewHub()

	c1 := newMockClient("c1")
	h.Register(c1)
	h.Subscribe(c1, "tenant:4")

	h.Unsubscribe(c1, "tenant:4")

	delivered := h.Broadcast("tenant:4", []byte("test"))
	if delivered != 0 {
		t.Errorf("Expected 0 deliveries after unsubscribe, got %d", delivered)
	}
}
