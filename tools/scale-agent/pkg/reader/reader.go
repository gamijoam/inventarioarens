package reader

import (
	"bufio"
	"context"
	"io"
	"sync"
	"time"

	"balanzapro/scale-agent/pkg/protocol"
)

type ScaleReader interface {
	Start(ctx context.Context)
	GetLatest() *protocol.ScaleReading
	Subscribe() <-chan *protocol.ScaleReading
	Unsubscribe(ch <-chan *protocol.ScaleReading)
}

type StreamReader struct {
	r           io.ReadCloser
	parser      protocol.Parser
	mu          sync.RWMutex
	latest      *protocol.ScaleReading
	subMu       sync.Mutex
	subscribers map[chan *protocol.ScaleReading]struct{}
}

func NewStreamReader(r io.ReadCloser, parser protocol.Parser) *StreamReader {
	return &StreamReader{
		r:           r,
		parser:      parser,
		subscribers: make(map[chan *protocol.ScaleReading]struct{}),
	}
}

func (s *StreamReader) Start(ctx context.Context) {
	defer func() {
		if s.r != nil {
			_ = s.r.Close()
		}
	}()

	scanner := bufio.NewScanner(s.r)
	for scanner.Scan() {
		select {
		case <-ctx.Done():
			return
		default:
		}

		line := scanner.Text()
		reading, err := s.parser.Parse(line)
		if err != nil {
			continue
		}

		s.mu.Lock()
		s.latest = reading
		s.mu.Unlock()

		s.broadcast(reading)
	}
}

func (s *StreamReader) GetLatest() *protocol.ScaleReading {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.latest
}

func (s *StreamReader) Subscribe() <-chan *protocol.ScaleReading {
	ch := make(chan *protocol.ScaleReading, 10)
	s.subMu.Lock()
	defer s.subMu.Unlock()
	s.subscribers[ch] = struct{}{}
	return ch
}

func (s *StreamReader) Unsubscribe(ch <-chan *protocol.ScaleReading) {
	s.subMu.Lock()
	defer s.subMu.Unlock()
	for sub := range s.subscribers {
		if sub == ch {
			delete(s.subscribers, sub)
			close(sub)
			break
		}
	}
}

func (s *StreamReader) broadcast(reading *protocol.ScaleReading) {
	s.subMu.Lock()
	defer s.subMu.Unlock()
	for ch := range s.subscribers {
		select {
		case ch <- reading:
		default:
			// Drop if channel full to avoid blocking stream reader
		}
	}
}

// MockReader generates simulated weight readings for tests and demo mode
type MockReader struct {
	weight      float64
	unit        string
	interval    time.Duration
	mu          sync.RWMutex
	latest      *protocol.ScaleReading
	subMu       sync.Mutex
	subscribers map[chan *protocol.ScaleReading]struct{}
}

func NewMockReader(weight float64, unit string, interval time.Duration) *MockReader {
	if interval <= 0 {
		interval = 100 * time.Millisecond
	}
	return &MockReader{
		weight:   weight,
		unit:     unit,
		interval: interval,
		latest: &protocol.ScaleReading{
			Weight:    weight,
			Unit:      unit,
			IsStable:  true,
			Raw:       "MOCK_SCALE",
			Timestamp: time.Now(),
		},
		subscribers: make(map[chan *protocol.ScaleReading]struct{}),
	}
}

func (m *MockReader) Start(ctx context.Context) {
	ticker := time.NewTicker(m.interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			reading := &protocol.ScaleReading{
				Weight:    m.weight,
				Unit:      m.unit,
				IsStable:  true,
				Raw:       "MOCK_SCALE",
				Timestamp: time.Now(),
			}

			m.mu.Lock()
			m.latest = reading
			m.mu.Unlock()

			m.subMu.Lock()
			for ch := range m.subscribers {
				select {
				case ch <- reading:
				default:
				}
			}
			m.subMu.Unlock()
		}
	}
}

func (m *MockReader) GetLatest() *protocol.ScaleReading {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.latest
}

func (m *MockReader) Subscribe() <-chan *protocol.ScaleReading {
	ch := make(chan *protocol.ScaleReading, 10)
	m.subMu.Lock()
	defer m.subMu.Unlock()
	m.subscribers[ch] = struct{}{}
	return ch
}

func (m *MockReader) Unsubscribe(ch <-chan *protocol.ScaleReading) {
	m.subMu.Lock()
	defer m.subMu.Unlock()
	for sub := range m.subscribers {
		if sub == ch {
			delete(m.subscribers, sub)
			close(sub)
			break
		}
	}
}
