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
	if s.r == nil {
		return
	}
	defer s.r.Close()

	scanner := bufio.NewScanner(s.r)
	scanner.Split(scanScaleFrame)
	scanner.Buffer(make([]byte, 1024), 64*1024)
	for scanner.Scan() {
		select {
		case <-ctx.Done():
			return
		default:
		}

		reading, err := s.parser.Parse(scanner.Text())
		if err != nil {
			continue
		}
		s.publish(reading)
	}
}

func (s *StreamReader) publish(reading *protocol.ScaleReading) {
	s.mu.Lock()
	s.latest = reading
	s.mu.Unlock()

	s.subMu.Lock()
	defer s.subMu.Unlock()
	for ch := range s.subscribers {
		select {
		case ch <- reading:
		default:
		}
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

// ReconnectingReader keeps the local API alive when a device is unplugged or
// its network connection drops. The opener returns a new stream after each retry.
type ReconnectingReader struct {
	open        func() (io.ReadCloser, error)
	parser      protocol.Parser
	mu          sync.RWMutex
	latest      *protocol.ScaleReading
	subMu       sync.Mutex
	subscribers map[chan *protocol.ScaleReading]struct{}
	retryDelay  time.Duration
}

func NewReconnectingReader(open func() (io.ReadCloser, error), parser protocol.Parser) *ReconnectingReader {
	return &ReconnectingReader{
		open:        open,
		parser:      parser,
		subscribers: make(map[chan *protocol.ScaleReading]struct{}),
		retryDelay:  2 * time.Second,
	}
}

func (r *ReconnectingReader) Start(ctx context.Context) {
	for {
		if ctx.Err() != nil {
			return
		}
		stream, err := r.open()
		if err != nil {
			if !waitForRetry(ctx, r.retryDelay) {
				return
			}
			continue
		}

		connectionDone := make(chan struct{})
		go func() {
			select {
			case <-ctx.Done():
				_ = stream.Close()
			case <-connectionDone:
			}
		}()
		scanner := bufio.NewScanner(stream)
		scanner.Split(scanScaleFrame)
		scanner.Buffer(make([]byte, 1024), 64*1024)
		for scanner.Scan() {
			if ctx.Err() != nil {
				_ = stream.Close()
				return
			}
			reading, parseErr := r.parser.Parse(scanner.Text())
			if parseErr != nil {
				continue
			}
			r.publish(reading)
		}
		_ = stream.Close()
		close(connectionDone)
		if !waitForRetry(ctx, r.retryDelay) {
			return
		}
	}
}

func (r *ReconnectingReader) publish(reading *protocol.ScaleReading) {
	r.mu.Lock()
	r.latest = reading
	r.mu.Unlock()

	r.subMu.Lock()
	defer r.subMu.Unlock()
	for ch := range r.subscribers {
		select {
		case ch <- reading:
		default:
		}
	}
}

func (r *ReconnectingReader) GetLatest() *protocol.ScaleReading {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.latest
}

func (r *ReconnectingReader) Subscribe() <-chan *protocol.ScaleReading {
	ch := make(chan *protocol.ScaleReading, 10)
	r.subMu.Lock()
	defer r.subMu.Unlock()
	r.subscribers[ch] = struct{}{}
	return ch
}

func (r *ReconnectingReader) Unsubscribe(ch <-chan *protocol.ScaleReading) {
	r.subMu.Lock()
	defer r.subMu.Unlock()
	for sub := range r.subscribers {
		if sub == ch {
			delete(r.subscribers, sub)
			close(sub)
			break
		}
	}
}

type DisconnectedReader struct{}

func NewDisconnectedReader() *DisconnectedReader                { return &DisconnectedReader{} }
func (d *DisconnectedReader) Start(ctx context.Context)         {}
func (d *DisconnectedReader) GetLatest() *protocol.ScaleReading { return nil }
func (d *DisconnectedReader) Subscribe() <-chan *protocol.ScaleReading {
	ch := make(chan *protocol.ScaleReading)
	close(ch)
	return ch
}
func (d *DisconnectedReader) Unsubscribe(ch <-chan *protocol.ScaleReading) {}

func scanScaleFrame(data []byte, atEOF bool) (advance int, token []byte, err error) {
	for index, value := range data {
		if value == '\r' || value == '\n' {
			if index == 0 {
				return 1, nil, nil
			}
			return index + 1, data[:index], nil
		}
	}
	if atEOF && len(data) > 0 {
		return len(data), data, nil
	}
	return 0, nil, nil
}

func waitForRetry(ctx context.Context, delay time.Duration) bool {
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}

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
